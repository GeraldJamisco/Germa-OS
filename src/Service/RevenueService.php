<?php

declare(strict_types=1);

namespace Germa\Service;

use DateTimeImmutable;
use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Period;
use Germa\Support\Provenance;

/**
 * Business → Revenue.
 *
 * Income asks whether enough money arrived. This asks the structural question
 * behind it: where is revenue coming from, how much of it repeats, and how
 * exposed is the household if one source stops. The spec ranks recurring
 * revenue above one-off work for exactly that reason.
 */
final class RevenueService
{
    public function __construct(
        private readonly FinanceService $finance = new FinanceService(),
        private readonly PipelineService $pipeline = new PipelineService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'month'): array
    {
        $period = Period::normalise($period);
        [$from, $to] = Period::window($period);

        $sources    = $this->bySource($from, $to);
        $trend      = $this->trend();
        $recurring  = $this->recurring();

        return [
            'period'       => $period,
            'period_label' => Period::OPTIONS[$period],
            'periods'      => Period::options($period),
            'range_label'  => Period::rangeLabel($period),
            'summary'      => $this->summary($period, $from, $to, $sources),
            'insight'      => $this->insight($from, $to, $sources, $recurring),
            'trend'        => $trend,
            'facts'        => $this->facts($trend, $recurring, $sources),
            'sources'      => $sources,
            'recent'       => $this->recentEvents(),
            'suggestions'  => [
                "I'm too dependent on one client",
                "I want revenue that repeats every month",
                "I don't know which work is actually worth doing",
                "I want to turn a client into a retainer",
                'Call the board',
            ],
        ];
    }

    // ── Summary ─────────────────────────────────────────────────────────────

