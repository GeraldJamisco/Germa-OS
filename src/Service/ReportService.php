<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Hours;
use Germa\Support\Money;
use Germa\Support\Period;
use Germa\Support\Provenance;

/**
 * System → Reports.
 *
 * The spec defines four reporting cycles. Two already have homes: the Daily
 * Command Brief on Today, and the end-of-day review beside it. The other two live
 * here, because nothing else in the OS produces them:
 *
 *  - the WEEKLY BOARD MEETING, nine role reports and next week's three priorities
 *  - the MONTHLY REVIEW, thirteen measures and seven verdicts
 *
 * Then there are the reports the spec does not ask for, which are the ones worth
 * arguing about. Three are built here.
 *
 * **Can you trust these numbers** comes first, above everything else on the page.
 * Every figure in this system is derived from what Gerald logged. If he stops
 * logging for ten days, every screen keeps rendering with total confidence and a
 * runway that is quietly fiction. Nothing else in the OS says so. A report that
 * grades its own inputs is the only thing standing between a provenance chip and
 * a number that merely looks precise.
 *
 * **Said versus did** compares what was committed to against what was delivered.
 * Both halves already exist as records; nothing puts them side by side, which is
 * exactly why the gap is comfortable to ignore.
 *
 * **The cost of waiting** prices inaction. Every other screen shows what is
 * happening. This shows what not deciding has already cost, because the expensive
 * decisions are usually the ones nobody made.
 */
final class ReportService
{
    /** Past this many days without an entry, a domain's figures are not current. */
    private const STALE_DAYS = 4;

    /** Past this, they should not be used for a decision at all. */
    private const DEAD_DAYS = 14;

    public function __construct(
        private readonly FinanceService $finance = new FinanceService(),
        private readonly DebtService $debt = new DebtService(),
        private readonly PipelineService $pipeline = new PipelineService(),
        private readonly ProjectService $projects = new ProjectService(),
        private readonly RevenueService $revenue = new RevenueService(),
        private readonly SavingsService $savings = new SavingsService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'week'): array
    {
        // Only two cycles produce a report, so a year selection would render a
        // board meeting that never happened.
        $period = $period === 'month' ? 'month' : 'week';
        [$from, $to] = $this->window($period);

        $trust = $this->trust();

        return [
            'period'      => $period,
            'periods'     => $this->periodOptions($period),
            'range_label' => $this->rangeLabel($period, $from, $to),
            'from'        => $from,
            'to'          => $to,
            'trust'       => $trust,
            'insight'     => $this->insight($period, $from, $to, $trust),
            'board'       => $period === 'week' ? $this->weeklyBoard($from, $to) : null,
            'priorities'  => $period === 'week' ? $this->nextThreePriorities($from, $to) : [],
            'review'      => $period === 'month' ? $this->monthlyReview($from, $to) : null,
            'said_did'    => $this->saidVersusDid($from, $to),
            'waiting'     => $this->costOfWaiting(),
            'self_audit'  => $this->selfAudit($from, $to),
            'suggestions' => [
                'Read me this week in three sentences',
                'What should I stop doing next month?',
                'Which of these numbers should I not trust?',
                'What did waiting cost me?',
                'Call the board',
            ],
        ];
    }

    // ── Can you trust these numbers ─────────────────────────────────────────

    /**
     * The report nobody asks for. Each domain is graded on when it was last fed,
     * because a confident figure over stale input is the most expensive kind of
     * wrong: it does not look like missing data, it looks like an answer.
     *
     * @return array<string,mixed>
     */
    private function trust(): array
    {
        $domains = [
            [
                'label'  => 'Cash balance',
                'last'   => Database::value('SELECT MAX(observed_on) FROM cash_snapshots'),
                'blocks' => 'Runway, today\'s target, every mode decision',
            ],
            [
                'label'  => 'Money in and out',
                'last'   => Database::value('SELECT MAX(occurred_on) FROM ledger_entries'),
                'blocks' => 'Income, expenses, revenue attribution',
            ],
            [
                'label'  => 'Pipeline movement',
                'last'   => Database::value('SELECT MAX(DATE(changed_at)) FROM deal_stage_events'),
                'blocks' => 'Conversion, expected value, time-to-cash',
            ],
            [
                'label'  => 'Hours logged',
                'last'   => Database::value('SELECT MAX(logged_on) FROM project_time_logs'),
                'blocks' => 'Allocation, at-risk projects, workload and rest',
            ],
            [
                'label'  => 'Day closed out',
                'last'   => Database::value('SELECT MAX(for_date) FROM day_logs WHERE closed_at IS NOT NULL'),
                'blocks' => 'Scores, execution trend, tomorrow\'s brief',
            ],
            [
                'label'  => 'Debt payments',
                'last'   => Database::value('SELECT MAX(paid_on) FROM debt_payments'),
                'blocks' => 'Payoff date, repayment pace',
            ],
        ];

        $rows  = [];
        $worst = 0;

        foreach ($domains as $domain) {
            $last = $domain['last'] === null || $domain['last'] === '' ? null : (string) $domain['last'];
            $days = $last === null ? null : abs(Clock::daysUntil($last));

            $state = match (true) {
                $days === null           => 'never',
                $days <= self::STALE_DAYS => 'current',
                $days <= self::DEAD_DAYS  => 'stale',
                default                   => 'dead',
            };

            $worst = max($worst, match ($state) {
                'current' => 0,
                'stale'   => 1,
                default   => 2,
            });

            $rows[] = [
                'label'  => $domain['label'],
                'blocks' => $domain['blocks'],
                'last'   => $last,
                'days'   => $days,
                'state'  => $state,
                'label_when' => match (true) {
                    $days === null => 'Never recorded',
                    $days === 0    => 'Today',
                    $days === 1    => 'Yesterday',
                    default        => sprintf('%d days ago', $days),
                },
                'tone'   => match ($state) {
                    'current' => 'good',
                    'stale'   => 'warn',
                    default   => 'high',
                },
                'verdict' => match ($state) {
                    'current' => 'Current',
                    'stale'   => 'Behind',
                    'never'   => 'No data',
                    default   => 'Do not rely on it',
                },
            ];
        }

        $stale = count(array_filter($rows, static fn (array $r): bool => $r['state'] !== 'current'));

        return [
            'rows'    => $rows,
            'stale'   => $stale,
            'level'   => $worst,
            'headline' => match ($worst) {
                0 => 'Every input is current, so the figures on the other screens are worth acting on.',
                1 => sprintf(
                    '%d of %d inputs are behind. The figures still render with full confidence, which is exactly the problem: read them as approximate until they are fed.',
                    $stale,
                    count($rows)
                ),
                default => sprintf(
                    '%d of %d inputs are stale or missing. Some figures on the other screens are presented precisely and are not currently true. Fix the inputs before making a decision on them.',
                    $stale,
                    count($rows)
                ),
            },
            'tone'    => match ($worst) {
                0       => 'good',
                1       => 'warn',
                default => 'bad',
            },
            'stale_after' => self::STALE_DAYS,
        ];
    }

