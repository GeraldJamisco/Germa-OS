<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Money;
use Germa\Support\Settings;

/**
 * Execution → Today: the spec's DAILY COMMAND BRIEF, rendered.
 *
 * The spec lists eight things every brief must produce, in order:
 * financial position, today's income target, top 3 priorities, the
 * highest-value action, sales actions, build/technical actions, risks, and one
 * thing Gerald should NOT do. This screen is that list, and nothing else, it
 * is the Operations Director turning the day's position into work.
 *
 * The AI supplies the narrative and the "do not"; everything else is derived,
 * because an action list has to be checkable against the record.
 */
final class TodayService
{
    public function __construct(
        private readonly DashboardService $dashboard = new DashboardService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $data     = $this->dashboard->build();
        $finance  = $data['finance'];
        $target   = $data['income_target'];
        $mode     = $data['meta']['mode'];

        return [
            'meta'        => $data['meta'],
            'brief'       => $data['brief'],
            'brief_stats' => $this->briefStats($finance, $target),
            'priorities'  => $data['priorities'],
            'highest'     => $this->highestValueAction($data),
            'sales'       => $this->salesActions(),
            'build'       => $this->buildActions($mode),
            'risks'       => $data['alerts'],
            'review'      => $this->review(),
            'score'       => $data['score'],
            'suggestions' => [
                "I don't know what to start with today",
                "I'm behind on today's plan",
                "I want to swap one of today's priorities",
                "I'm about to finish for the day",
                'Call the board',
            ],
        ];
    }

    /**
     * Item 1 and 2 of the brief: where the money stands, and what today owes.
     *
     * @param array<string,mixed> $finance
     * @param array<string,mixed> $target
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function briefStats(array $finance, array $target): array
    {
        $runway = $finance['stats'][2]['metric'];

        return [
            [
                'label' => 'Available cash',
                'value' => $finance['cash']->display,
                'tone'  => $finance['status'] === 'CRITICAL' ? 'bad' : 'plain',
            ],
            [
                'label' => 'Income target',
                'value' => $target['metric']->display,
                'tone'  => 'plain',
            ],
            [
                'label' => 'Runway',
                'value' => $runway->display,
                'tone'  => $finance['status'] === 'CRITICAL' ? 'bad' : 'plain',
            ],
            [
                'label' => 'Planned today',
                'value' => $this->plannedHours(),
                'tone'  => 'plain',
            ],
        ];
    }

    /** Estimated hours across everything scheduled for today. */
    private function plannedHours(): string
    {
        $minutes = (int) Database::value(
            "SELECT COALESCE(SUM(estimated_minutes), 0) FROM tasks
             WHERE for_date = :d AND status = 'open'",
            ['d' => Clock::todayString()],
            0
        );

        return $minutes === 0 ? 'Not estimated' : round($minutes / 60, 1) . 'h';
    }

