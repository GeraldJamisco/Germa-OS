<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Money;
use Germa\Support\Provenance;

/**
 * Goals → Goals.
 *
 * Two things from the spec shape this screen.
 *
 * The mission is a ladder, in order: financial pressure, stability, debt
 * reduction, reliable income, business growth, asset creation, long-term wealth.
 * So the screen works out which rung you are actually standing on from the
 * numbers, and marks business goals that belong to a rung above it. Those are not
 * ambitious, they are premature, and the spec is blunt about the cost: never
 * sacrifice household survival for an unvalidated idea.
 *
 * Household and shared goals are exempt from that sequencing. The same sentence
 * that defers an unvalidated startup idea protects the household, which sits at
 * the top of the decision hierarchy rather than in the queue behind it.
 *
 * The Operations Director is told never to leave Gerald with only abstract
 * advice, and every objective should become Goal → Milestones → Tasks →
 * Deadline → Metric → Review. A goal with no milestone has no next step, so the
 * screen counts those and names them rather than showing a tidy progress bar
 * over nothing.
 */
final class GoalBoardService
{
    /**
     * The mission ladder. Each rung carries the question that has to be answered
     * yes before the next one is worth spending anything on.
     */
    public const STAGES = [
        1 => ['key' => 'pressure',  'label' => 'Financial pressure', 'test' => 'Is this month covered?'],
        2 => ['key' => 'stability', 'label' => 'Stability',          'test' => 'Is next month covered without luck?'],
        3 => ['key' => 'debt',      'label' => 'Debt reduction',     'test' => 'Is the debt shrinking on a schedule?'],
        4 => ['key' => 'income',    'label' => 'Reliable income',    'test' => 'Does money repeat without being re-won?'],
        5 => ['key' => 'growth',    'label' => 'Business growth',    'test' => 'Is revenue growing beyond survival?'],
        6 => ['key' => 'assets',    'label' => 'Asset creation',     'test' => 'Is anything being built that holds value?'],
        7 => ['key' => 'wealth',    'label' => 'Long-term wealth',   'test' => 'Is capital working on its own?'],
    ];

    /** Which rung a goal belongs to, by horizon. */
    private const HORIZON_STAGE = ['30d' => 1, '90d' => 3, '1y' => 4, 'long' => 6];

    public const SCOPES = ['all' => 'All', 'business' => 'Business', 'household' => 'Household', 'shared' => 'Shared'];

    public function __construct(
        private readonly FinanceService $finance = new FinanceService(),
        private readonly DebtService $debt = new DebtService(),
        private readonly RevenueService $revenue = new RevenueService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $scope = 'all'): array
    {
        $scope = array_key_exists($scope, self::SCOPES) ? $scope : 'all';
        $stage = $this->stage();
        $goals = $this->goals($stage['number']);

        $shown = $scope === 'all'
            ? $goals
            : array_values(array_filter($goals, static fn (array $g): bool => $g['scope'] === $scope));

        $premature = array_values(array_filter($goals, static fn (array $g): bool => $g['premature']));
        $abstract  = array_values(array_filter($goals, static fn (array $g): bool => !$g['has_milestone']));

        return [
            'scope'     => $scope,
            'scopes'    => $this->scopeOptions($scope, $goals),
            'stage'     => $stage,
            'ladder'    => $this->ladder($stage['number'], $goals),
            'insight'   => $this->insight($goals, $stage, $premature, $abstract),
            'summary'   => $this->summary($goals, $premature, $abstract),
            'goals'     => $shown,
            'premature' => $premature,
            'abstract'  => $abstract,
            'total'     => count($goals),
            'suggestions' => [
                'Which goal should I drop?',
                'What is the next step on my 90-day goal?',
                'Am I working on the right rung?',
                'Turn this goal into milestones',
                'Call the board',
            ],
        ];
    }

    // ── The ladder ──────────────────────────────────────────────────────────