    // ── Weekly board meeting ────────────────────────────────────────────────

    /**
     * The nine role reports the spec names, each derived rather than narrated. A
     * role with nothing to say says so: an empty report is information.
     *
     * @return list<array<string,mixed>>
     */
    private function weeklyBoard(string $from, string $to): array
    {
        $income   = $this->finance->incomeBetween($from, $to);
        $spend    = $this->finance->expensesBetween($from, $to);
        $survival = $this->finance->monthlySurvivalCost();
        $runway   = $this->finance->runwayDays();

        $won        = $this->pipeline->wonBetween($from, $to);
        $added      = $this->pipeline->prospectsAddedSince($from);
        $stale      = count($this->pipeline->staleLeads());
        $conversion = $this->pipeline->conversion();

        $portfolio = $this->projects->portfolio('week');
        $hours     = $this->projects->hoursInWindow('week');
        $earning   = array_values(array_filter($portfolio, static fn (array $p): bool => $p['earns_revenue']));
        $atRisk    = array_values(array_filter($portfolio, static fn (array $p): bool => $p['at_risk']));

        $milestonesHit = (int) Database::value(
            'SELECT COUNT(*) FROM goal_milestones WHERE done_on BETWEEN :f AND :t',
            ['f' => $from, 't' => $to],
            0
        );

        $tasks = Database::first(
            "SELECT
               COALESCE(SUM(status = 'done'), 0) AS done,
               COALESCE(SUM(status = 'open'), 0) AS open
             FROM tasks WHERE for_date BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        );

        $reversed = (int) Database::value(
            "SELECT COUNT(*) FROM decisions WHERE status = 'reversed' AND decided_on BETWEEN :f AND :t",
            ['f' => $from, 't' => $to],
            0
        );

        $critical = (int) Database::value(
            "SELECT COUNT(*) FROM alerts WHERE status = 'open' AND severity = 'CRITICAL'",
            [],
            0
        );

        $staleResearch = (int) Database::value(
            'SELECT COUNT(*) FROM research_notes
             WHERE archived = 0 AND DATE_ADD(checked_on, INTERVAL shelf_life_days DAY) < :today',
            ['today' => Clock::todayString()],
            0
        );

        $opportunities = (int) Database::value(
            "SELECT COUNT(*) FROM opportunities WHERE status = 'open'",
            [],
            0
        );

        $recurring = $this->revenue->recurring();

        return [
            $this->report(
                'CFO',
                'Financial performance',
                sprintf('%s in, %s out', $income->compact(), $spend->compact()),
                $income->minor >= $spend->minor
                    ? sprintf(
                        'The week was cash positive by %s. Runway stands at %s.',
                        Money::of($income->minor - $spend->minor, $income->currency)->compact(),
                        $runway === null ? 'an unknown number of days' : $runway . ' days'
                    )
                    : sprintf(
                        'The week cost %s more than it earned, against a %s monthly requirement. %s',
                        Money::of($spend->minor - $income->minor, $spend->currency)->compact(),
                        $survival->compact(),
                        $runway !== null && $runway < 30
                            ? 'With ' . $runway . ' days of runway, this cannot repeat.'
                            : 'Sustainable for now, not indefinitely.'
                    ),
                $income->minor >= $spend->minor ? 'good' : 'warn'
            ),
            $this->report(
                'Sales',
                'Pipeline and revenue',
                $won->isZero() ? 'Nothing closed' : $won->compact() . ' closed',
                sprintf(
                    '%d prospect%s added, %d live deal%s past the follow-up window. Conversion sits at %.1f%% across %d deals.%s',
                    $added,
                    $added === 1 ? '' : 's',
                    $stale,
                    $stale === 1 ? '' : 's',
                    $conversion['rate'],
                    $conversion['total'],
                    // Won is not received. On the same page as the CFO's cash figure
                    // this has to be said, or the two reports look like they disagree.
                    $won->isZero()
                        ? ''
                        : ' The figure above is deal value marked won, not money received: the CFO report counts the cash.'
                ),
                // Closing money does not make a week with a cooling pipeline good.
                match (true) {
                    $won->isZero() && $added === 0 => 'high',
                    $stale > 0                     => 'warn',
                    $won->isZero()                 => 'warn',
                    default                        => 'good',
                }
            ),
            $this->report(
                'CTO',
                'Technical progress',
                Hours::format($hours) . ' logged',
                $portfolio === []
                    ? 'No projects tracked, so there is nothing to report on build progress.'
                    : sprintf(
                        '%d project%s in flight, %d earning. %s',
                        count($portfolio),
                        count($portfolio) === 1 ? '' : 's',
                        count($earning),
                        $atRisk === []
                            ? 'None are consuming hours without a return.'
                            : $atRisk[0]['name'] . ' is consuming hours with nothing back yet.'
                    ),
                $atRisk === [] ? 'good' : 'warn'
            ),
            $this->report(
                'Product',
                'What was built and whether it mattered',
                $milestonesHit === 0 ? 'No milestone hit' : $milestonesHit . ' milestone' . ($milestonesHit === 1 ? '' : 's') . ' hit',
                $milestonesHit === 0
                    ? 'Hours went in and nothing shipped that anyone had committed to a date. That is not automatically wrong, but it is worth noticing twice in a row.'
                    : sprintf(
                        'Work landed against dates that were set in advance, which is the only version of progress that can be checked.%s',
                        $earning === [] ? ' None of it has produced revenue yet.' : ''
                    ),
                $milestonesHit === 0 ? 'warn' : 'good'
            ),
            $this->report(
                'Critic',
                'Mistakes and questionable decisions',
                $reversed === 0 ? 'Nothing reversed' : $reversed . ' reversed',
                $this->criticLine($atRisk, $stale, $added, $reversed),
                $atRisk !== [] || ($stale > 0 && $added === 0) ? 'warn' : 'quiet'
            ),
            $this->report(
                'Risk',
                'Emerging threats',
                $critical === 0 ? 'Nothing critical' : $critical . ' critical open',
                $this->riskLine($critical, $runway, $survival),
                $critical > 0 || ($runway !== null && $runway < 14) ? 'warn' : 'good'
            ),
            $this->report(
                'Research',
                'Important opportunities',
                $opportunities === 0 ? 'None tracked' : $opportunities . ' open',
                $staleResearch > 0
                    ? sprintf(
                        '%d recorded finding%s past %s shelf life, so any opportunity judged against %s is being judged on old information.',
                        $staleResearch,
                        $staleResearch === 1 ? ' is' : 's are',
                        $staleResearch === 1 ? 'its' : 'their',
                        $staleResearch === 1 ? 'it' : 'them'
                    )
                    : ($opportunities === 0
                        ? 'Nothing recorded as an opportunity. Either none appeared, or they appeared and were not written down.'
                        : 'Everything recorded is inside its shelf life, so the ranking is built on current information.'),
                $staleResearch > 0 ? 'warn' : 'quiet'
            ),
            $this->report(
                'Operations',
                'Execution performance',
                sprintf('%d of %d tasks done', (int) ($tasks['done'] ?? 0), (int) ($tasks['done'] ?? 0) + (int) ($tasks['open'] ?? 0)),
                (int) ($tasks['open'] ?? 0) === 0 && (int) ($tasks['done'] ?? 0) === 0
                    ? 'No tasks were recorded for this week, so execution cannot be measured. That is a gap in the record rather than a quiet week.'
                    : sprintf(
                        '%d still open at the end of the week. %s',
                        (int) ($tasks['open'] ?? 0),
                        (int) ($tasks['open'] ?? 0) > (int) ($tasks['done'] ?? 0)
                            ? 'More was carried than cleared, which is how a list stops being a plan.'
                            : 'More was cleared than carried.'
                    ),
                (int) ($tasks['open'] ?? 0) > (int) ($tasks['done'] ?? 0) ? 'warn' : 'good'
            ),
            $this->report(
                'CEO',
                'Overall strategic assessment',
                $this->ceoHeadline($income, $survival, $recurring['amount'], $runway),
                $this->ceoLine($income, $survival, $recurring['amount'], $earning, $atRisk),
                $runway !== null && $runway < 30 ? 'warn' : 'good'
            ),
        ];
    }

