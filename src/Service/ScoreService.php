<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Settings;

/**
 * Today's execution score. Every component is arithmetic over recorded data and
 * every deduction produces a sentence, because the dashboard promises a
 * "Why this score?" link and an unexplainable number would be worthless.
 *
 * Weights shift with the operating mode: in emergency mode selling matters more
 * than tidy execution, which is the spec's whole point.
 */
final class ScoreService
{
    private const WEIGHTS = [
        ModeService::NORMAL      => ['financial_discipline' => 25, 'sales' => 25, 'execution' => 25, 'strategic_focus' => 25],
        ModeService::EMERGENCY   => ['financial_discipline' => 20, 'sales' => 40, 'execution' => 25, 'strategic_focus' => 15],
        ModeService::OPPORTUNITY => ['financial_discipline' => 20, 'sales' => 30, 'execution' => 25, 'strategic_focus' => 25],
    ];

    public function __construct(
        private readonly FinanceService $finance,
        private readonly TaskService $tasks,
        private readonly PipelineService $pipeline,
        private readonly ProjectService $projects,
    ) {}

    /**
     * @return array{
     *   total:int, parts:list<array{key:string,label:string,value:int,weight:int}>,
     *   explanation:list<string>, mode:string
     * }
     */
    public function today(string $mode = ModeService::NORMAL): array
    {
        $weights = self::WEIGHTS[$mode] ?? self::WEIGHTS[ModeService::NORMAL];

        $components = [
            'financial_discipline' => $this->financialDiscipline($mode),
            'sales'                => $this->sales(),
            'execution'            => $this->execution(),
            'strategic_focus'      => $this->strategicFocus(),
        ];

        $labels = [
            'financial_discipline' => 'Financial discipline',
            'sales'                => 'Sales',
            'execution'            => 'Execution',
            'strategic_focus'      => 'Strategic focus',
        ];

        $total       = 0.0;
        $parts       = [];
        $explanation = [];

        foreach ($components as $key => [$value, $lines]) {
            $total  += $value * ($weights[$key] / 100);
            $parts[] = [
                'key'    => $key,
                'label'  => $labels[$key],
                'value'  => $value,
                'weight' => $weights[$key],
            ];
            foreach ($lines as $line) {
                $explanation[] = $labels[$key] . ': ' . $line;
            }
        }

        $result = [
            'total'       => (int) round($total),
            'parts'       => $parts,
            'explanation' => $explanation,
            'mode'        => $mode,
        ];

        $this->persist($result);

        return $result;
    }

    /**
     * Spending against what the household can actually afford, plus whether
     * bills are being met. In emergency mode any non-essential spending is a
     * serious deduction, not a nudge.
     *
     * @return array{0:int,1:list<string>}
     */
    private function financialDiscipline(string $mode): array
    {
        $score = 100;
        $lines = [];

        $weekStart     = Clock::daysAgo(6);
        $today         = Clock::todayString();
        $spend         = $this->finance->expensesBetween($weekStart, $today);
        $nonEssential  = $this->finance->nonEssentialSpend($weekStart, $today);
        $dailySurvival = $this->finance->dailySurvivalCost();

        if ($spend->isZero()) {
            $lines[] = 'no spending recorded in the last 7 days log expenses for this to mean anything';
            return [70, $lines];
        }

        $nonEssentialShare = $nonEssential->minor / max($spend->minor, 1);

        if ($nonEssential->minor > 0) {
            $penalty = (int) round($nonEssentialShare * ($mode === ModeService::EMERGENCY ? 60 : 35));
            $score  -= $penalty;
            $lines[] = sprintf(
                '%s of %s spent this week was non-essential (-%d)%s',
                $nonEssential->format(),
                $spend->format(),
                $penalty,
                $mode === ModeService::EMERGENCY ? ', and the OS is in emergency mode' : ''
            );
        } else {
            $lines[] = 'all recorded spending this week was essential';
        }

        // Burning more per day than the household costs to run.
        $dailyBurn = (int) round($spend->minor / 7);
        if ($dailySurvival->minor > 0 && $dailyBurn > $dailySurvival->minor) {
            $score  -= 15;
            $lines[] = 'daily burn is above the survival cost per day (-15)';
        }

        $overdue = $this->finance->overdueBills();
        if ($overdue !== []) {
            $penalty = min(30, count($overdue) * 15);
            $score  -= $penalty;
            $lines[] = count($overdue) . ' bill(s) past due (-' . $penalty . ')';
        }

        return [max(0, min(100, $score)), $lines];
    }