    /**
     * Which rung the numbers put you on. Read from the bottom up and stop at the
     * first test that fails, because a rung you have not cleared is the one you
     * are standing on regardless of what you are working on.
     *
     * @return array{number:int,key:string,label:string,test:string,reason:string}
     */
    private function stage(): array
    {
        $runway    = $this->finance->runwayDays();
        $survival  = $this->finance->monthlySurvivalCost();
        $income    = $this->finance->incomeThisMonth();
        $debt      = $this->debt->summary();
        $recurring = $this->revenue->recurring();

        // 1. Is this month covered?
        if ($runway !== null && $runway < 30) {
            return $this->atStage(1, sprintf(
                '%d day%s of runway against a %s month. Everything above this rung is on hold until that is not true.',
                $runway,
                $runway === 1 ? '' : 's',
                $survival->compact()
            ));
        }

        // 2. Is next month covered without luck?
        if ($income->minor < $survival->minor) {
            return $this->atStage(2, sprintf(
                'Income this month is %s against a %s requirement, so next month still depends on something landing.',
                $income->compact(),
                $survival->compact()
            ));
        }

        // 3. Is the debt shrinking on a schedule?
        if (!$debt['remaining']->isZero()) {
            return $this->atStage(3, sprintf(
                '%s of debt outstanding. Until it is on a schedule it takes the first claim on any surplus.',
                $debt['remaining']->compact()
            ));
        }

        // 4. Does money repeat without being re-won?
        if ($recurring['amount']->minor < $survival->minor) {
            return $this->atStage(4, sprintf(
                'Only %s of income repeats against a %s requirement, so the month has to be won again each time.',
                $recurring['amount']->compact(),
                $survival->compact()
            ));
        }

        // 5. Is revenue growing beyond survival?
        if ($income->minor < $survival->minor * 2) {
            return $this->atStage(5, 'Recurring income covers the household. Growth beyond survival is now the work.');
        }

        return $this->atStage(6, 'Survival and growth are handled. Building something that holds value is the work now.');
    }

    /** @return array{number:int,key:string,label:string,test:string,reason:string} */
    private function atStage(int $number, string $reason): array
    {
        return self::STAGES[$number] + ['number' => $number, 'reason' => $reason];
    }

    /**
     * The whole ladder, so the current rung is visible in context rather than as
     * a bare label. Rungs above the current one are dimmed, not hidden: the point
     * is that they exist and are waiting.
     *
     * @param list<array<string,mixed>> $goals
     * @return list<array<string,mixed>>
     */
    private function ladder(int $current, array $goals): array
    {
        $rungs = [];

        foreach (self::STAGES as $number => $stage) {
            $onRung = count(array_filter($goals, static fn (array $g): bool => $g['stage'] === $number));

            $rungs[] = [
                'number'  => $number,
                'label'   => $stage['label'],
                'test'    => $stage['test'],
                'goals'   => $onRung,
                'state'   => match (true) {
                    $number < $current  => 'cleared',
                    $number === $current => 'here',
                    default              => 'ahead',
                },
            ];
        }

        return $rungs;
    }