    /**
     * @param list<array<string,mixed>> $atRisk
     */
    private function criticLine(array $atRisk, int $stale, int $added, int $reversed): string
    {
        if ($atRisk !== []) {
            return sprintf(
                '%sh went into %s this fortnight with no revenue and no near milestone. Building is measurable and immediate; selling is neither, which is why it is the one that gets skipped.',
                rtrim(rtrim(number_format((float) $atRisk[0]['hours_14d'], 1), '0'), '.'),
                $atRisk[0]['name']
            );
        }

        if ($stale > 0 && $added === 0) {
            return sprintf(
                '%d conversations were left to cool and no new ones were started. Neither half of that is busy work, and both are avoidance.',
                $stale
            );
        }

        if ($reversed > 0) {
            return sprintf(
                '%d decision%s reversed this week. That is the system working rather than failing, but it is worth asking what the original call was missing.',
                $reversed,
                $reversed === 1 ? ' was' : 's were'
            );
        }

        return 'Nothing obvious to object to in the record this week. That is not the same as nothing to object to.';
    }

    private function riskLine(int $critical, ?int $runway, Money $survival): string
    {
        $parts = [];

        if ($runway !== null && $runway < 14) {
            $parts[] = sprintf(
                'Runway is %d days against a %s month, so a single missed payment is a household problem rather than a business one.',
                $runway,
                $survival->compact()
            );
        }

        $overdue = $this->finance->overdueBills();

        if ($overdue !== []) {
            $parts[] = sprintf('%d bill%s already overdue.', count($overdue), count($overdue) === 1 ? ' is' : 's are');
        }

        if ($parts === []) {
            $parts[] = $critical === 0
                ? 'No critical alert open and no bill overdue. The exposure is ordinary.'
                : 'Critical alerts are open. They are on the Insights screen with what raised them.';
        }

        return implode(' ', $parts);
    }

    private function ceoHeadline(Money $income, Money $survival, Money $recurring, ?int $runway): string
    {
        if ($runway !== null && $runway < 30) {
            return 'Survival is the strategy';
        }
        if ($recurring->minor < $survival->minor) {
            return 'Income does not yet repeat';
        }
        if ($income->minor < $survival->minor * 2) {
            return 'Covered, not yet growing';
        }

        return 'Growing beyond survival';
    }

