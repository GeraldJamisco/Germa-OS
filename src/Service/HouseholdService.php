<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Hours;
use Germa\Support\Money;
use Germa\Support\Provenance;

/**
 * Household → Household Overview.
 *
 * The spec is unusually direct here: "Do not treat Gerald's life purely as a
 * business optimization problem. The objective is to build a sustainable life,
 * not merely maximize income." The household is part of the operating system,
 * not a cost line inside it.
 *
 * So this screen is not another money dashboard. It answers the one question the
 * rest of the OS cannot ask about itself: is the business being run at the
 * household's expense? Three measures answer it, and all three come from records
 * that already exist.
 *
 *  - Cover: is the household funded, in months rather than in shillings.
 *  - Load: hours worked, and how many days have passed without one off. A person
 *    is a resource with a maintenance schedule, and the OS is the only thing
 *    positioned to notice it being skipped.
 *  - Commitments: what is owed to people, with a cadence. A family day that was
 *    meant to be weekly and last happened three weeks ago is exactly the kind of
 *    quiet debt no financial screen will ever show.
 */
final class HouseholdService
{
    /** Above this many hours in seven days, the pace is not sustainable. */
    private const HEAVY_WEEK_HOURS = 55;

    /** Days of continuous work after which a rest day is overdue. */
    private const REST_INTERVAL_DAYS = 7;

    public function __construct(
        private readonly FinanceService $finance = new FinanceService(),
        private readonly SavingsService $savings = new SavingsService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $cover       = $this->cover();
        $load        = $this->load();
        $commitments = $this->commitments();
        $split       = $this->spendSplit();

        return [
            'insight'     => $this->insight($cover, $load, $commitments),
            'summary'     => $this->summary($cover, $load, $commitments),
            'cover'       => $cover,
            'load'        => $load,
            'split'       => $split,
            'commitments' => $commitments,
            'suggestions' => [
                'Am I working at a pace I can keep up?',
                'What is the household owed that I have not given it?',
                'Is the business costing my family more than it pays?',
                'What can I stop doing this week?',
                'Call the board',
            ],
        ];
    }

    // ── Cover ───────────────────────────────────────────────────────────────

    /**
     * How long the household is funded for, stated in months of survival cost.
     * Shillings are the wrong unit for this question: "UGX 150,000" means nothing
     * without the monthly requirement beside it.
     *
     * @return array<string,mixed>
     */
    private function cover(): array
    {
        $survival = $this->finance->monthlySurvivalCost();
        $cash     = $this->finance->availableCash();
        $reserve  = $this->savings->totalSaved();

        $funded = Money::of($cash->minor + $reserve->minor, $cash->currency);
        $months = $survival->minor > 0 ? $funded->minor / $survival->minor : 0.0;

        return [
            'survival'    => $survival,
            'cash'        => $cash,
            'reserve'     => $reserve,
            'funded'      => $funded,
            'months'      => round($months, 1),
            'months_label' => $survival->minor <= 0
                ? 'No survival cost set'
                : ($months < 0.1 ? 'Under a week' : sprintf('%.1f months of cover', $months)),
            'tone'        => match (true) {
                $survival->minor <= 0 => 'quiet',
                $months < 1.0         => 'bad',
                $months < 3.0         => 'warn',
                default               => 'good',
            },
            // The spec's own baseline: three months is the reserve target.
            'target_months' => 3,
            'pct'         => (int) round(min(100.0, ($months / 3) * 100)),
        ];
    }

    // ── Load ────────────────────────────────────────────────────────────────

    /**
     * Workload and rest, from logged hours. Both project time logs and the
     * end-of-day review carry hours, and the review is the more complete of the
     * two because it counts work that was not on a project.
     *
     * @return array<string,mixed>
     */
    private function load(): array
    {
        // Six days back plus today is seven days. Using daysAgo(7) spans eight
        // calendar days, which is how "8 of the last 7" ends up on screen.
        $since = Clock::daysAgo(6);

        $projectMinutes = (int) Database::value(
            'SELECT COALESCE(SUM(minutes), 0) FROM project_time_logs WHERE logged_on >= :since',
            ['since' => $since],
            0
        );

        $reviewHours = (float) Database::value(
            'SELECT COALESCE(SUM(hours_worked), 0) FROM day_logs WHERE for_date >= :since',
            ['since' => $since],
            0
        );

        // Whichever record is more complete. They measure the same week, so
        // adding them would double count the hours that appear in both.
        $hours = max($projectMinutes / 60, $reviewHours);

        $daysWorked = (int) Database::value(
            'SELECT COUNT(DISTINCT d) FROM (
                 SELECT logged_on AS d FROM project_time_logs WHERE logged_on >= :since1
                 UNION
                 SELECT for_date AS d FROM day_logs WHERE for_date >= :since2 AND hours_worked > 0
             ) AS worked',
            ['since1' => $since, 'since2' => $since],
            0
        );

        $lastRest = $this->lastRestDay();
        $sinceRest = $lastRest === null ? null : abs(Clock::daysUntil($lastRest));

