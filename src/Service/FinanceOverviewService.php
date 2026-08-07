<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Provenance;
use Germa\Support\Settings;
use DateTimeImmutable;

/**
 * The Money → Financial Overview screen.
 *
 * Everything here reads the same ledger the dashboard does; the difference is
 * the window. The dashboard answers "what about today"; this answers "what has
 * been happening", so every figure is period-scoped and comparable against the
 * period before it.
 */
final class FinanceOverviewService
{
    public const PERIODS = ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    /** Expense categories that cannot be cut without changing how the household lives. */
    private const FIXED_CATEGORIES = ['rent', 'utilities', 'school', 'debt_payment'];

    private readonly FinanceService $finance;
    private readonly DebtService $debt;

    public function __construct(?FinanceService $finance = null, ?DebtService $debt = null)
    {
        $this->finance = $finance ?? new FinanceService();
        $this->debt    = $debt ?? new DebtService();
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'month'): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'month';
        [$from, $to] = $this->window($period);
        [$prevFrom, $prevTo] = $this->previousWindow($period);

        $debt = $this->debt->summary();

        return [
            'period'      => $period,
            'period_label' => self::PERIODS[$period],
            'periods'     => $this->periodOptions($period),
            'range_label' => $this->rangeLabel($period, $from, $to),
            'summary'     => $this->summary($period, $from, $to, $prevFrom, $prevTo),
            'insight'     => $this->insight($from, $to),
            'cashflow'    => $this->cashFlow(),
            'runway'      => $this->runway(),
            'income'      => $this->incomeSources($from, $to),
            'expenses'    => $this->expenseCategories($from, $to),
            'transactions' => $this->recentTransactions(),
            'debt'        => [
                'summary'      => $debt,
                'total_metric' => $this->debt->totalMetric(),
                'next_metric'  => $this->debt->nextPaymentMetric(),
                'payoff_label' => $debt['target_payoff_on'] === null
                    ? null
                    : (new DateTimeImmutable($debt['target_payoff_on'], Clock::zone()))->format('j M Y'),
            ],
            'suggestions' => [
                "I don't know where the money is going",
                "I need to cut something this month",
                "I'm thinking about a purchase",
                "I don't think I'm on pace for " . $this->finance->monthlySurvivalCost()->compact(),
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
            'week' => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
            'year' => [$today->format('Y') . '-01-01', $today->format('Y-m-d')],
            default => [Clock::monthStart(), Clock::monthEnd()],
        };
    }