    // ── Goals ───────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function goals(int $currentStage): array
    {
        $rows = Database::all(
            "SELECT g.*,
                    (SELECT COUNT(*) FROM goal_milestones m WHERE m.goal_id = g.id) AS milestones,
                    (SELECT COUNT(*) FROM goal_milestones m WHERE m.goal_id = g.id AND m.done_on IS NOT NULL) AS milestones_done
             FROM goals g
             WHERE g.status = 'active'
             ORDER BY g.sort_order, FIELD(g.horizon, '30d', '90d', '1y', 'long')"
        );

        $goals = [];

        foreach ($rows as $row) {
            $id       = (int) $row['id'];
            $target   = (int) $row['target_value'];
            $current  = (int) $row['current_value'];
            $pct      = $target > 0 ? min(100.0, ($current / $target) * 100) : 0.0;
            $scope    = (string) $row['scope'];
            // The ladder sequences business ambition. It does not get to defer the
            // household: the spec puts essential survival and obligations at the
            // top of the decision hierarchy and says never to sacrifice household
            // survival for an unvalidated idea. So a household or shared goal sits
            // on the rung you are already standing on, never above it, and is never
            // marked premature. Telling Gerald his family obligations are ahead of
            // his position would be the OS getting the hierarchy backwards.
            $isHousehold = $scope !== 'business';
            $stage       = $isHousehold
                ? $currentStage
                : (self::HORIZON_STAGE[(string) $row['horizon']] ?? 1);
            $daysLeft = $row['target_on'] === null ? null : Clock::daysUntil((string) $row['target_on']);
            $next     = $this->nextMilestone($id);

            $goals[] = [
                'id'             => $id,
                'title'          => (string) $row['title'],
                'why'            => $row['why'] ?? null,
                'scope'          => $scope,
                'scope_label'    => ucfirst($scope),
                'is_household'   => $isHousehold,
                'horizon'        => (string) $row['horizon'],
                'horizon_label'  => $this->horizonLabel((string) $row['horizon']),
                'metric'         => (string) $row['metric'],
                'progress_pct'   => $pct,
                'progress_label' => $pct > 0 && $pct < 1 ? '<1%' : round($pct) . '%',
                'detail'         => $this->detail($row, $current, $target),
                'days_left'      => $daysLeft,
                'deadline_label' => $this->deadlineLabel($daysLeft),
                'overdue'        => $daysLeft !== null && $daysLeft < 0,
                'stage'          => $stage,
                'stage_label'    => self::STAGES[$stage]['label'],
                // A goal on a rung above the one you are standing on. Not wrong to
                // want, wrong to be spending this week on.
                'premature'      => !$isHousehold && $stage > $currentStage,
                'milestones'     => (int) $row['milestones'],
                'milestones_done' => (int) $row['milestones_done'],
                'has_milestone'  => (int) $row['milestones'] > 0,
                'next_step'      => $next,
                'review_on'      => $row['review_on'] ?? null,
                'review_due'     => $row['review_on'] !== null && Clock::daysUntil((string) $row['review_on']) <= 0,
                'ask'            => $next === null
                    ? sprintf('Break this goal into milestones with dates: %s', $row['title'])
                    : sprintf('What is the fastest way to hit this milestone: %s', $next['title']),
            ];
        }

        return $goals;
    }

