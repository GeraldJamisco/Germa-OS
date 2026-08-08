<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Ai\Context;
use Germa\Support\Clock;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Provenance;
use Germa\Support\Settings;

/**
 * Assembles one dashboard payload. The view does no querying and no arithmetic
 * it renders what this returns, including the provenance chip for each value.
 */
final class DashboardService
{
    public readonly FinanceService $finance;
    public readonly DebtService $debt;
    public readonly IncomeTargetService $incomeTarget;
    public readonly PipelineService $pipeline;
    public readonly ProjectService $projects;
    public readonly GoalService $goals;
    public readonly TaskService $tasks;
    public readonly OpportunityService $opportunities;
    public readonly ModeService $mode;
    public readonly AlertService $alerts;
    public readonly ScoreService $score;
    public readonly BoardService $board;

    public function __construct()
    {
        $this->finance       = new FinanceService();
        $this->debt          = new DebtService();
        $this->incomeTarget  = new IncomeTargetService($this->finance, $this->debt);
        $this->pipeline      = new PipelineService();
        $this->projects      = new ProjectService();
        $this->goals         = new GoalService();
        $this->tasks         = new TaskService();
        $this->opportunities = new OpportunityService();
        $this->mode          = new ModeService($this->finance);
        $this->alerts        = new AlertService($this->finance, $this->debt, $this->pipeline, $this->projects);
        $this->score         = new ScoreService($this->finance, $this->tasks, $this->pipeline, $this->projects);
        $this->board         = new BoardService();
    }

    /**
     * Everything the dashboard view needs.
     *
     * @param bool $regenerateAi Force fresh AI output instead of today's stored copy.
     * @return array<string,mixed>
     */
    public function build(bool $regenerateAi = false): array
    {
        // Order matters: goals read the ledger, mode reads the runway, alerts
        // read all of it, and the score reads the mode.
        $this->goals->refreshProgress($this->finance, $this->debt);
        $mode = $this->mode->evaluate();

        $openAlerts = $this->alerts->evaluate();

        // The day is planned before it is scored: the execution score measures
        // progress against today's priorities, so those have to exist first.
        // Planning does not depend on the score, hence the placeholder here.
        if ($regenerateAi) {
            $this->board->clearProposedPriorities(Clock::todayString());
        }
        $this->board->ensurePriorities(
            $this->context($mode, $openAlerts, ['total' => 0, 'parts' => []]),
            $regenerateAi
        );

        $score   = $this->score->today($mode);
        $context = $this->context($mode, $openAlerts, $score);

        // Model calls only happen on an explicit regenerate. A page render uses
        // whatever is stored for today, or instant rules output the client
        // then upgrades it in the background.
        $dailyBrief = $this->board->dailyBrief($context, $regenerateAi, $regenerateAi);
        $boardBrief = $this->board->boardBrief($context, $regenerateAi, $regenerateAi);

        $target = $this->incomeTarget->today();
        $debt   = $this->debt->summary();

        return [
            'meta' => [
                'greeting'     => Clock::greeting(),
                'operator'     => Settings::get('operator_name', 'Gerald'),
                'location'     => Settings::get('operator_location', ''),
                'stamp'        => Clock::headerStamp(),
                'mode'         => $mode,
                'mode_label'   => $this->mode->label($mode),
                'mode_reason'  => $this->mode->currentPeriod()['reason'],
                'ai_live'      => $this->board->isLive(),
                // True when a model is configured but today's text is still the
                // rules stand-in the signal for the client to upgrade it.
                'ai_pending'   => $this->board->isLive() && $dailyBrief['generator'] !== 'claude',
                'demo_data'    => Settings::bool('demo_data'),
            ],

            'brief' => $dailyBrief,
            'board' => $boardBrief,

            'score' => [
                'total'       => $score['total'],
                'parts'       => $score['parts'],
                'explanation' => $score['explanation'],
            ],

            'finance' => [
                'status'      => $this->finance->status(),
                'cash'        => $this->finance->cashMetric(),
                'cash_usd'    => $this->finance->cashInUsd(),
                'stats'       => [
                    [
                        'label'  => 'Monthly survival target',
                        'metric' => $this->finance->monthlySurvivalCost()->isZero()
                            ? Metric::unavailable('Not set')
                            : Metric::moneyCompact($this->finance->monthlySurvivalCost(), Provenance::Entered),
                    ],
                    ['label' => 'Current debt',     'metric' => $this->debt->totalMetric()],
                    ['label' => 'Financial runway', 'metric' => $this->finance->runwayMetric()],
                ],
            ],

            'income_target' => [
                'metric'       => $this->incomeTarget->targetMetric(),
                'target'       => $target['target'],
                'earned'       => $target['earned'],
                'progress_pct' => $target['progress_pct'],
                'monthly'      => $this->finance->monthlySurvivalCost(),
                'basis'        => $target['basis'],
                'ladder'       => $mode === ModeService::EMERGENCY ? $this->incomeTarget->emergencyLadder() : null,
            ],

            'debt' => [
                'summary'      => $debt,
                'total_metric' => $this->debt->totalMetric(),
                'next_metric'  => $this->debt->nextPaymentMetric(),
                'payoff_label' => $debt['target_payoff_on'] === null
                    ? null
                    : (new \DateTimeImmutable($debt['target_payoff_on'], Clock::zone()))->format('j M Y'),
            ],

            'priorities' => $this->tasks->topPriorities(),
            'alerts'     => $openAlerts,

            'pipeline' => [
                'funnel'   => $this->pipeline->funnel(),
                'open'     => $this->pipeline->openValueMetric(),
                'expected' => $this->pipeline->expectedValueMetric(),
                'has_data' => $this->pipeline->hasAnyDeals(),
            ],

            'projects' => [
                'rows'            => $this->projects->portfolio(),
                'revenue_count'   => $this->projects->revenueProducingCount(),
                'active_count'    => $this->projects->activeCount(),
            ],

            'goals'         => $this->goals->active(),
            'opportunities' => $this->opportunities->ranked(),
            'suggestions'   => $this->suggestions($mode),
        ];
    }