    /**
     * Outreach done today against the daily target, plus movement in the funnel.
     *
     * @return array{0:int,1:list<string>}
     */
    private function sales(): array
    {
        $lines  = [];
        $target = max(Settings::int('daily_prospect_target', 20), 1);

        $log = Database::first(
            'SELECT prospects_contacted, followups_made FROM day_logs WHERE for_date = :d',
            ['d' => Clock::todayString()]
        );

        $contacted = (int) ($log['prospects_contacted'] ?? 0);
        $followups = (int) ($log['followups_made'] ?? 0);
        $advances  = $this->pipeline->stageAdvancesToday();

        $outreach = (int) round(min($contacted / $target, 1.0) * 60);
        $follow   = min($followups * 8, 20);
        $movement = min($advances * 10, 20);

        $lines[] = sprintf('%d of %d prospects contacted (%d/60)', $contacted, $target, $outreach);

        if ($followups > 0) {
            $lines[] = "$followups follow-up(s) made (+$follow/20)";
        } else {
            $lines[] = 'no follow-ups logged today (0/20)';
        }

        if ($advances > 0) {
            $lines[] = "$advances deal(s) advanced a stage (+$movement/20)";
        }

        $stale = count($this->pipeline->staleLeads());
        $score = $outreach + $follow + $movement;

        if ($stale > 0) {
            $penalty = min(15, $stale * 3);
            $score  -= $penalty;
            $lines[] = "$stale lead(s) have gone quiet (-$penalty)";
        }

        return [max(0, min(100, $score)), $lines];
    }

    /**
     * Did the day's plan get done with the ranked three counting double.
     *
     * @return array{0:int,1:list<string>}
     */
    private function execution(): array
    {
        $counts = $this->tasks->todayCounts();
        $lines  = [];

        if ($counts['planned'] === 0) {
            return [50, ['no tasks planned for today an unplanned day scores neutral']];
        }

        $topWeight   = 2;
        $weighted    = ($counts['top_done'] * $topWeight) + ($counts['done'] - $counts['top_done']);
        $weightedMax = ($counts['top_planned'] * $topWeight) + ($counts['planned'] - $counts['top_planned']);

        $score = (int) round(($weighted / max($weightedMax, 1)) * 100);

        $lines[] = sprintf('%d of %d tasks done', $counts['done'], $counts['planned']);
        $lines[] = sprintf('%d of %d ranked priorities done (these count double)', $counts['top_done'], $counts['top_planned']);

        return [max(0, min(100, $score)), $lines];
    }

    /**
     * Time spent on work that earns versus work that does not. If nothing was
     * logged, fall back to whether the number-one priority is finished.
     *
     * @return array{0:int,1:list<string>}
     */
    private function strategicFocus(): array
    {
        $split = $this->projects->todayFocusSplit();
        $lines = [];

        if ($split['total_minutes'] === 0) {
            $top = $this->tasks->topPriorities();
            if ($top === []) {
                return [50, ['no time logged and no priorities set nothing to judge']];
            }

            $first = $top[0];
            $lines[] = $first['done']
                ? 'no time logged, but the number-one priority is done'
                : 'no time logged, and the number-one priority is still open';

            return [$first['done'] ? 80 : 40, $lines];
        }

        $revenueShare = $split['revenue_minutes'] / $split['total_minutes'];
        $score        = (int) round($revenueShare * 100);

        $lines[] = sprintf(
            '%.1fh of %.1fh logged today went to revenue-producing work',
            $split['revenue_minutes'] / 60,
            $split['total_minutes'] / 60
        );

        $sinks = $this->projects->timeSinksWithoutRevenue();
        foreach ($sinks as $sink) {
            if (!$sink['defensible']) {
                $score  = max(0, $score - 10);
                $lines[] = sprintf('%s has taken %.1fh in 14 days with no revenue path (-10)', $sink['name'], $sink['hours']);
            }
        }

        return [max(0, min(100, $score)), $lines];
    }

    /** @param array{total:int,parts:list<array<string,mixed>>,explanation:list<string>} $result */
    private function persist(array $result): void
    {
        $byKey = [];
        foreach ($result['parts'] as $part) {
            $byKey[$part['key']] = $part['value'];
        }

        Database::run(
            'INSERT INTO daily_scores (for_date, total, financial_discipline, sales, execution, strategic_focus, explanation, computed_at)
             VALUES (:d, :total, :fin, :sales, :exec, :focus, :explanation, NOW())
             ON DUPLICATE KEY UPDATE
               total = VALUES(total),
               financial_discipline = VALUES(financial_discipline),
               sales = VALUES(sales),
               execution = VALUES(execution),
               strategic_focus = VALUES(strategic_focus),
               explanation = VALUES(explanation),
               computed_at = NOW()',
            [
                'd'           => Clock::todayString(),
                'total'       => $result['total'],
                'fin'         => $byKey['financial_discipline'] ?? 0,
                'sales'       => $byKey['sales'] ?? 0,
                'exec'        => $byKey['execution'] ?? 0,
                'focus'       => $byKey['strategic_focus'] ?? 0,
                'explanation' => json_encode($result['explanation'], JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}