    /** The same length of time immediately before, for the comparison line. */
    private function previousWindow(string $period): array
    {
        $today = Clock::today();

        return match ($period) {
            'week' => [
                $today->modify('monday last week')->format('Y-m-d'),
                $today->modify('sunday last week')->format('Y-m-d'),
            ],
            'year' => [
                ((int) $today->format('Y') - 1) . '-01-01',
                ((int) $today->format('Y') - 1) . '-12-31',
            ],
            default => [
                $today->modify('first day of last month')->format('Y-m-d'),
                $today->modify('last day of last month')->format('Y-m-d'),
            ],
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

    // ── Summary row ─────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function summary(string $period, string $from, string $to, string $prevFrom, string $prevTo): array
    {
        $income      = $this->finance->incomeBetween($from, $to);
        $expenses    = $this->finance->expensesBetween($from, $to);
        $prevExpense = $this->finance->expensesBetween($prevFrom, $prevTo);
        $prevIncome  = $this->finance->incomeBetween($prevFrom, $prevTo);
        $net         = Money::of($income->minor - $expenses->minor);
        $runway      = $this->finance->runwayDays();
        $label       = strtolower(self::PERIODS[$period]);

        return [
            [
                'label'  => 'Available cash',
                'metric' => $this->finance->cashMetric(),
                'delta'  => $this->finance->cashInUsd()->display . ' equivalent',
                'tone'   => 'neutral',
            ],
            [
                'label'  => 'Income · this ' . $label,
                'metric' => Metric::money($income, Provenance::Entered, 'Recorded receipts in this period'),
                'delta'  => $this->comparison($income, $prevIncome, 'last ' . $label, true),
                'tone'   => $this->tone($income, $prevIncome, true),
            ],
            [
                'label'  => 'Expenses · this ' . $label,
                'metric' => Metric::money($expenses, Provenance::Entered, 'Recorded spending in this period'),
                'delta'  => $this->comparison($expenses, $prevExpense, 'last ' . $label, false),
                'tone'   => $this->tone($expenses, $prevExpense, false),
            ],
            [
                'label'  => 'Net position',
                'metric' => Metric::money($net, Provenance::Calculated, 'Income less expenses over the period'),
                'delta'  => $runway === null
                    ? 'Runway not calculable'
                    : $runway . ' day' . ($runway === 1 ? '' : 's') . ' runway',
                'tone'   => $net->isNegative() ? 'bad' : 'good',
            ],
        ];
    }

    /** "+12% vs last month", or an absolute statement when there is no baseline. */
    private function comparison(Money $now, Money $before, string $label, bool $moreIsBetter): string
    {
        if ($before->isZero()) {
            return $now->isZero() ? 'Nothing recorded' : 'No ' . $label . ' to compare against';
        }

        $change = (($now->minor - $before->minor) / $before->minor) * 100;

        if (abs($change) < 1) {
            return 'Level with ' . $label;
        }

        return sprintf('%+d%% vs %s', (int) round($change), $label);
    }

    /** Direction of travel, judged against what the household actually wants. */
    private function tone(Money $now, Money $before, bool $moreIsBetter): string
    {
        if ($before->isZero()) {
            return 'neutral';
        }

        $up = $now->minor > $before->minor;

        return $up === $moreIsBetter ? 'good' : 'bad';
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * A rules-derived sentence about the period. Deliberately not a model call:
     * this screen is opened often, the statement is arithmetic, and the
     * dashboard already carries the AI voice.
     *
     * @return array{text:string,provenance:string}
     */
    private function insight(string $from, string $to): array
    {
        $income   = $this->finance->incomeBetween($from, $to);
        $expenses = $this->finance->expensesBetween($from, $to);
        $survival = $this->finance->monthlySurvivalCost();
        $runway   = $this->finance->runwayDays();
        $parts    = [];

        if ($expenses->minor > $income->minor) {
            $gap     = Money::of($expenses->minor - $income->minor);
            $parts[] = sprintf('Spending is outpacing recorded income by %s this period.', $gap->format());
        } elseif ($income->minor > 0) {
            $parts[] = sprintf('Income is ahead of spending by %s this period.', Money::of($income->minor - $expenses->minor)->format());
        } else {
            $parts[] = 'No income has been recorded in this period.';
        }

        if ($runway !== null && $runway < Settings::int('runway_critical_days', 7)) {
            $parts[] = sprintf(
                'With %d day%s of runway, hold discretionary categories until the income plan lands a payment.',
                $runway,
                $runway === 1 ? '' : 's'
            );
        } elseif ($survival->minor > 0 && $income->minor < $survival->minor) {
            $parts[] = sprintf('Recorded income is still short of the %s monthly requirement.', $survival->format());
        }

        $nonEssential = $this->finance->nonEssentialSpend($from, $to);
        if ($nonEssential->minor > 0 && $expenses->minor > 0) {
            $share   = (int) round(($nonEssential->minor / $expenses->minor) * 100);
            $parts[] = sprintf('%s of it (%d%%) was non-essential.', $nonEssential->format(), $share);
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }

    // ── Cash flow ───────────────────────────────────────────────────────────

    /**
     * Six months of income against expenses. Bar heights are relative to the
     * largest single figure across both series, so the two are directly
     * comparable rather than each scaled to its own maximum.
     *
     * @return array{months:list<array<string,mixed>>,max:Money}
     */
    private function cashFlow(int $months = 6): array
    {
        $cursor = Clock::today()->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
        $today  = Clock::todayString();

        $rows = Database::all(
            "SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym,
                    COALESCE(SUM(CASE WHEN direction = 'in'  THEN amount_minor ELSE 0 END), 0) AS money_in,
                    COALESCE(SUM(CASE WHEN direction = 'out' THEN amount_minor ELSE 0 END), 0) AS money_out
             FROM ledger_entries
             WHERE currency = :c AND occurred_on >= :from
             GROUP BY ym",
            ['c' => Money::base(), 'from' => $cursor->format('Y-m-d')]
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['ym']] = [
                'in'  => (int) $row['money_in'],
                'out' => (int) $row['money_out'],
            ];
        }

        $max    = 0;
        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $cursor->modify('+' . $i . ' months');
            $key   = $month->format('Y-m');
            $in    = $byMonth[$key]['in'] ?? 0;
            $out   = $byMonth[$key]['out'] ?? 0;
            $max   = max($max, $in, $out);

            $series[] = [
                'key'      => $key,
                'label'    => $month->format('M'),
                'in'       => Money::of($in),
                'out'      => Money::of($out),
                'is_now'   => $month->format('Y-m') === substr($today, 0, 7),
            ];
        }

        // Percentages of the tallest bar; a non-zero month always shows.
        foreach ($series as $index => $month) {
            $series[$index]['in_pct']  = $max > 0 ? max(($month['in']->minor / $max) * 100, $month['in']->minor > 0 ? 3 : 0) : 0;
            $series[$index]['out_pct'] = $max > 0 ? max(($month['out']->minor / $max) * 100, $month['out']->minor > 0 ? 3 : 0) : 0;
        }

        return ['months' => $series, 'max' => Money::of($max)];
    }

    /** @return array<string,mixed> */
    private function runway(): array
    {
        $days   = $this->finance->runwayDays();
        $cash   = $this->finance->availableCash();
        $daily  = $this->finance->dailySurvivalCost();
        $target = Settings::int('runway_warning_days', 21);

        return [
            'metric'      => $this->finance->runwayMetric(),
            'days'        => $days,
            'pct_of_goal' => $days === null || $target <= 0 ? 0 : min(100, ($days / $target) * 100),
            'goal_days'   => $target,
            'explanation' => $days === null
                ? 'Set the monthly survival requirement in Settings to measure runway.'
                : sprintf(
                    'At %s a day against %s available, essential household costs are covered for %d more day%s without new income.',
                    $daily->format(),
                    $cash->format(),
                    $days,
                    $days === 1 ? '' : 's'
                ),
        ];
    }

    // ── Breakdowns ──────────────────────────────────────────────────────────

    /**
     * Income grouped by what produced it: a project where the entry names one,
     * otherwise the category.
     *
     * @return array{rows:list<array<string,mixed>>,total:Money}
     */
    private function incomeSources(string $from, string $to): array
    {
        $rows = Database::all(
            "SELECT COALESCE(p.name, l.category) AS source,
                    SUM(l.amount_minor) AS total
             FROM ledger_entries l
             LEFT JOIN projects p ON p.id = l.project_id
             WHERE l.direction = 'in' AND l.currency = :c
               AND l.occurred_on BETWEEN :from AND :to
             GROUP BY source
             ORDER BY total DESC",
            ['c' => Money::base(), 'from' => $from, 'to' => $to]
        );

        return $this->asBreakdown($rows, 'source', static fn (): array => ['tag' => null]);
    }

    /**
     * Expenses grouped by category, each classified so the screen shows what is
     * actually cuttable, the whole point of looking at this list when cash is
     * short.
     *
     * @return array{rows:list<array<string,mixed>>,total:Money}
     */
    private function expenseCategories(string $from, string $to): array
    {
        $rows = Database::all(
            "SELECT l.category AS source,
                    SUM(l.amount_minor) AS total,
                    MIN(l.is_essential) AS min_essential
             FROM ledger_entries l
             WHERE l.direction = 'out' AND l.currency = :c
               AND l.occurred_on BETWEEN :from AND :to
             GROUP BY l.category
             ORDER BY total DESC",
            ['c' => Money::base(), 'from' => $from, 'to' => $to]
        );

        return $this->asBreakdown($rows, 'source', static function (array $row): array {
            $category = (string) $row['source'];

            if ((int) $row['min_essential'] === 0) {
                return ['tag' => 'Flag', 'tag_class' => 'flag', 'fill' => 'coral'];
            }
            if (in_array($category, self::FIXED_CATEGORIES, true)) {
                return ['tag' => 'Fixed', 'tag_class' => 'fixed', 'fill' => 'ink'];
            }

            return ['tag' => 'Variable', 'tag_class' => 'variable', 'fill' => 'quiet'];
        });
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):array<string,mixed> $decorate
     * @return array{rows:list<array<string,mixed>>,total:Money}
     */
    private function asBreakdown(array $rows, string $nameKey, callable $decorate): array
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['total'];
        }

        $out = [];
        foreach ($rows as $row) {
            $amount = Money::of((int) $row['total']);

            // The decorator goes on the LEFT: with `+`, the left operand wins on
            // duplicate keys, so defaults must be the fallback, not the override.
            $out[] = $decorate($row) + [
                'name'      => $this->humanCategory((string) $row[$nameKey]),
                'amount'    => $amount,
                'pct'       => $total > 0 ? ($amount->minor / $total) * 100 : 0,
                'fill'      => 'ink',
                'tag'       => null,
                'tag_class' => null,
            ];
        }

        return ['rows' => $out, 'total' => Money::of($total)];
    }