    /**
     * @param list<array<string,mixed>> $earning
     * @param list<array<string,mixed>> $atRisk
     */
    private function ceoLine(Money $income, Money $survival, Money $recurring, array $earning, array $atRisk): string
    {
        $parts = [];

        $parts[] = $recurring->isZero()
            ? 'Nothing repeats without being won again, so every month starts from zero.'
            : sprintf('%s repeats monthly against a %s requirement.', $recurring->compact(), $survival->compact());

        if (count($earning) <= 1 && $earning !== []) {
            $parts[] = sprintf(
                'One project produces the money: %s. That is a single point of failure, not a focus.',
                $earning[0]['name']
            );
        } elseif ($earning === []) {
            $parts[] = 'No project has produced money, which makes every one of them a bet rather than a business.';
        }

        if ($atRisk !== []) {
            $parts[] = sprintf('The decision this week is what happens to %s.', $atRisk[0]['name']);
        }

        return implode(' ', $parts);
    }

    /** @return array<string,mixed> */
    private function report(string $role, string $remit, string $headline, string $body, string $tone): array
    {
        return [
            'role'     => $role,
            'remit'    => $remit,
            'headline' => $headline,
            'body'     => $body,
            'tone'     => $tone,
            'ask'      => sprintf('%s: expand on your report this week and tell me what you would do about it.', $role),
        ];
    }

    /**
     * The spec allows exactly three. Ranked from what the week's own record says
     * is unresolved, so they are not aspirations typed in on a Sunday.
     *
     * @return list<array<string,mixed>>
     */
    private function nextThreePriorities(string $from, string $to): array
    {
        $candidates = [];

        $stale = count($this->pipeline->staleLeads());

        if ($stale > 0) {
            $candidates[] = [
                'title'  => sprintf('Contact the %d deal%s that have gone quiet', $stale, $stale === 1 ? '' : 's'),
                'reason' => 'Money already in the pipeline is the cheapest money available.',
                'weight' => 100,
            ];
        }

        $overdue = $this->finance->overdueBills();

        if ($overdue !== []) {
            $candidates[] = [
                'title'  => sprintf('Clear or reschedule %d overdue bill%s', count($overdue), count($overdue) === 1 ? '' : 's'),
                'reason' => 'An overdue obligation costs more than it says on the invoice.',
                'weight' => 95,
            ];
        }

        $portfolio = $this->projects->portfolio('week');
        $atRisk    = array_values(array_filter($portfolio, static fn (array $p): bool => $p['at_risk']));

        if ($atRisk !== []) {
            $candidates[] = [
                'title'  => sprintf('Decide what happens to %s', $atRisk[0]['name']),
                'reason' => 'It has consumed hours with nothing back. Continuing by default is still a decision.',
                'weight' => 80,
            ];
        }

        $ungraded = (int) Database::value(
            "SELECT COUNT(*) FROM decisions WHERE status = 'decided' AND (metric IS NULL OR review_on IS NULL)",
            [],
            0
        );

        if ($ungraded > 0) {
            $candidates[] = [
                'title'  => sprintf('Put a metric and a review date on %d decision%s', $ungraded, $ungraded === 1 ? '' : 's'),
                'reason' => 'A decision that cannot be graded cannot teach you anything.',
                'weight' => 55,
            ];
        }

        $abstract = (int) Database::value(
            "SELECT COUNT(*) FROM goals g WHERE g.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM goal_milestones m WHERE m.goal_id = g.id)",
            [],
            0
        );

        if ($abstract > 0) {
            $candidates[] = [
                'title'  => sprintf('Break %d goal%s into milestones', $abstract, $abstract === 1 ? '' : 's'),
                'reason' => 'A goal with no milestone has no next step.',
                'weight' => 40,
            ];
        }

        $candidates[] = [
            'title'  => 'Close out each day for a week',
            'reason' => 'Scores and the execution trend are only as real as the days that were closed.',
            'weight' => 20,
        ];

        usort($candidates, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return array_slice($candidates, 0, 3);
    }

    // ── Monthly review ──────────────────────────────────────────────────────

    /**
     * The thirteen measures the spec lists, then the seven verdicts. The verdicts
     * are derived from the measures rather than written separately, so they cannot
     * contradict the table above them.
     *
     * @return array<string,mixed>
     */
    private function monthlyReview(string $from, string $to): array
    {
        $income   = $this->finance->incomeBetween($from, $to);
        $spend    = $this->finance->expensesBetween($from, $to);
        $net      = Money::of($income->minor - $spend->minor, $income->currency);
        $survival = $this->finance->monthlySurvivalCost();

        $repaid = Money::of((int) Database::value(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM debt_payments
             WHERE paid_on BETWEEN :f AND :t AND currency = :c',
            ['f' => $from, 't' => $to, 'c' => Money::base()],
            0
        ));

        $saved = Money::of((int) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0)
             FROM savings_entries WHERE occurred_on BETWEEN :f AND :t AND currency = :c",
            ['f' => $from, 't' => $to, 'c' => Money::base()],
            0
        ));

        $clients = (int) Database::value(
            "SELECT COUNT(DISTINCT d.client_id)
             FROM deals d
             JOIN deal_stage_events e ON e.deal_id = d.id AND e.to_stage = 'won'
             WHERE DATE(e.changed_at) BETWEEN :f AND :t",
            ['f' => $from, 't' => $to],
            0
        );

        $prospects  = $this->pipeline->prospectsAddedSince($from);
        $conversion = $this->pipeline->conversion();
        $hours      = $this->projects->hoursInWindow('month');
        $recurring  = $this->revenue->recurring();

        $completed = (int) Database::value(
            "SELECT COUNT(*) FROM projects WHERE archived = 1 AND decision != 'kill'",
            [],
            0
        );

        $killed = (int) Database::value(
            "SELECT COUNT(*) FROM projects WHERE archived = 1 AND decision = 'kill'",
            [],
            0
        );

        // Counted, not taken from a display list: the lessons query below is capped
        // at four and would have silently capped this number too.
        $experiments = (int) Database::value(
            "SELECT COUNT(*) FROM decisions WHERE status = 'reversed'",
            [],
            0
        );