    /**
     * The factual snapshot handed to the AI. Money is pre-formatted so the model
     * never has to guess a currency or reconstruct a figure.
     *
     * @param list<array<string,mixed>> $alerts
     * @param array<string,mixed>       $score
     */
    public function context(?string $mode = null, ?array $alerts = null, ?array $score = null): Context
    {
        $mode   = $mode ?? $this->mode->current();
        $alerts = $alerts ?? $this->alerts->open();
        $score  = $score ?? $this->score->today($mode);

        $target = $this->incomeTarget->today();
        $debt   = $this->debt->summary();
        $runway = $this->finance->runwayDays();

        $finance = [
            'available_cash'    => $this->finance->availableCash()->format(),
            'monthly_survival'  => $this->finance->monthlySurvivalCost()->format(),
            'runway_days'       => $runway,
            'status'            => $this->finance->status(),
            'income_this_month' => $this->finance->incomeThisMonth()->format(),
            'spend_this_month'  => $this->finance->expensesThisMonth()->format(),
            'today_target'      => $target['target']->format(),
            'earned_today'      => $target['earned']->format(),
            'working_days_left' => $target['working_days_left'],
            'debt_remaining'    => $debt['remaining']->format(),
            'debt_next_payment' => $debt['next_payment'] === null
                ? null
                : $debt['next_payment']['amount']->format() . ' due in ' . $debt['next_payment']['days'] . ' days',
        ];

        $pipelineCounts = [];
        foreach ($this->pipeline->funnel() as $stage) {
            $pipelineCounts[$stage['stage']] = $stage['count'];
        }

        $pipeline = [
            'stages'                => $pipelineCounts,
            'open_value'            => $this->pipeline->openValue()->format(),
            'expected_value'        => $this->pipeline->expectedValue()->format(),
            'prospects_added_7d'    => $this->pipeline->prospectsAddedSince(Clock::daysAgo(7)),
            'stale_leads'           => count($this->pipeline->staleLeads()),
            'daily_prospect_target' => Settings::int('daily_prospect_target', 20),
        ];

        // The description travels with the metrics, because a continue/pause/
        // pivot/kill judgement needs to know what the project is, not only how it
        // is doing. It is trimmed rather than sent whole: a couple of sentences
        // is enough to reason from, and it keeps every board call from carrying
        // an essay per project. A project with none is sent as null on purpose,
        // so the board can say it cannot judge what it has not been told.
        $describe = static function (mixed $text): ?string {
            $text = trim((string) ($text ?? ''));
            if ($text === '') {
                return null;
            }
            return mb_strlen($text) <= 500 ? $text : mb_substr($text, 0, 499) . '…';
        };

        $projects = array_map(static fn(array $p): array => [
            'name'          => $p['name'],
            'status'        => $p['status_label'],
            'priority'      => $p['priority'],
            'progress_pct'  => $p['progress_pct'],
            'revenue'       => $p['revenue']->format(),
            'earns_revenue' => $p['earns_revenue'],
            'hours_14d'     => $p['hours_14d'],
            'next_milestone' => $p['milestone_label'],
            // What it is, what it solves, who it is for.
            'description'   => $describe($p['description'] ?? null),
            'problem'       => $describe($p['problem'] ?? null),
            'customer'      => $p['customer'] ?? null,
        ], $this->projects->portfolio());

        $priorities = array_map(static fn(array $t): array => [
            'title'    => $t['title'],
            'done'     => $t['done'],
            'estimate' => $t['estimate_label'],
            'outcome'  => $t['expected_outcome'],
        ], $this->tasks->topPriorities());

        $goals = array_map(static fn(array $g): array => [
            'horizon'      => $g['horizon_label'],
            'title'        => $g['title'],
            'progress_pct' => round($g['progress_pct'], 1),
        ], $this->goals->active());

        $opportunities = array_map(static fn(array $o): array => [
            'title'        => $o['title'],
            'value'        => $o['value']->format(),
            'probability'  => $o['probability'],
            'time_to_cash' => $o['time_to_cash'] . ' days',
            'verdict'      => $o['verdict'],
        ], $this->opportunities->ranked(4));

        $alertSummary = array_map(static fn(array $a): array => [
            'severity' => $a['severity'],
            'title'    => $a['title'],
            'body'     => $a['body'],
        ], $alerts);

        return new Context(
            Clock::todayString(),
            $mode,
            $finance,
            $pipeline,
            $projects,
            $priorities,
            $alertSummary,
            $goals,
            $opportunities,
            ['total' => $score['total'], 'parts' => $score['parts']],
        );
    }

    /** @return list<string> */
    private function suggestions(string $mode): array
    {
        if ($mode === ModeService::EMERGENCY) {
            return [
                "I need money in the next 24 hours",
                "I'm not sure we can afford this",
                "I don't know which lead to chase first",
                "I'm spread too thin today",
                'Call the board',
            ];
        }

        return [
            "I'm thinking about spending on something",
            "I want to make " . Money::of(100_000)->compact() . ' this week',
            "I'm not sure where I stand financially",
            "I'm tempted to spend today on FlowPesa",
            'Call the board',
        ];
    }
}