    /**
     * The nearest milestone not yet done. This is the "next actionable step" the
     * spec insists on, so it is read from the record rather than invented.
     *
     * @return array<string,mixed>|null
     */
    private function nextMilestone(int $goalId): ?array
    {
        $row = Database::first(
            'SELECT id, title, metric, target_on FROM goal_milestones
             WHERE goal_id = :id AND done_on IS NULL
             ORDER BY target_on IS NULL, target_on, sort_order LIMIT 1',
            ['id' => $goalId]
        );

        if ($row === null) {
            return null;
        }

        $days = $row['target_on'] === null ? null : Clock::daysUntil((string) $row['target_on']);

        return [
            'id'     => (int) $row['id'],
            'title'  => (string) $row['title'],
            'metric' => $row['metric'] ?? null,
            'when'   => $this->deadlineLabel($days),
            'days'   => $days,
            'late'   => $days !== null && $days < 0,
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $goals
     * @param array<string,mixed>       $stage
     * @param list<array<string,mixed>> $premature
     * @param list<array<string,mixed>> $abstract
     * @return array{text:string,provenance:string}
     */
    private function insight(array $goals, array $stage, array $premature, array $abstract): array
    {
        if ($goals === []) {
            return [
                'text' => sprintf(
                    'No goals recorded. You are on the %s rung: %s Set the goal that clears it before setting any others.',
                    strtolower((string) $stage['label']),
                    $stage['reason']
                ),
                'provenance' => Provenance::Unavailable->value,
            ];
        }

        $parts = [];

        $parts[] = sprintf('You are on the %s rung. %s', strtolower((string) $stage['label']), $stage['reason']);

        if ($premature !== []) {
            $names = array_map(static fn (array $g): string => (string) $g['title'], $premature);
            $parts[] = sprintf(
                '%d goal%s %s further up the ladder than that: %s. Wanting %s is not the problem, spending this week on %s is.',
                count($premature),
                count($premature) === 1 ? '' : 's',
                count($premature) === 1 ? 'sits' : 'sit',
                implode('; ', array_slice($names, 0, 2)),
                count($premature) === 1 ? 'it' : 'them',
                count($premature) === 1 ? 'it' : 'them'
            );
        }

        $household = array_values(array_filter($goals, static fn (array $g): bool => $g['is_household']));

        if ($household !== []) {
            $parts[] = sprintf(
                '%d household goal%s %s not sequenced against the ladder: obligations to the people you live with are not ambitions to be deferred.',
                count($household),
                count($household) === 1 ? '' : 's',
                count($household) === 1 ? 'is' : 'are'
            );
        }

        if ($abstract !== []) {
            $parts[] = sprintf(
                '%d %s no milestone, which means %s no next step. A goal without one is a wish with a progress bar.',
                count($abstract),
                count($abstract) === 1 ? 'has' : 'have',
                count($abstract) === 1 ? 'it has' : 'they have'
            );
        } else {
            $parts[] = 'Every goal has a next milestone, so every one of them has a next step.';
        }

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    /**
     * @param list<array<string,mixed>> $goals
     * @param list<array<string,mixed>> $premature
     * @param list<array<string,mixed>> $abstract
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $goals, array $premature, array $abstract): array
    {
        $onRung  = count($goals) - count($premature);
        $overdue = count(array_filter($goals, static fn (array $g): bool => $g['overdue']));

        return [
            [
                'label' => 'Active goals',
                'value' => $goals === [] ? 'None' : (string) count($goals),
                'tone'  => 'neutral',
            ],
            [
                'label' => 'On the current rung',
                'value' => (string) $onRung . ' of ' . count($goals),
                'tone'  => $onRung === 0 ? 'bad' : 'good',
            ],
            [
                'label' => 'Without a milestone',
                'value' => $abstract === [] ? 'None' : (string) count($abstract),
                'tone'  => $abstract === [] ? 'good' : 'warn',
            ],
            [
                'label' => 'Past their date',
                'value' => $overdue === 0 ? 'None' : (string) $overdue,
                'tone'  => $overdue === 0 ? 'good' : 'bad',
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $goals
     * @return list<array{key:string,label:string,count:?int,active:bool}>
     */
    private function scopeOptions(string $active, array $goals): array
    {
        $options = [];

        foreach (self::SCOPES as $key => $label) {
            $count = $key === 'all'
                ? count($goals)
                : count(array_filter($goals, static fn (array $g): bool => $g['scope'] === $key));

            $options[] = ['key' => $key, 'label' => $label, 'count' => $count, 'active' => $key === $active];
        }

        return $options;
    }

    // ── Formatting ──────────────────────────────────────────────────────────

    private function horizonLabel(string $horizon): string
    {
        return match ($horizon) {
            '30d'  => '30 days',
            '90d'  => '90 days',
            '1y'   => '1 year',
            'long' => 'Long term',
            default => $horizon,
        };
    }

    private function deadlineLabel(?int $days): string
    {
        return match (true) {
            $days === null => 'No date set',
            $days < 0      => sprintf('%d day%s overdue', abs($days), abs($days) === 1 ? '' : 's'),
            $days === 0    => 'Due today',
            $days === 1    => 'Due tomorrow',
            $days <= 30    => sprintf('%d days left', $days),
            default        => sprintf('%d weeks left', (int) round($days / 7)),
        };
    }

    /** @param array<string,mixed> $row */
    private function detail(array $row, int $current, int $target): ?string
    {
        if ($target <= 0) {
            return null;
        }

        if ((string) $row['metric'] === 'money') {
            $currency = (string) ($row['currency'] ?? Money::base());

            return Money::of($current, $currency)->compact() . ' of ' . Money::of($target, $currency)->compact();
        }

        if ((string) $row['metric'] === 'percent') {
            return $current . '% of ' . $target . '%';
        }

        return $current . ' of ' . $target;
    }
}