        $lessons = Database::all(
            "SELECT title, outcome FROM decisions
             WHERE outcome IS NOT NULL AND status IN ('reviewed', 'reversed')
             ORDER BY decided_on DESC LIMIT 4"
        );

        // Every label carries the window it was measured over. Without that, money
        // received this month sits beside money that repeats across earlier months
        // and the table reads as a contradiction rather than as two facts.
        $measures = [
            ['label' => 'Revenue received', 'note' => 'This month', 'value' => $income->format(), 'tone' => $income->isZero() ? 'bad' : 'good'],
            ['label' => 'Expenses', 'note' => 'This month', 'value' => $spend->format(), 'tone' => 'neutral'],
            ['label' => 'Net cash movement', 'note' => 'This month', 'value' => $net->format(), 'tone' => $net->minor >= 0 ? 'good' : 'bad'],
            ['label' => 'Debt repaid', 'note' => 'This month', 'value' => $repaid->isZero() ? 'None' : $repaid->format(), 'tone' => $repaid->isZero() ? 'warn' : 'good'],
            ['label' => 'Savings movement', 'note' => 'This month', 'value' => $saved->isZero() ? 'None' : $saved->format(), 'tone' => $saved->minor > 0 ? 'good' : 'warn'],
            ['label' => 'Clients with a deal won', 'note' => 'This month, deal value not cash', 'value' => $clients === 0 ? 'None' : (string) $clients, 'tone' => $clients === 0 ? 'bad' : 'good'],
            ['label' => 'Prospects added', 'note' => 'This month', 'value' => $prospects === 0 ? 'None' : (string) $prospects, 'tone' => $prospects === 0 ? 'warn' : 'good'],
            ['label' => 'Conversion rate', 'note' => 'Every deal ever recorded', 'value' => sprintf('%.1f%%', $conversion['rate']), 'tone' => $conversion['rate'] < 10 ? 'warn' : 'good'],
            ['label' => 'Hours worked', 'note' => 'This month', 'value' => $hours <= 0.0 ? 'None logged' : Hours::format($hours), 'tone' => 'neutral'],
            ['label' => 'Projects finished', 'note' => 'All time', 'value' => $completed === 0 ? 'None' : (string) $completed, 'tone' => 'neutral'],
            ['label' => 'Projects killed', 'note' => 'All time', 'value' => $killed === 0 ? 'None' : (string) $killed, 'tone' => 'neutral'],
            ['label' => 'Recurring revenue', 'note' => 'Repeated in each of the last 3 complete months', 'value' => $recurring['amount']->isZero() ? 'None' : $recurring['amount']->format(), 'tone' => $recurring['amount']->isZero() ? 'bad' : 'good'],
            ['label' => 'Experiments ended', 'note' => 'Decisions reversed, all time', 'value' => $experiments === 0 ? 'None' : (string) $experiments, 'tone' => 'neutral'],
        ];