    private function humanCategory(string $raw): string
    {
        return match ($raw) {
            'client_payment' => 'Client payments',
            'debt_payment'   => 'Debt service',
            'data'           => 'Data & airtime',
            'business'       => 'Business tools & hosting',
            'other'          => 'Discretionary',
            'general'        => 'Uncategorised',
            default          => ucfirst(str_replace('_', ' ', $raw)),
        };
    }

    // ── Transactions ────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function recentTransactions(int $limit = 6): array
    {
        $rows = Database::all(
            "SELECT l.id, l.direction, l.amount_minor, l.currency, l.occurred_on,
                    l.category, l.description, l.is_essential, p.name AS project
             FROM ledger_entries l
             LEFT JOIN projects p ON p.id = l.project_id
             ORDER BY l.occurred_on DESC, l.id DESC
             LIMIT " . max(1, $limit),
        );

        $txns = [];

        foreach ($rows as $row) {
            $isIn   = (string) $row['direction'] === 'in';
            $amount = Money::of((int) $row['amount_minor'], (string) $row['currency']);
            $when   = new DateTimeImmutable((string) $row['occurred_on'], Clock::zone());
            $group  = $this->humanCategory((string) $row['category']);

            $txns[] = [
                'name'    => $row['description'] ?? $group,
                'meta'    => ($row['project'] ?? $group) . ' · ' . $when->format('j M'),
                'amount'  => ($isIn ? '+' : '-') . $amount->format(),
                'is_in'   => $isIn,
                'icon'    => $isIn ? '↑' : '↓',
                // Debt service reads differently from ordinary spending: it is
                // the one outflow that improves the position.
                'variant' => $isIn ? 'in' : ((string) $row['category'] === 'debt_payment' ? 'debt' : 'out'),
            ];
        }

        return $txns;
    }
}
