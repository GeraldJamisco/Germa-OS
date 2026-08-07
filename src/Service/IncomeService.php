<?php

declare(strict_types=1);

namespace Germa\Service;

use DateTimeImmutable;
use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Provenance;
use Germa\Support\Settings;

/**
 * The Money → Income screen.
 *
 * The Financial Overview asks "where did the money go"; this asks the harder
 * question the spec actually cares about: where is money going to come from,
 * and is the answer big enough. So it leads with the target rather than the
 * total, and every source carries its status rather than just its amount.
 */
final class IncomeService
{
    public const PERIODS = ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    private readonly FinanceService $finance;
    private readonly IncomeTargetService $target;
    private readonly ProjectService $projects;

    public function __construct(
        ?FinanceService $finance = null,
        ?IncomeTargetService $target = null,
        ?ProjectService $projects = null,
    ) {
        $this->finance  = $finance ?? new FinanceService();
        $this->target   = $target ?? new IncomeTargetService($this->finance, new DebtService());
        $this->projects = $projects ?? new ProjectService();
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'month'): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'month';
        [$from, $to] = $this->window($period);

        $sources = $this->sources($from, $to);
        $trend   = $this->trend();

        return [
            'period'       => $period,
            'period_label' => self::PERIODS[$period],
            'periods'      => $this->periodOptions($period),
            'range_label'  => $this->rangeLabel($period, $from, $to),
            'target'       => $this->targetCard(),
            'summary'      => $this->summary($period, $from, $to, $sources),
            'insight'      => $this->insight($from, $to, $sources, $trend),
            'trend'        => $trend,
            'sources'      => $sources,
            'payments'     => $this->recentPayments(),
            'suggestions'  => [
                "I don't know where my next payment is coming from",
                "I need to close the gap to " . $this->finance->monthlySurvivalCost()->compact(),
                "I'm not sure which source to put today into",
                "I want something I can invoice this week",
                'Call the board',
            ],
        ];
    }

    // ── Windows ─────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string} */
    private function window(string $period): array
    {
        $today = Clock::today();

        return match ($period) {
            'week'  => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
            'year'  => [$today->format('Y') . '-01-01', $today->format('Y-m-d')],
            default => [Clock::monthStart(), Clock::monthEnd()],
        };
    }

    private function rangeLabel(string $period, string $from, string $to): string
    {
        $start = new DateTimeImmutable($from, Clock::zone());
        $end   = new DateTimeImmutable($to, Clock::zone());

        return match ($period) {
            'week'  => $start->format('j M') . ' – ' . $end->format('j M'),
            'year'  => $start->format('Y'),
            default => $start->format('F Y'),
        };
    }

    /** @return list<array{key:string,label:string,active:bool}> */
    private function periodOptions(string $active): array
    {
        $options = [];
        foreach (self::PERIODS as $key => $label) {
            $options[] = ['key' => $key, 'label' => $label, 'active' => $key === $active];
        }
        return $options;
    }

    // ── Today's target ──────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function targetCard(): array
    {
        $today = $this->target->today();

        return [
            'metric'       => $this->target->targetMetric(),
            'earned'       => $today['earned'],
            'progress_pct' => $today['progress_pct'],
            'shortfall'    => $today['shortfall'],
            'basis'        => implode(' · ', $today['basis']),
        ];
    }

    // ── Summary ─────────────────────────────────────────────────────────────

    /**
     * @param array{rows:list<array<string,mixed>>,total:Money} $sources
     * @return list<array<string,mixed>>
     */
    private function summary(string $period, string $from, string $to, array $sources): array
    {
        $earned   = $this->finance->incomeBetween($from, $to);
        $survival = $this->finance->monthlySurvivalCost();
        $daysLeft = Clock::workingDaysLeftInMonth();
        $active   = 0;

        foreach ($sources['rows'] as $row) {
            if ($row['is_active']) {
                $active++;
            }
        }

        return [
            [
                'label'  => 'Earned this ' . strtolower(self::PERIODS[$period]),
                'metric' => Metric::money($earned, Provenance::Entered, 'Recorded receipts in this period'),
                'delta'  => $survival->isZero()
                    ? 'No target set'
                    : 'of ' . $survival->compact() . ' target',
                'tone'   => $survival->isZero() || $earned->minor >= $survival->minor ? 'good' : 'neutral',
            ],
            [
                'label'  => 'Days remaining',
                'metric' => Metric::number($daysLeft, Provenance::Calculated, 'Mon–Sat, to the end of the month'),
                'delta'  => 'working days left',
                'tone'   => 'neutral',
            ],
            [
                'label'  => 'Active sources',
                'metric' => Metric::number($active, Provenance::Entered, 'Sources that have produced money'),
                'delta'  => $active === 0 ? 'none producing revenue' : 'producing revenue',
                'tone'   => $active === 0 ? 'bad' : 'good',
            ],
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param array{rows:list<array<string,mixed>>,total:Money} $sources
     * @param array<string,mixed>                              $trend
     * @return array{text:string,provenance:string}
     */
    private function insight(string $from, string $to, array $sources, array $trend): array
    {
        $earned   = $this->finance->incomeBetween($from, $to);
        $survival = $this->finance->monthlySurvivalCost();
        $parts    = [];

        if ($earned->isZero()) {
            $parts[] = $survival->isZero()
                ? 'No income has been recorded in this period.'
                : sprintf('No income has been recorded this period against a %s survival target.', $survival->format());
        } else {
            $parts[] = sprintf('%s recorded this period.', $earned->format());

            if (!$survival->isZero() && $earned->minor < $survival->minor) {
                $parts[] = sprintf('That is %s short of the monthly requirement.', Money::of($survival->minor - $earned->minor)->format());
            }
        }

        // Concentration is the risk worth naming: one source is not a business.
        $earning = array_values(array_filter($sources['rows'], static fn (array $r): bool => $r['is_active']));

        if (count($earning) === 1) {
            $parts[] = sprintf('%s is the only source producing anything: a single client is a single point of failure.', $earning[0]['name']);
        } elseif ($earning === []) {
            $parts[] = 'No source has produced money in this period, so the pipeline is the only thing that can change that.';
        } elseif (($sources['total']->minor > 0) && ($earning[0]['amount']->minor / $sources['total']->minor) > 0.7) {
            $parts[] = sprintf('%s accounts for most of it: worth widening before it becomes the whole business.', $earning[0]['name']);
        }

        if ($trend['average']->minor > 0 && !$survival->isZero() && $trend['average']->minor < $survival->minor) {
            $parts[] = sprintf(
                'The six-month average of %s is below what the household costs to run.',
                $trend['average']->format()
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }

    // ── Trend ───────────────────────────────────────────────────────────────

    /**
     * Six months of recorded income, plus the three figures that say whether it
     * is enough: the average, the best month, and the gap to survival.
     *
     * @return array{months:list<array<string,mixed>>,average:Money,best:Money,gap:Money,max:Money}
     */
    private function trend(int $months = 6): array
    {
        $start = Clock::today()->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
        $now   = Clock::today()->format('Y-m');

        $rows = Database::all(
            "SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym, COALESCE(SUM(amount_minor), 0) AS total
             FROM ledger_entries
             WHERE direction = 'in' AND currency = :c AND occurred_on >= :from
             GROUP BY ym",
            ['c' => Money::base(), 'from' => $start->format('Y-m-d')]
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['ym']] = (int) $row['total'];
        }

        $series   = [];
        $max      = 0;
        $complete = [];

        for ($i = 0; $i < $months; $i++) {
            $month  = $start->modify('+' . $i . ' months');
            $key    = $month->format('Y-m');
            $amount = $byMonth[$key] ?? 0;
            $isNow  = $key === $now;
            $max    = max($max, $amount);

            // The month in progress is not a fair member of an average.
            if (!$isNow) {
                $complete[] = $amount;
            }

            $series[] = [
                'key'    => $key,
                'label'  => $month->format('M'),
                'amount' => Money::of($amount),
                'is_now' => $isNow,
            ];
        }

        foreach ($series as $index => $month) {
            $series[$index]['pct'] = $max > 0
                ? max(($month['amount']->minor / $max) * 100, $month['amount']->minor > 0 ? 3 : 0)
                : 0;
        }

        $average  = $complete === [] ? 0 : (int) round(array_sum($complete) / count($complete));
        $survival = $this->finance->monthlySurvivalCost();

        return [
            'months'  => $series,
            'average' => Money::of($average),
            'best'    => Money::of($max),
            // Negative means short. Measured against the average, since a single
            // good month does not pay next month's rent.
            'gap'     => Money::of($average - $survival->minor),
            'max'     => Money::of($max),
        ];
    }

    // ── Sources ─────────────────────────────────────────────────────────────

    /**
     * Every place money could come from, ranked by what it actually produced.
     *
     * Projects appear even when they have earned nothing this period, a
     * strategic project sitting at zero is exactly the thing this screen exists
     * to make visible.
     *
     * @return array{rows:list<array<string,mixed>>,total:Money}
     */
    private function sources(string $from, string $to): array
    {
        $earnedByProject = [];
        foreach (Database::all(
            "SELECT project_id, SUM(amount_minor) AS total
             FROM ledger_entries
             WHERE direction = 'in' AND currency = :c AND project_id IS NOT NULL
               AND occurred_on BETWEEN :from AND :to
             GROUP BY project_id",
            ['c' => Money::base(), 'from' => $from, 'to' => $to]
        ) as $row) {
            $earnedByProject[(int) $row['project_id']] = (int) $row['total'];
        }

        $rows  = [];
        $total = 0;

        foreach ($this->projects->portfolio() as $project) {
            $amount = $earnedByProject[$project['id']] ?? 0;
            $total += $amount;

            $rows[] = [
                'name'      => $project['name'],
                'amount'    => Money::of($amount),
                'status'    => $this->projectStatus($project, $amount > 0),
                'is_active' => $amount > 0,
                'project_id' => $project['id'],
            ];
        }

        // Income that names no project still has to be accounted for.
        foreach (Database::all(
            "SELECT category, SUM(amount_minor) AS total
             FROM ledger_entries
             WHERE direction = 'in' AND currency = :c AND project_id IS NULL
               AND occurred_on BETWEEN :from AND :to
             GROUP BY category
             ORDER BY total DESC",
            ['c' => Money::base(), 'from' => $from, 'to' => $to]
        ) as $row) {
            $amount = (int) $row['total'];
            $total += $amount;

            $rows[] = [
                'name'       => $this->humanCategory((string) $row['category']),
                'amount'     => Money::of($amount),
                'status'     => 'Unattributed · not linked to a project',
                'is_active'  => $amount > 0,
                'project_id' => null,
            ];
        }

        // Earning sources first, then by size; a producing source outranks a
        // promising one.
        usort($rows, static function (array $a, array $b): int {
            return [$b['is_active'], $b['amount']->minor] <=> [$a['is_active'], $a['amount']->minor];
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = sprintf('%02d', $index + 1);
            $rows[$index]['pct']  = $total > 0 ? ($row['amount']->minor / $total) * 100 : 0;
        }

        return ['rows' => $rows, 'total' => Money::of($total)];
    }

    /** @param array<string,mixed> $project */
    private function projectStatus(array $project, bool $earnedThisPeriod): string
    {
        $label = $project['status_label'];

        if ($earnedThisPeriod) {
            return $label . ' · paid this period';
        }

        if ($project['earns_revenue']) {
            return $label . ' · earned before, nothing this period';
        }

        return $label . ($project['hours_14d'] > 0
            ? sprintf(' · pre-revenue, %.1fh in 14 days', $project['hours_14d'])
            : ' · pre-revenue');
    }

    private function humanCategory(string $raw): string
    {
        return match ($raw) {
            'client_payment' => 'Client payments',
            'general'        => 'Uncategorised',
            default          => ucfirst(str_replace('_', ' ', $raw)),
        };
    }

    // ── Payments ────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function recentPayments(int $limit = 6): array
    {
        $rows = Database::all(
            "SELECT l.amount_minor, l.currency, l.occurred_on, l.category,
                    l.description, p.name AS project
             FROM ledger_entries l
             LEFT JOIN projects p ON p.id = l.project_id
             WHERE l.direction = 'in'
             ORDER BY l.occurred_on DESC, l.id DESC
             LIMIT " . max(1, $limit)
        );

        $payments = [];

        foreach ($rows as $row) {
            $amount = Money::of((int) $row['amount_minor'], (string) $row['currency']);
            $when   = new DateTimeImmutable((string) $row['occurred_on'], Clock::zone());
            $source = $row['project'] ?? $this->humanCategory((string) $row['category']);

            $payments[] = [
                'name'   => $row['description'] ?? $source,
                'meta'   => $source . ' · ' . $when->format('j M'),
                'amount' => '+' . $amount->format(),
            ];
        }

        return $payments;
    }
}