    /**
     * Item 4: the single highest-value action. The first unfinished ranked
     * priority is exactly that by construction, the board ordered them.
     *
     * @param array<string,mixed> $data
     */
    private function highestValueAction(array $data): ?array
    {
        foreach ($data['priorities'] as $task) {
            if (!$task['done']) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Item 5: what the Sales Director wants done today, drawn from the state of
     * the pipeline rather than from a list someone typed.
     *
     * @return array{rows:list<array{text:string,meta:string}>,note:string}
     */
    private function salesActions(): array
    {
        $pipeline = new PipelineService();
        $stale    = $pipeline->staleLeads();
        $target   = Settings::int('daily_prospect_target', 20);

        $log = Database::first(
            'SELECT prospects_contacted FROM day_logs WHERE for_date = :d',
            ['d' => Clock::todayString()]
        );
        $contacted = (int) ($log['prospects_contacted'] ?? 0);
        $remaining = max($target - $contacted, 0);

        $rows = [];

        if ($stale !== []) {
            $rows[] = [
                'text' => sprintf('Follow up %d lead%s that have gone quiet', count($stale), count($stale) === 1 ? '' : 's'),
                'meta' => max(15, count($stale) * 5) . 'm',
            ];
        }

        if ($remaining > 0) {
            $rows[] = [
                'text' => sprintf('Contact %d more prospect%s to hit today\'s target', $remaining, $remaining === 1 ? '' : 's'),
                'meta' => round(($remaining * 9) / 60, 1) . 'h',
            ];
        }

        $opportunities = (new OpportunityService())->ranked(1);
        if ($opportunities !== []) {
            $rows[] = [
                'text' => 'Move on ' . $opportunities[0]['title'] . ' · ' . $opportunities[0]['verdict'],
                'meta' => $opportunities[0]['time_to_cash'] . 'd to cash',
            ];
        }

        if ($rows === []) {
            $rows[] = ['text' => 'Outreach target met: nothing outstanding', 'meta' => 'None'];
        }

        return [
            'rows' => $rows,
            'note' => $contacted . ' of ' . $target . ' contacted so far today',
        ];
    }

    /**
     * Item 6: build work, and, just as importantly, the build work that is
     * deliberately not happening today and why. The spec is explicit that the
     * CTO must never argue for building something because it is interesting.
     *
     * @return array{rows:list<array{text:string,meta:string,deferred:bool}>,note:string}
     */
    private function buildActions(string $mode): array
    {
        $projects = (new ProjectService())->portfolio();
        $rows     = [];
        $deferred = 0;

        foreach ($projects as $project) {
            if ($project['earns_revenue']) {
                $rows[] = [
                    'text'     => $project['name'] . ($project['milestone_label'] === null
                        ? ': continue'
                        : ' · ' . $project['milestone_label']),
                    'meta'     => 'earns',
                    'deferred' => false,
                ];
                continue;
            }

            // Non-revenue work is deferred while cash is the binding constraint.
            if ($mode === ModeService::EMERGENCY) {
                $deferred++;
                $rows[] = [
                    'text'     => $project['name'] . ': paused while runway is critical',
                    'meta'     => $project['hours_14d'] > 0 ? $project['hours_14d'] . 'h in 14d' : '0h',
                    'deferred' => true,
                ];
            } else {
                $rows[] = [
                    'text'     => $project['name'] . ($project['milestone_label'] === null ? '' : ' · ' . $project['milestone_label']),
                    'meta'     => $project['hours_14d'] . 'h in 14d',
                    'deferred' => false,
                ];
            }
        }

        if ($rows === []) {
            $rows[] = ['text' => 'No active projects', 'meta' => 'None', 'deferred' => false];
        }

        return [
            'rows' => $rows,
            'note' => $deferred === 0
                ? 'Nothing deferred'
                : $deferred . ' deferred while cash is the constraint',
        ];
    }

    /**
     * The end-of-day review. Pre-filled with what the day already recorded, so
     * Gerald confirms rather than retypes.
     *
     * @return array<string,mixed>
     */
    private function review(): array
    {
        $today   = Clock::todayString();
        $finance = new FinanceService();

        $log = Database::first('SELECT * FROM day_logs WHERE for_date = :d', ['d' => $today]);

        return [
            'closed'    => $log !== null && $log['closed_at'] !== null,
            'closed_at' => $log['closed_at'] ?? null,
            'fields'    => [
                ['name' => 'earned',    'label' => 'Money earned',        'type' => 'number', 'value' => '', 'placeholder' => $finance->incomeToday()->format()],
                ['name' => 'spent',     'label' => 'Money spent',         'type' => 'number', 'value' => '', 'placeholder' => $finance->expensesBetween($today, $today)->format()],
                ['name' => 'prospects', 'label' => 'Prospects contacted', 'type' => 'number', 'value' => (string) ($log['prospects_contacted'] ?? ''), 'placeholder' => '0'],
                ['name' => 'followups', 'label' => 'Follow-ups made',     'type' => 'number', 'value' => (string) ($log['followups_made'] ?? ''), 'placeholder' => '0'],
                ['name' => 'hours',     'label' => 'Hours worked',        'type' => 'number', 'value' => $log === null || (float) $log['hours_worked'] === 0.0 ? '' : (string) $log['hours_worked'], 'placeholder' => '0'],
                ['name' => 'wins',      'label' => 'Biggest win',         'type' => 'text',   'value' => (string) ($log['wins'] ?? ''), 'placeholder' => 'What actually worked'],
                ['name' => 'problems',  'label' => 'Biggest problem',     'type' => 'text',   'value' => (string) ($log['problems'] ?? ''), 'placeholder' => 'What got in the way'],
            ],
        ];
    }

    /**
     * Closes the day: records what happened, then reports the scores the spec
     * asks an end-of-day review to produce.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function submitReview(array $input): array
    {
        $today   = Clock::todayString();
        $entries = new EntryService();

        $earned = $this->amount($input, 'earned');
        $spent  = $this->amount($input, 'spent');

        // Money is recorded through the ledger, never straight onto the day log
        //: otherwise the runway and the review would disagree.
        if ($earned !== null && $earned->minor > 0) {
            $entries->addIncome(['amount' => (string) $earned->toMajor(), 'description' => 'End-of-day review', 'occurred_on' => $today]);
        }
        if ($spent !== null && $spent->minor > 0) {
            $entries->addExpense(['amount' => (string) $spent->toMajor(), 'description' => 'End-of-day review', 'occurred_on' => $today]);
        }

        Database::run(
            'INSERT INTO day_logs (for_date, prospects_contacted, followups_made, hours_worked, wins, problems, closed_at)
             VALUES (:d, :p, :f, :h, :w, :pr, :closed)
             ON DUPLICATE KEY UPDATE
               prospects_contacted = VALUES(prospects_contacted),
               followups_made = VALUES(followups_made),
               hours_worked = VALUES(hours_worked),
               wins = VALUES(wins),
               problems = VALUES(problems),
               closed_at = VALUES(closed_at)',
            [
                'd'      => $today,
                'p'      => max(0, (int) ($input['prospects'] ?? 0)),
                'f'      => max(0, (int) ($input['followups'] ?? 0)),
                'h'      => max(0, min(24, (float) ($input['hours'] ?? 0))),
                'w'      => $this->text($input, 'wins'),
                'pr'     => $this->text($input, 'problems'),
                'closed' => Clock::now()->format('Y-m-d H:i:s'),
            ]
        );

        // The score is recomputed against what was just recorded, so the review
        // reports the day as it actually finished.
        $mode  = (new ModeService(new FinanceService()))->current();
        $score = $this->dashboard->score->today($mode);

        return [
            'message'     => 'Day closed. ' . $this->verdict($score['total']),
            'score'       => $score,
            'explanation' => $score['explanation'],
        ];
    }

    private function verdict(int $total): string
    {
        return match (true) {
            $total >= 80 => 'Strong day: ' . $total . '/100.',
            $total >= 60 => 'Solid day: ' . $total . '/100.',
            $total >= 40 => 'Mixed day: ' . $total . '/100.',
            default      => 'Weak day: ' . $total . '/100. The breakdown says why.',
        };
    }

    /** @param array<string,mixed> $input */
    private function amount(array $input, string $key): ?Money
    {
        $raw = $input[$key] ?? null;

        if ($raw === null || $raw === '' || !is_numeric(preg_replace('/[^0-9.\-]/', '', (string) $raw))) {
            return null;
        }

        return Money::fromMajor((string) $raw);
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }
}