    /**
     * @param array{rows:list<array<string,mixed>>,total:Money,earning:int} $sources
     * @return list<array<string,mixed>>
     */
    private function summary(string $period, string $from, string $to, array $sources): array
    {
        $revenue  = $this->finance->incomeBetween($from, $to);
        $survival = $this->finance->monthlySurvivalCost();
        $expected = $this->pipeline->expectedValue();
        $deals    = count($this->pipeline->activeDeals(50));

        return [
            [
                'label'  => 'Revenue ' . Period::phrase($period),
                'metric' => Metric::money($revenue, Provenance::Entered, 'Money actually received in this period'),
                'delta'  => $survival->isZero() ? 'No target set' : 'of ' . $survival->compact() . ' target',
                'tone'   => $revenue->isZero() ? 'bad' : 'good',
            ],
            [
                'label'  => 'Expected pipeline value',
                'metric' => Metric::money($expected, Provenance::Estimated, 'Open deal value weighted by your win probabilities'),
                'delta'  => 'across ' . $deals . ' live deal' . ($deals === 1 ? '' : 's'),
                'tone'   => 'neutral',
            ],
            [
                'label'  => 'Active revenue sources',
                'metric' => Metric::number($sources['earning'], Provenance::Entered, 'Sources that produced money in this period'),
                'delta'  => 'of ' . count($sources['rows']) . ' tracked',
                'tone'   => $sources['earning'] <= 1 ? 'bad' : 'good',
            ],
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param array{rows:list<array<string,mixed>>,total:Money,earning:int} $sources
     * @param array{amount:Money,sources:list<string>}                     $recurring
     * @return array{text:string,provenance:string}
     */
    private function insight(string $from, string $to, array $sources, array $recurring): array
    {
        $revenue = $this->finance->incomeBetween($from, $to);
        $parts   = [];

        $earning = array_values(array_filter($sources['rows'], static fn (array $r): bool => !$r['amount']->isZero()));

        if ($revenue->isZero()) {
            $parts[] = 'No revenue recorded in this period.';
        } else {
            $parts[] = sprintf('%s received this period.', $revenue->format());
        }

        // Concentration is the structural risk the CFO should keep naming.
        if (count($earning) === 1 && !$sources['total']->isZero()) {
            $parts[] = sprintf(
                'All of it came from %s: revenue is 100%% concentrated in one source, so losing it means losing everything.',
                $earning[0]['name']
            );
        } elseif ($earning !== [] && !$sources['total']->isZero()) {
            $share = (int) round(($earning[0]['amount']->minor / $sources['total']->minor) * 100);
            if ($share >= 70) {
                $parts[] = sprintf('%d%% of it came from %s, which is a single point of failure.', $share, $earning[0]['name']);
            }
        }

        // The recurring line has to stand on its own when nothing came in this
        // period: "of it" would refer to nothing.
        if ($recurring['amount']->isZero()) {
            $parts[] = $revenue->isZero()
                ? 'Nothing recurs either, so there is no floor under next month. Converting one client to a retainer is worth more than winning one new project.'
                : 'None of it repeats: every shilling has to be won again next month. Converting one client to a retainer is worth more than winning one new project.';
        } else {
            $parts[] = sprintf(
                '%s a month does repeat, from %s. That much is already committed for next month.',
                $recurring['amount']->format(),
                implode(' and ', $recurring['sources'])
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }

    // ── Trend ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function trend(int $months = 6): array
    {
        $start = Clock::today()->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
        $now   = Clock::today()->format('Y-m');

        $byMonth = [];
        foreach (Database::all(
            "SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym, COALESCE(SUM(amount_minor), 0) AS total
             FROM ledger_entries
             WHERE direction = 'in' AND currency = :c AND occurred_on >= :from
             GROUP BY ym",
            ['c' => Money::base(), 'from' => $start->format('Y-m-d')]
        ) as $row) {
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

        return [
            'months'   => $series,
            'average'  => Money::of($complete === [] ? 0 : (int) round(array_sum($complete) / count($complete))),
            'max'      => Money::of($max),
            'has_data' => $max > 0,
        ];
    }

    /**
     * Revenue that has arrived in each of the last three complete months from
     * the same source. Not a flag anyone sets: repetition is the only
     * evidence that something actually recurs.
     *
     * Public because Goals needs it too: the mission ladder cannot tell stability
     * from reliable income without it, and a second copy of the three-month rule
     * would drift from this one.
     *
     * @return array{amount:Money,sources:list<string>}
     */
    public function recurring(): array
    {
        $months = [];
        for ($i = 1; $i <= 3; $i++) {
            $months[] = Clock::today()->modify('first day of this month')->modify("-$i months")->format('Y-m');
        }

        $rows = Database::all(
            "SELECT COALESCE(p.name, l.category) AS source,
                    DATE_FORMAT(l.occurred_on, '%Y-%m') AS ym,
                    SUM(l.amount_minor) AS total
             FROM ledger_entries l
             LEFT JOIN projects p ON p.id = l.project_id
             WHERE l.direction = 'in' AND l.currency = :c
               AND DATE_FORMAT(l.occurred_on, '%Y-%m') IN (:m1, :m2, :m3)
             GROUP BY source, ym",
            ['c' => Money::base(), 'm1' => $months[0], 'm2' => $months[1], 'm3' => $months[2]]
        );

        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(string) $row['source']][(string) $row['ym']] = (int) $row['total'];
        }

        $amount  = 0;
        $sources = [];

        foreach ($bySource as $source => $byMonth) {
            if (count($byMonth) < 3) {
                continue; // A gap means it is not yet recurring.
            }

            // The dependable part is the smallest of the three, not the average.
            $amount   += min($byMonth);
            $sources[] = $source;
        }

        return ['amount' => Money::of($amount), 'sources' => $sources];
    }

    /**
     * @param array<string,mixed>                                          $trend
     * @param array{amount:Money,sources:list<string>}                     $recurring
     * @param array{rows:list<array<string,mixed>>,total:Money,earning:int} $sources
     * @return list<array{label:string,value:string,tone:?string}>
     */
    private function facts(array $trend, array $recurring, array $sources): array
    {
        $earning = array_values(array_filter($sources['rows'], static fn (array $r): bool => !$r['amount']->isZero()));

        $concentration = ($earning === [] || $sources['total']->isZero())
            ? 'No revenue yet'
            : sprintf(
                '%d%% · %d source%s',
                (int) round(($earning[0]['amount']->minor / $sources['total']->minor) * 100),
                count($earning),
                count($earning) === 1 ? '' : 's'
            );

        return [
            [
                'label' => 'Average monthly revenue',
                'value' => $trend['average']->format(),
                'tone'  => null,
            ],
            [
                'label' => 'Recurring revenue',
                'value' => $recurring['amount']->format() . ($recurring['amount']->isZero() ? '' : '/mo'),
                'tone'  => $recurring['amount']->isZero() ? 'bad' : 'good',
            ],
            [
                'label' => 'Concentration',
                'value' => $concentration,
                'tone'  => count($earning) === 1 ? 'bad' : null,
            ],
        ];
    }

    // ── Sources ─────────────────────────────────────────────────────────────

    /**
     * Revenue by project, every project listed. A project earning nothing is
     * the point of the list, not an omission from it.
     *
     * @return array{rows:list<array<string,mixed>>,total:Money,earning:int}
     */
    private function bySource(string $from, string $to): array
    {
        $byProject = [];
        foreach (Database::all(
            "SELECT project_id, SUM(amount_minor) AS total
             FROM ledger_entries
             WHERE direction = 'in' AND currency = :c AND project_id IS NOT NULL
               AND occurred_on BETWEEN :from AND :to
             GROUP BY project_id",
            ['c' => Money::base(), 'from' => $from, 'to' => $to]
        ) as $row) {
            $byProject[(int) $row['project_id']] = (int) $row['total'];
        }

        $rows  = [];
        $total = 0;

        foreach ((new ProjectService())->portfolio() as $project) {
            $amount = $byProject[$project['id']] ?? 0;
            $total += $amount;

            $rows[] = [
                'name'   => $project['name'],
                'amount' => Money::of($amount),
                'status' => $project['status_label'],
            ];
        }

        foreach (Database::all(
            "SELECT category, SUM(amount_minor) AS total
             FROM ledger_entries
             WHERE direction = 'in' AND currency = :c AND project_id IS NULL
               AND occurred_on BETWEEN :from AND :to
             GROUP BY category",
            ['c' => Money::base(), 'from' => $from, 'to' => $to]
        ) as $row) {
            $amount = (int) $row['total'];
            $total += $amount;

            $rows[] = [
                'name'   => $row['category'] === 'client_payment' ? 'Client payments (unattributed)' : ucfirst(str_replace('_', ' ', (string) $row['category'])),
                'amount' => Money::of($amount),
                'status' => 'Not linked to a project',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['amount']->minor <=> $a['amount']->minor);

        $earning = 0;
        foreach ($rows as $index => $row) {
            $rows[$index]['pct'] = $total > 0 ? ($row['amount']->minor / $total) * 100 : 0;
            if (!$row['amount']->isZero()) {
                $earning++;
            }
        }

        return ['rows' => $rows, 'total' => Money::of($total), 'earning' => $earning];
    }

    /** @return list<array<string,mixed>> */
    private function recentEvents(int $limit = 6): array
    {
        $rows = Database::all(
            "SELECT l.amount_minor, l.currency, l.occurred_on, l.description, l.category, p.name AS project
             FROM ledger_entries l
             LEFT JOIN projects p ON p.id = l.project_id
             WHERE l.direction = 'in'
             ORDER BY l.occurred_on DESC, l.id DESC
             LIMIT " . max(1, $limit)
        );

        $out = [];

        foreach ($rows as $row) {
            $amount = Money::of((int) $row['amount_minor'], (string) $row['currency']);
            $when   = new DateTimeImmutable((string) $row['occurred_on'], Clock::zone());
            $source = $row['project'] ?? ucfirst(str_replace('_', ' ', (string) $row['category']));

            $out[] = [
                'name'   => $row['description'] ?? $source,
                'meta'   => $source . ' · ' . $when->format('j M'),
                'amount' => '+' . $amount->format(),
            ];
        }

        return $out;
    }
}