        return [
            'hours'        => round($hours, 1),
            'hours_label'  => $hours <= 0.0 ? 'No hours logged' : Hours::format($hours) . ' in the last seven days',
            'days_worked'  => $daysWorked,
            'last_rest'    => $lastRest,
            'since_rest'   => $sinceRest,
            'rest_label'   => match (true) {
                $lastRest === null  => 'No rest day on record',
                $sinceRest === 0    => 'Resting today',
                $sinceRest === 1    => 'Last full day off yesterday',
                default             => sprintf('%d days since a full day off', (int) $sinceRest),
            },
            'rest_overdue' => $sinceRest === null || $sinceRest > self::REST_INTERVAL_DAYS,
            'heavy'        => $hours > self::HEAVY_WEEK_HOURS,
            'pace_label'   => match (true) {
                $hours <= 0.0                       => 'Nothing logged, so the pace is unknown',
                $hours > self::HEAVY_WEEK_HOURS      => 'Above a pace anyone keeps up for long',
                $hours > 40                          => 'A full week, sustainable if it does not become every week',
                default                              => 'Inside a sustainable week',
            },
            'pct'          => (int) round(min(100.0, ($hours / self::HEAVY_WEEK_HOURS) * 100)),
            'cap'          => self::HEAVY_WEEK_HOURS,
        ];
    }

    /**
     * The most recent day in the last three weeks with no work logged against it.
     * Absence of a record is the evidence here, which is why it is computed by
     * walking back through the days rather than queried directly.
     */
    private function lastRestDay(): ?string
    {
        $worked = Database::all(
            'SELECT DISTINCT d FROM (
                 SELECT logged_on AS d FROM project_time_logs WHERE logged_on >= :since1
                 UNION
                 SELECT for_date AS d FROM day_logs WHERE for_date >= :since2 AND hours_worked > 0
             ) AS w',
            ['since1' => Clock::daysAgo(21), 'since2' => Clock::daysAgo(21)]
        );

        $busy = array_map(static fn (array $r): string => (string) $r['d'], $worked);

        for ($i = 0; $i <= 21; $i++) {
            $day = Clock::today()->modify("-$i days")->format('Y-m-d');

            if (!in_array($day, $busy, true)) {
                return $day;
            }
        }

        return null;
    }

    // ── Spending split ──────────────────────────────────────────────────────

    /**
     * How much of what goes out is the household versus the business. The point
     * is not to police it: it is that a household carrying the business shows up
     * here first, long before it shows up in a row.
     *
     * @return array<string,mixed>
     */
    private function spendSplit(): array
    {
        $rows = Database::all(
            "SELECT category, SUM(amount_minor) total
             FROM ledger_entries
             WHERE direction = 'out' AND currency = :cur AND occurred_on >= :since
             GROUP BY category ORDER BY total DESC",
            ['cur' => Money::base(), 'since' => Clock::monthStart()]
        );

        // Categories the household lives on, as opposed to money spent to trade.
        $householdCategories = ['rent', 'food', 'utilities', 'transport', 'health', 'education', 'household'];

        $household = 0;
        $business  = 0;
        $breakdown = [];

        foreach ($rows as $row) {
            $category = mb_strtolower((string) $row['category']);
            $amount   = (int) $row['total'];
            $isHome   = in_array($category, $householdCategories, true);

            $isHome ? $household += $amount : $business += $amount;

            $breakdown[] = [
                'name'   => ucfirst((string) $row['category']),
                'amount' => Money::of($amount, Money::base()),
                'is_household' => $isHome,
            ];
        }

        $total = $household + $business;

        foreach ($breakdown as $i => $row) {
            $breakdown[$i]['pct'] = $total === 0 ? 0 : (int) round(($row['amount']->minor / $total) * 100);
        }

        return [
            'household'     => Money::of($household, Money::base()),
            'business'      => Money::of($business, Money::base()),
            'total'         => Money::of($total, Money::base()),
            'household_pct' => $total === 0 ? 0 : (int) round(($household / $total) * 100),
            'rows'          => $breakdown,
            'has_data'      => $total > 0,
        ];
    }

    // ── Commitments ─────────────────────────────────────────────────────────

    /**
     * What is owed to people rather than to creditors. Each carries a cadence, so
     * "overdue" means something concrete: it was meant to happen every N days and
     * it has been longer than that.
     *
     * @return list<array<string,mixed>>
     */
    private function commitments(): array
    {
        $rows = Database::all(
            'SELECT * FROM household_commitments WHERE archived = 0
             ORDER BY last_honoured_on IS NULL DESC, last_honoured_on ASC'
        );

        $commitments = [];

        foreach ($rows as $row) {
            $cadence = (int) $row['cadence_days'];
            $last    = $row['last_honoured_on'] ?? null;
            $since   = $last === null ? null : abs(Clock::daysUntil((string) $last));
            $overdue = $cadence > 0 && ($since === null || $since > $cadence);

            $commitments[] = [
                'id'        => (int) $row['id'],
                'title'     => (string) $row['title'],
                'kind'      => (string) $row['kind'],
                'kind_label' => match ((string) $row['kind']) {
                    'relationship'   => 'RELATIONSHIP',
                    'responsibility' => 'RESPONSIBILITY',
                    'rest'           => 'REST',
                    default          => 'DEVELOPMENT',
                },
                'note'      => $row['note'] ?? null,
                'cadence'   => $cadence,
                'cadence_label' => $cadence === 0
                    ? 'One off'
                    : ($cadence === 1 ? 'Every day' : sprintf('Every %d days', $cadence)),
                'since'     => $since,
                'since_label' => match (true) {
                    $since === null => 'Never recorded',
                    $since === 0    => 'Today',
                    $since === 1    => 'Yesterday',
                    default         => sprintf('%d days ago', (int) $since),
                },
                'overdue'   => $overdue,
                'tone'      => $overdue ? 'high' : 'good',
                'ask'       => sprintf(
                    'This household commitment is overdue: %s. It was meant to happen %s and last happened %s. What should I move to make room?',
                    (string) $row['title'],
                    $cadence === 0 ? 'once' : sprintf('every %d days', $cadence),
                    $since === null ? 'never' : sprintf('%d days ago', (int) $since)
                ),
            ];
        }

        return $commitments;
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>       $cover
     * @param array<string,mixed>       $load
     * @param list<array<string,mixed>> $commitments
     * @return array{text:string,provenance:string}
     */
    private function insight(array $cover, array $load, array $commitments): array
    {
        $overdue = array_values(array_filter($commitments, static fn (array $c): bool => $c['overdue']));
        $parts   = [];

        // Money first, because an unfunded household makes the rest academic.
        $parts[] = $cover['survival']->minor <= 0
            ? 'No monthly survival cost is set, so there is no way to say whether the household is funded.'
            : sprintf(
                'The household has %s against a %s month.',
                $cover['months'] < 0.1 ? 'under a week of cover' : sprintf('%.1f months of cover', $cover['months']),
                $cover['survival']->compact()
            );

        // Then the part no financial screen will ever raise.
        if ($load['heavy'] && $load['rest_overdue']) {
            $parts[] = sprintf(
                '%s and %s. That is not a hard week, it is a pace with no recovery built into it, and the thing that '
                . 'breaks first will be the person doing the work.',
                $load['hours_label'],
                lcfirst((string) $load['rest_label'])
            );
        } elseif ($load['rest_overdue'] && $load['hours'] > 0.0) {
            $parts[] = sprintf(
                '%s. A day off is not time taken from the work, it is what keeps next week possible.',
                $load['rest_label']
            );
        } elseif ($load['heavy']) {
            $parts[] = sprintf('%s, which is above what anyone keeps up for long.', $load['hours_label']);
        }

        if ($overdue !== []) {
            $parts[] = sprintf(
                '%s %s overdue by %s own cadence. Nothing in the financial screens will ever raise %s, which is why %s here.',
                count($overdue) === 1 ? '"' . $overdue[0]['title'] . '" is' : count($overdue) . ' household commitments are',
                '',
                count($overdue) === 1 ? 'its' : 'their',
                count($overdue) === 1 ? 'it' : 'them',
                count($overdue) === 1 ? 'it is' : 'they are'
            );
        } elseif ($commitments !== []) {
            $parts[] = 'Every household commitment is inside its cadence.';
        }

        return [
            'text'       => preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? implode(' ', $parts),
            'provenance' => Provenance::Calculated->value,
        ];
    }

    /**
     * @param array<string,mixed>       $cover
     * @param array<string,mixed>       $load
     * @param list<array<string,mixed>> $commitments
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $cover, array $load, array $commitments): array
    {
        $overdue = count(array_filter($commitments, static fn (array $c): bool => $c['overdue']));

        return [
            [
                'label' => 'Household cover',
                'value' => $cover['survival']->minor <= 0 ? 'n/a' : sprintf('%.1f months', $cover['months']),
                'tone'  => $cover['tone'] === 'bad' ? 'bad' : ($cover['tone'] === 'good' ? 'good' : 'warn'),
            ],
            [
                'label' => 'Hours last 7 days',
                'value' => $load['hours'] <= 0.0 ? 'None logged' : Hours::format($load['hours']),
                'tone'  => $load['heavy'] ? 'bad' : 'neutral',
            ],
            [
                'label' => 'Since a day off',
                'value' => $load['since_rest'] === null
                    ? 'Not recorded'
                    : sprintf('%d day%s', (int) $load['since_rest'], (int) $load['since_rest'] === 1 ? '' : 's'),
                'tone'  => $load['rest_overdue'] ? 'bad' : 'good',
            ],
            [
                'label' => 'Commitments overdue',
                'value' => $overdue === 0 ? 'None' : (string) $overdue,
                'tone'  => $overdue === 0 ? 'good' : 'bad',
            ],
        ];
    }
}