        return [
            'measures' => $measures,
            'sections' => [
                [
                    'label' => 'What worked',
                    'body'  => $this->whatWorked($income, $repaid, $saved, $clients, $recurring['amount']),
                ],
                [
                    'label' => 'What failed',
                    'body'  => $this->whatFailed($income, $survival, $prospects, $conversion, $killed),
                ],
                [
                    'label' => 'What we learned',
                    'body'  => $lessons === []
                        ? 'Nothing was closed out with a finding this month. Lessons that are not written down are re-learned at full price.'
                        : implode(' ', array_map(
                            static fn (array $d): string => rtrim((string) $d['title'], '.') . ': ' . (string) $d['outcome'],
                            $lessons
                        )),
                ],
                [
                    'label' => 'What to stop',
                    'body'  => $this->whatToStop(),
                ],
                [
                    'label' => 'What to double down on',
                    'body'  => $this->whatToDoubleDown($recurring['amount'], $clients),
                ],
                [
                    'label' => 'Current financial position',
                    'body'  => sprintf(
                        '%s in hand against a %s monthly requirement, %s of debt outstanding, %s set aside. %s',
                        $this->finance->availableCash()->format(),
                        $survival->format(),
                        $this->debt->summary()['remaining']->format(),
                        $this->savings->totalSaved()->format(),
                        $this->finance->runwayDays() === null
                            ? 'Runway cannot be calculated without a recorded cash balance.'
                            : sprintf('That is %d days of runway.', $this->finance->runwayDays())
                    ),
                ],
                [
                    'label' => 'Next month\'s targets',
                    'body'  => $this->nextMonthTargets($income, $survival, $recurring['amount'], $conversion),
                ],
            ],
        ];
    }

    private function whatWorked(Money $income, Money $repaid, Money $saved, int $clients, Money $recurring): string
    {
        $wins = [];

        if (!$income->isZero()) {
            $wins[] = sprintf('%s came in', $income->compact());
        }
        if ($clients > 0) {
            $wins[] = sprintf('%d client%s paid', $clients, $clients === 1 ? '' : 's');
        }
        if (!$repaid->isZero()) {
            $wins[] = sprintf('%s went off the debt', $repaid->compact());
        }
        if ($saved->minor > 0) {
            $wins[] = sprintf('%s was set aside', $saved->compact());
        }
        if (!$recurring->isZero()) {
            $wins[] = sprintf('%s of income now repeats', $recurring->compact());
        }

        if ($wins === []) {
            return 'Nothing in the record counts as a win this month. That is a finding, not a judgement: '
                . 'a month with no wins usually means the inputs were not logged, or the work went somewhere that does not pay.';
        }

        return ucfirst(implode(', ', $wins)) . '. These are the only things worth repeating deliberately.';
    }

    /** @param array{rate:float,won:int,total:int} $conversion */
    private function whatFailed(Money $income, Money $survival, int $prospects, array $conversion, int $killed): string
    {
        $failures = [];

        if ($income->minor < $survival->minor) {
            $failures[] = sprintf(
                'Income of %s did not reach the %s the household needs',
                $income->compact(),
                $survival->compact()
            );
        }
        if ($prospects === 0) {
            $failures[] = 'no new prospect was added all month';
        }
        if ($conversion['total'] > 10 && $conversion['rate'] < 10) {
            $failures[] = sprintf(
                'conversion of %.1f%% across %d deals says the problem is the conversation rather than the volume',
                $conversion['rate'],
                $conversion['total']
            );
        }
        if ($killed > 0) {
            $failures[] = sprintf('%d project%s was stopped', $killed, $killed === 1 ? '' : 's');
        }

        if ($failures === []) {
            return 'Nothing in the record failed outright. Worth checking that the record is complete before believing it.';
        }

        return ucfirst(implode('; ', $failures)) . '.';
    }

    private function whatToStop(): string
    {
        $portfolio = $this->projects->portfolio('month');
        $atRisk    = array_values(array_filter($portfolio, static fn (array $p): bool => $p['at_risk']));

        if ($atRisk !== []) {
            return sprintf(
                'Hours on %s until it produces a revenue signal. %sh have gone in with nothing back, and continuing by default is still a decision, just not one anybody made.',
                $atRisk[0]['name'],
                rtrim(rtrim(number_format((float) $atRisk[0]['hours_total'], 1), '0'), '.')
            );
        }

        $stale = count($this->pipeline->staleLeads());

        if ($stale > 0) {
            return sprintf(
                'Opening new conversations while %d already in the pipeline sit uncontacted. New outreach feels like progress and costs more than a follow-up.',
                $stale
            );
        }

        return 'Nothing in the record argues for stopping something specific. That is rare enough to be worth double-checking against how the month actually felt.';
    }

    private function whatToDoubleDown(Money $recurring, int $clients): string
    {
        if (!$recurring->isZero()) {
            return sprintf(
                'Whatever produced the %s that now repeats. Recurring revenue is the only kind that does not have to be won twice, and the spec ranks it above one-off work for exactly that reason.',
                $recurring->compact()
            );
        }

        if ($clients > 0) {
            return sprintf(
                'Converting the %d client%s who paid this month into something recurring. A client who has already paid once is the cheapest revenue available.',
                $clients,
                $clients === 1 ? '' : 's'
            );
        }

        return 'Nothing has produced enough evidence to double down on yet. The honest move is to find one thing that pays before deciding what to scale.';
    }

    /** @param array{rate:float,won:int,total:int} $conversion */
    private function nextMonthTargets(Money $income, Money $survival, Money $recurring, array $conversion): string
    {
        $targets = [];

        if ($income->minor < $survival->minor) {
            $targets[] = sprintf('reach %s of income, which is survival rather than growth', $survival->compact());
        } else {
            $targets[] = sprintf('hold income above %s', $survival->compact());
        }

        if ($recurring->minor < $survival->minor) {
            $targets[] = 'convert at least one client to something monthly';
        }

        if ($conversion['rate'] < 10 && $conversion['total'] > 5) {
            $targets[] = 'raise conversion by fixing the conversation, not by adding volume';
        }

        return ucfirst(implode('; ', $targets)) . '.';
    }

    // ── Said versus did ─────────────────────────────────────────────────────

    /**
     * Commitments against delivery. Both halves are already recorded; nothing puts
     * them side by side, which is precisely why the gap is comfortable.
     *
     * @return array<string,mixed>
     */
    private function saidVersusDid(string $from, string $to): array
    {
        $tasks = Database::first(
            "SELECT COALESCE(SUM(status = 'done'), 0) AS done, COUNT(*) AS total
             FROM tasks WHERE for_date BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        );

        $priorities = Database::first(
            "SELECT COALESCE(SUM(status = 'done'), 0) AS done, COUNT(*) AS total
             FROM tasks WHERE for_date BETWEEN :f AND :t AND priority_rank IS NOT NULL",
            ['f' => $from, 't' => $to]
        );

        $milestones = Database::first(
            'SELECT
               COALESCE(SUM(done_on IS NOT NULL), 0) AS done,
               COUNT(*) AS total
             FROM goal_milestones WHERE target_on BETWEEN :f AND :t',
            ['f' => $from, 't' => $to]
        );

        $reviews = Database::first(
            "SELECT
               COALESCE(SUM(status IN ('reviewed', 'reversed')), 0) AS done,
               COUNT(*) AS total
             FROM decisions WHERE review_on BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        );

        $rows = [
            $this->promise('Tasks set for the period', (int) ($tasks['done'] ?? 0), (int) ($tasks['total'] ?? 0)),
            $this->promise('Ranked daily priorities', (int) ($priorities['done'] ?? 0), (int) ($priorities['total'] ?? 0)),
            $this->promise('Milestones due', (int) ($milestones['done'] ?? 0), (int) ($milestones['total'] ?? 0)),
            $this->promise('Decision reviews due', (int) ($reviews['done'] ?? 0), (int) ($reviews['total'] ?? 0)),
        ];

        $promised  = array_sum(array_map(static fn (array $r): int => $r['total'], $rows));
        $delivered = array_sum(array_map(static fn (array $r): int => $r['done'], $rows));

        return [
            'rows'      => $rows,
            'promised'  => $promised,
            'delivered' => $delivered,
            'pct'       => $promised === 0 ? null : (int) round(($delivered / $promised) * 100),
            'verdict'   => match (true) {
                $promised === 0                       => 'Nothing was committed to in this period, so nothing can be said about delivery. An empty plan always looks fully delivered.',
                $delivered === $promised              => 'Everything committed to was delivered. Worth checking whether the commitments were ambitious enough to be worth making.',
                $delivered >= (int) ($promised * 0.7) => 'Most of what was committed to happened. The shortfall is normal; a pattern of the same items slipping is not.',
                default                               => 'Less than most of what was committed to was delivered. The useful question is whether the week was overloaded or the commitments were never real.',
            },
        ];
    }

    /** @return array{label:string,done:int,total:int,pct:?int,tone:string} */
    private function promise(string $label, int $done, int $total): array
    {
        $pct = $total === 0 ? null : (int) round(($done / $total) * 100);

        return [
            'label' => $label,
            'done'  => $done,
            'total' => $total,
            'pct'   => $pct,
            'tone'  => match (true) {
                $pct === null => 'quiet',
                $pct >= 80    => 'good',
                $pct >= 50    => 'warn',
                default       => 'high',
            },
        ];
    }

    // ── The cost of waiting ─────────────────────────────────────────────────

    /**
     * Inaction, priced. Every other screen shows what is happening; this shows what
     * not deciding has already cost, because the expensive decisions are usually
     * the ones nobody made.
     *
     * @return array<string,mixed>
     */
    private function costOfWaiting(): array
    {
        $rows = [];

        $stale = $this->pipeline->staleLeads();

        if ($stale !== []) {
            // staleLeads() returns identity, not value, so the exposure is priced
            // here instead of summing a key that does not exist.
            $value = (int) Database::value(
                'SELECT COALESCE(SUM(value_minor), 0) FROM deals
                 WHERE id IN (' . implode(',', array_map('intval', array_column($stale, 'id'))) . ')
                   AND currency = :c',
                ['c' => Money::base()],
                0
            );

            $rows[] = [
                'label'  => sprintf('%d deal%s left to cool', count($stale), count($stale) === 1 ? '' : 's'),
                'cost'   => $value > 0 ? Money::of($value)->compact() . ' of pipeline' : 'Value not recorded',
                'detail' => 'Not refused, just not followed up. Conversion decays with silence, so this is money being lost by default rather than by decision.',
                'tone'   => 'high',
                'ask'    => 'Which of the deals that have gone quiet is worth a message today?',
            ];
        }

        $overdue = $this->finance->overdueBills();

        if ($overdue !== []) {
            $rows[] = [
                'label'  => sprintf('%d bill%s overdue', count($overdue), count($overdue) === 1 ? '' : 's'),
                'cost'   => 'Penalties and standing',
                'detail' => 'An overdue obligation costs more than the invoice says, and the extra is never itemised anywhere.',
                'tone'   => 'high',
                'ask'    => 'What do I do about the overdue bills given the cash position?',
            ];
        }

        $ungraded = (int) Database::value(
            "SELECT COUNT(*) FROM decisions WHERE status = 'decided' AND (metric IS NULL OR review_on IS NULL)",
            [],
            0
        );

        if ($ungraded > 0) {
            $rows[] = [
                'label'  => sprintf('%d decision%s cannot be graded', $ungraded, $ungraded === 1 ? '' : 's'),
                'cost'   => 'The lesson',
                'detail' => 'No metric or no review date means no moment at which it can be shown to have been wrong, so nothing is learned either way.',
                'tone'   => 'warn',
                'ask'    => 'Help me put a metric and a review date on my open decisions.',
            ];
        }

        $expired = (int) Database::value(
            'SELECT COUNT(*) FROM research_notes
             WHERE archived = 0 AND DATE_ADD(checked_on, INTERVAL shelf_life_days DAY) < :today',
            ['today' => Clock::todayString()],
            0
        );

        if ($expired > 0) {
            $rows[] = [
                'label'  => sprintf('%d finding%s out of date', $expired, $expired === 1 ? '' : 's'),
                'cost'   => 'Decisions built on it',
                'detail' => 'Old information does not announce itself. Anything decided against these is being decided on a guess wearing a fact\'s clothes.',
                'tone'   => 'warn',
                'ask'    => 'Which of my out-of-date findings matters most to re-check?',
            ];
        }

        $portfolio = $this->projects->portfolio('month');
        $atRisk    = array_values(array_filter($portfolio, static fn (array $p): bool => $p['at_risk']));

        foreach ($atRisk as $project) {
            $rows[] = [
                'label'  => sprintf('%s continues by default', $project['name']),
                'cost'   => Hours::format((float) $project['hours_total']) . ' so far',
                'detail' => 'No revenue and no near milestone. The hours are real whether or not a decision was ever made about them.',
                'tone'   => 'high',
                'ask'    => sprintf('Should I pause, pivot or kill %s? Give me the case for each.', $project['name']),
            ];
        }

        $unclosed = (int) Database::value(
            'SELECT COUNT(*) FROM (
                 SELECT d.for_date FROM day_logs d WHERE d.closed_at IS NULL AND d.for_date < :today
             ) AS x',
            ['today' => Clock::todayString()],
            0
        );

        if ($unclosed > 0) {
            $rows[] = [
                'label'  => sprintf('%d day%s never closed out', $unclosed, $unclosed === 1 ? '' : 's'),
                'cost'   => 'The scores',
                'detail' => 'A day that is not closed cannot be scored, so the execution trend is measured on a subset and reads better than reality.',
                'tone'   => 'warn',
                'ask'    => 'What am I not seeing because I have not been closing out my days?',
            ];
        }

        return [
            'rows'  => $rows,
            'clear' => $rows === [],
        ];
    }

    // ── The OS, audited ─────────────────────────────────────────────────────

    /**
     * The Critic pointed at the OS rather than at Gerald. A system that reports on
     * everything except itself is asking to be trusted more than it has earned.
     *
     * @return array<string,mixed>
     */
    private function selfAudit(string $from, string $to): array
    {
        $commands = Database::first(
            "SELECT
               COUNT(*) AS total,
               COALESCE(SUM(driver = 'claude'), 0) AS model,
               COALESCE(SUM(error IS NOT NULL AND error != ''), 0) AS failed,
               COALESCE(ROUND(AVG(latency_ms)), 0) AS avg_ms
             FROM commands WHERE created_at BETWEEN :f AND :t",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
        );

        $alerts = Database::first(
            "SELECT
               COALESCE(SUM(status = 'open'), 0) AS open,
               COALESCE(SUM(status = 'resolved'), 0) AS resolved,
               COALESCE(SUM(status = 'dismissed'), 0) AS dismissed
             FROM alerts"
        );

        $briefs = Database::first(
            "SELECT
               COUNT(*) AS total,
               COALESCE(SUM(generator = 'rules'), 0) AS rules
             FROM board_briefs WHERE for_date BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        );

        $reversed = (int) Database::value("SELECT COUNT(*) FROM decisions WHERE status = 'reversed'", [], 0);
        $graded   = (int) Database::value(
            "SELECT COUNT(*) FROM decisions WHERE status IN ('reviewed', 'reversed')",
            [],
            0
        );

        $total  = (int) ($commands['total'] ?? 0);
        $model  = (int) ($commands['model'] ?? 0);
        $failed = (int) ($commands['failed'] ?? 0);

        $rows = [
            [
                'label' => 'Questions asked',
                'value' => $total === 0 ? 'None' : (string) $total,
                'note'  => $total === 0
                    ? 'The board was not consulted in this period.'
                    : sprintf(
                        '%d answered by a model, %d by the rules engine.',
                        $model,
                        $total - $model
                    ),
                'tone'  => 'neutral',
            ],
            [
                'label' => 'Answers that failed',
                'value' => $failed === 0 ? 'None' : (string) $failed,
                'note'  => $failed === 0
                    ? 'No call to the model failed.'
                    : 'A failed call falls back to the rules engine, which is labelled but is not the same answer.',
                'tone'  => $failed === 0 ? 'good' : 'warn',
            ],
            [
                'label' => 'Average answer time',
                'value' => (int) ($commands['avg_ms'] ?? 0) === 0
                    ? 'n/a'
                    : (((int) $commands['avg_ms'] < 1000)
                        ? $commands['avg_ms'] . 'ms'
                        : round(((int) $commands['avg_ms']) / 1000, 1) . 's'),
                'note'  => 'Rules answers are instant. A slow average means the model is doing the work.',
                'tone'  => 'neutral',
            ],
            [
                'label' => 'Briefs from rules only',
                'value' => (int) ($briefs['total'] ?? 0) === 0
                    ? 'None generated'
                    : sprintf('%d of %d', (int) ($briefs['rules'] ?? 0), (int) ($briefs['total'] ?? 0)),
                'note'  => (int) ($briefs['rules'] ?? 0) > 0
                    ? 'Those days got arithmetic rather than reasoning, and the screens said so at the time.'
                    : 'Every brief in the period was reasoned rather than assembled.',
                'tone'  => (int) ($briefs['rules'] ?? 0) > 0 ? 'warn' : 'good',
            ],
            [
                'label' => 'Alerts dismissed',
                'value' => (int) ($alerts['dismissed'] ?? 0) === 0 ? 'None' : (string) (int) $alerts['dismissed'],
                'note'  => (int) ($alerts['dismissed'] ?? 0) > 0
                    ? 'Dismissed is not resolved. If a rule keeps raising something that does not matter, the rule is wrong and should be changed rather than silenced.'
                    : 'Nothing has been waved away, so the rules are still earning their place.',
                'tone'  => (int) ($alerts['dismissed'] ?? 0) > 0 ? 'warn' : 'good',
            ],
            [
                'label' => 'Decisions reversed',
                'value' => $graded === 0 ? 'None graded' : sprintf('%d of %d', $reversed, $graded),
                'note'  => $graded === 0
                    ? 'No decision has been closed out, so the OS has no evidence about its own advice.'
                    : ($reversed === 0
                        ? 'Nothing reversed. Either the calls were good or nobody looked hard.'
                        : 'Reversals are the only proof that reviews are real rather than ceremonial.'),
                'tone'  => 'neutral',
            ],
        ];

        return [
            'rows'    => $rows,
            'verdict' => $total === 0 && (int) ($briefs['total'] ?? 0) === 0
                ? 'The OS did nothing in this period that can be audited. That usually means it was not used.'
                : 'Everything above is the OS reporting on itself. A system that reports on everything except its own behaviour is asking for more trust than it has earned.',
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $trust
     * @return array{text:string,provenance:string}
     */
    private function insight(string $period, string $from, string $to, array $trust): array
    {
        $income = $this->finance->incomeBetween($from, $to);
        $spend  = $this->finance->expensesBetween($from, $to);
        $parts  = [];

        // Trust first, but pointed at rather than restated: the card below carries
        // the full headline, and printing it twice teaches the reader to skip it.
        if ($trust['level'] > 0) {
            $parts[] = sprintf(
                'Read the card below first: %d of %d inputs %s behind, so some figures here are precise without being current.',
                $trust['stale'],
                count($trust['rows']),
                $trust['stale'] === 1 ? 'is' : 'are'
            );
        }

        $parts[] = sprintf(
            'Across this %s, %s came in and %s went out.',
            $period,
            $income->compact(),
            $spend->compact()
        );

        $parts[] = $income->minor >= $spend->minor
            ? 'The period paid for itself.'
            : sprintf('The period cost %s more than it earned.', Money::of($spend->minor - $income->minor, $spend->currency)->compact());

        $parts[] = $period === 'week'
            ? 'The nine role reports below are derived from the record rather than written, so a role with nothing to say says so.'
            : 'The verdicts below are derived from the measures above them, so they cannot contradict the table.';

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    // ── Windows ─────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string} */
    private function window(string $period): array
    {
        if ($period === 'month') {
            return [Clock::monthStart(), Clock::today()->format('Y-m-d')];
        }

        return Period::window('week');
    }

    private function rangeLabel(string $period, string $from, string $to): string
    {
        $start = new \DateTimeImmutable($from, Clock::zone());
        $end   = new \DateTimeImmutable($to, Clock::zone());

        return $period === 'month'
            ? $start->format('F Y')
            : $start->format('j M') . ' to ' . $end->format('j M');
    }

    /** @return list<array{key:string,label:string,active:bool}> */
    private function periodOptions(string $active): array
    {
        return [
            ['key' => 'week',  'label' => 'Weekly board meeting', 'active' => $active === 'week'],
            ['key' => 'month', 'label' => 'Monthly review',       'active' => $active === 'month'],
        ];
    }
}
