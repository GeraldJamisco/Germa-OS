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
 * The Money → Expenses screen.
 *
 * Income asks where money comes from; this asks what it is committed to, and
 * how much of that is actually negotiable. So every category carries its
 * essential/non-essential classification, and bills: money promised but not
 * yet gone: get a section of their own rather than hiding inside a total.
 */
final class ExpenseService
{
    public const PERIODS = ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    /** Costs that cannot move without changing how the household lives. */
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

        $bills      = $this->upcomingBills();
        $categories = $this->categories($from, $to, $bills);
        $trend      = $this->trend();

        return [
            'period'       => $period,
            'period_label' => self::PERIODS[$period],
            'periods'      => $this->periodOptions($period),
            'range_label'  => $this->rangeLabel($period, $from, $to),
            'budget'       => $this->budget(),
            'summary'      => $this->summary($period, $from, $to, $bills),
            'insight'      => $this->insight($from, $to, $categories, $bills),
            'trend'        => $trend,
            'categories'   => $categories,
            'recent'       => $this->recentExpenses(),
            'bills'        => $bills,
            'suggestions'  => [
                "I need to cut something this month",
                "I can't cover every bill that's due",
                "I'm thinking about spending on something",
                "I feel like money is leaking somewhere",
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

    // ── Budget ──────────────────────────────────────────────────────────────

    /**
     * The survival requirement, and how much of it this month has consumed.
     * Over-budget is reported as over-budget, not clamped to 100%.
     *
     * @return array<string,mixed>
     */
    private function budget(): array
    {
        $budget = $this->finance->monthlySurvivalCost();
        $spent  = $this->finance->expensesThisMonth();
        $pct    = $budget->minor > 0 ? ($spent->minor / $budget->minor) * 100 : 0.0;

        return [
            'metric'    => $budget->isZero()
                ? Metric::unavailable('Set the monthly survival requirement in Settings')
                : Metric::money($budget, Provenance::Entered, 'The monthly household survival requirement'),
            'budget'    => $budget,
            'spent'     => $spent,
            'remaining' => Money::of($budget->minor - $spent->minor),
            'pct'       => $pct,
            'over'      => $budget->minor > 0 && $spent->minor > $budget->minor,
        ];
    }

    // ── Summary ─────────────────────────────────────────────────────────────

    /**
     * @param array{rows:list<array<string,mixed>>,total:Money,count:int} $bills
     * @return list<array<string,mixed>>
     */
    private function summary(string $period, string $from, string $to, array $bills): array
    {
        $spent  = $this->finance->expensesBetween($from, $to);
        $budget = $this->finance->monthlySurvivalCost();

        return [
            [
                'label'  => 'Spent this ' . strtolower(self::PERIODS[$period]),
                'metric' => Metric::money($spent, Provenance::Entered, 'Recorded spending in this period'),
                'delta'  => $budget->isZero() ? 'No budget set' : 'of ' . $budget->compact() . ' budget',
                'tone'   => $budget->isZero() || $spent->minor <= $budget->minor ? 'neutral' : 'bad',
            ],
            [
                'label'  => 'Days remaining',
                'metric' => Metric::number(Clock::workingDaysLeftInMonth(), Provenance::Calculated, 'Mon–Sat, to the end of the month'),
                'delta'  => 'working days left',
                'tone'   => 'neutral',
            ],
            [
                'label'  => 'Bills due soon',
                'metric' => Metric::number($bills['count'], Provenance::Entered, 'Unpaid bills and debt payments inside 14 days'),
                'delta'  => $bills['count'] === 0 ? 'nothing committed' : $bills['total']->compact() . ' within 14 days',
                'tone'   => $bills['count'] === 0 ? 'good' : 'bad',
            ],
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param array{rows:list<array<string,mixed>>,total:Money} $categories
     * @param array{rows:list<array<string,mixed>>,total:Money,count:int,unfunded:bool} $bills
     * @return array{text:string,provenance:string}
     */
    private function insight(string $from, string $to, array $categories, array $bills): array
    {
        $spent    = $this->finance->expensesBetween($from, $to);
        $budget   = $this->finance->monthlySurvivalCost();
        $parts    = [];

        if ($spent->isZero()) {
            $parts[] = 'No spending has been recorded in this period.';
        } elseif (!$budget->isZero()) {
            $share = (int) round(($spent->minor / $budget->minor) * 100);
            $parts[] = $spent->minor > $budget->minor
                ? sprintf('Spending is %s over the %s survival budget.', Money::of($spent->minor - $budget->minor)->format(), $budget->format())
                : sprintf('%s spent, %d%% of the %s survival budget.', $spent->format(), $share, $budget->format());
        } else {
            $parts[] = sprintf('%s spent this period.', $spent->format());
        }

        // What is actually cuttable is the only question worth answering here.
        $discretionary = Money::zero();
        foreach ($categories['rows'] as $row) {
            if (!$row['is_essential']) {
                $discretionary = $discretionary->plus($row['amount']);
            }
        }

        if ($discretionary->isZero() && !$spent->isZero()) {
            $parts[] = 'All of it was essential, so there is nothing here to cut. The gap has to close on the income side.';
        } elseif (!$discretionary->isZero()) {
            $parts[] = sprintf('%s of it was non-essential and could be held.', $discretionary->format());
        }

        if ($bills['count'] > 0) {
            $soonest = $bills['rows'][0];
            $parts[] = sprintf(
                '%s falls due %s%s.',
                $soonest['title'],
                $soonest['when_phrase'],
                $bills['unfunded'] ? ' with no cash allocated' : ''
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }

    // ── Trend ───────────────────────────────────────────────────────────────

    /**
     * Six months of spending, plus the essential/discretionary split that says
     * how much of it was ever negotiable.
     *
     * @return array<string,mixed>
     */
    private function trend(int $months = 6): array
    {
        $start = Clock::today()->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
        $now   = Clock::today()->format('Y-m');

        $rows = Database::all(
            "SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym,
                    COALESCE(SUM(amount_minor), 0) AS total,
                    COALESCE(SUM(CASE WHEN is_essential = 1 THEN amount_minor ELSE 0 END), 0) AS essential
             FROM ledger_entries
             WHERE direction = 'out' AND currency = :c AND occurred_on >= :from
             GROUP BY ym",
            ['c' => Money::base(), 'from' => $start->format('Y-m-d')]
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['ym']] = ['total' => (int) $row['total'], 'essential' => (int) $row['essential']];
        }

        $series        = [];
        $max           = 0;
        $complete      = [];
        $allTotal      = 0;
        $allEssential  = 0;

        for ($i = 0; $i < $months; $i++) {
            $month  = $start->modify('+' . $i . ' months');
            $key    = $month->format('Y-m');
            $amount = $byMonth[$key]['total'] ?? 0;
            $isNow  = $key === $now;
            $max    = max($max, $amount);

            $allTotal     += $amount;
            $allEssential += $byMonth[$key]['essential'] ?? 0;

            // A month still running is not a fair member of an average.
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

        $essentialShare = $allTotal > 0 ? (int) round(($allEssential / $allTotal) * 100) : 0;

        return [
            'months'          => $series,
            'average'         => Money::of($complete === [] ? 0 : (int) round(array_sum($complete) / count($complete))),
            'highest'         => Money::of($max),
            'max'             => Money::of($max),
            'essential_pct'   => $essentialShare,
            'discretionary_pct' => $allTotal > 0 ? 100 - $essentialShare : 0,
            'has_data'        => $allTotal > 0,
        ];
    }

    // ── Categories ──────────────────────────────────────────────────────────

    /**
     * Spending grouped by category and ranked by share, each carrying whether
     * it is essential and whether a bill is about to land against it.
     *
     * @param array{rows:list<array<string,mixed>>} $bills
     * @return array{rows:list<array<string,mixed>>,total:Money}
     */
    private function categories(string $from, string $to, array $bills): array
    {
        $rows = Database::all(
            "SELECT category,
                    SUM(amount_minor) AS total,
                    MIN(is_essential) AS min_essential
             FROM ledger_entries
             WHERE direction = 'out' AND currency = :c
               AND occurred_on BETWEEN :from AND :to
             GROUP BY category
             ORDER BY total DESC",
            ['c' => Money::base(), 'from' => $from, 'to' => $to]
        );

        // A bill falling due against a category is the most useful thing that
        // can be said about it, so index them by category first.
        $dueByCategory = [];
        foreach ($bills['rows'] as $bill) {
            $key = $bill['category'];
            if ($key !== null && !isset($dueByCategory[$key])) {
                $dueByCategory[$key] = $bill;
            }
        }

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['total'];
        }

        $out = [];

        foreach ($rows as $index => $row) {
            $category  = (string) $row['category'];
            $amount    = Money::of((int) $row['total']);
            $essential = (int) $row['min_essential'] === 1;
            $fixed     = in_array($category, self::FIXED_CATEGORIES, true);

            $out[] = [
                'rank'         => sprintf('%02d', $index + 1),
                'name'         => $this->humanCategory($category),
                'category'     => $category,
                'amount'       => $amount,
                'pct'          => $total > 0 ? ($amount->minor / $total) * 100 : 0,
                'is_essential' => $essential,
                'status'       => $this->categoryStatus($category, $essential, $fixed, $dueByCategory[$category] ?? null),
                // Non-essential spending is the only kind worth colouring: it is
                // the only kind a decision can change.
                'fill'         => $essential ? ($fixed ? 'ink' : 'quiet') : 'coral',
            ];
        }

        return ['rows' => $out, 'total' => Money::of($total)];
    }

    /** @param array<string,mixed>|null $dueBill */
    private function categoryStatus(string $category, bool $essential, bool $fixed, ?array $dueBill): string
    {
        if (!$essential) {
            return 'Non-essential · can be held';
        }

        if ($dueBill !== null) {
            return 'Essential · ' . $dueBill['title'] . ' due ' . $dueBill['when_phrase'];
        }

        return $fixed ? 'Essential · recurring' : 'Essential · variable';
    }

    private function humanCategory(string $raw): string
    {
        return match ($raw) {
            'rent'         => 'Rent & housing',
            'utilities'    => 'Utilities',
            'data'         => 'Data & airtime',
            'food'         => 'Food & household',
            'debt_payment' => 'Debt service',
            'business'     => 'Business tools & hosting',
            'other'        => 'Discretionary',
            'general'      => 'Uncategorised',
            default        => ucfirst(str_replace('_', ' ', $raw)),
        };
    }

    // ── Recent expenses ─────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function recentExpenses(int $limit = 6): array
    {
        $rows = Database::all(
            "SELECT amount_minor, currency, occurred_on, category, description, is_essential
             FROM ledger_entries
             WHERE direction = 'out'
             ORDER BY occurred_on DESC, id DESC
             LIMIT " . max(1, $limit)
        );

        $out = [];

        foreach ($rows as $row) {
            $amount   = Money::of((int) $row['amount_minor'], (string) $row['currency']);
            $when     = new DateTimeImmutable((string) $row['occurred_on'], Clock::zone());
            $category = $this->humanCategory((string) $row['category']);

            $out[] = [
                'name'    => $row['description'] ?? $category,
                'meta'    => $category . ' · ' . $when->format('j M'),
                'amount'  => '-' . $amount->format(),
                // Non-essential rows are marked so the list reads as a decision
                // record, not just a log.
                'variant' => (int) $row['is_essential'] === 1 ? 'out' : 'debt',
            ];
        }

        return $out;
    }

    // ── Upcoming bills ──────────────────────────────────────────────────────

    /**
     * Everything committed inside 14 days: unpaid bills plus scheduled debt
     * payments, which are an obligation by any other name.
     *
     * @return array{rows:list<array<string,mixed>>,total:Money,count:int,unfunded:bool}
     */
    private function upcomingBills(int $withinDays = 14): array
    {
        $until = Clock::today()->modify("+$withinDays days")->format('Y-m-d');

        $rows = [];

        foreach (Database::all(
            "SELECT id, name, amount_minor, currency, due_on
             FROM bills
             WHERE status = 'due' AND due_on <= :until
             ORDER BY due_on",
            ['until' => $until]
        ) as $bill) {
            $rows[] = $this->dueRow(
                (int) $bill['id'],
                (string) $bill['name'],
                Money::of((int) $bill['amount_minor'], (string) $bill['currency']),
                (string) $bill['due_on'],
                $this->billCategory((string) $bill['name'])
            );
        }

        foreach ($this->debt->upcomingPayments($withinDays) as $payment) {
            $rows[] = $this->dueRow(
                null,
                $payment['name'] . ' repayment',
                Money::of((int) $payment['amount_minor'], (string) $payment['currency']),
                (string) $payment['due_on'],
                'debt_payment'
            );
        }

        usort($rows, static fn (array $a, array $b): int => $a['days'] <=> $b['days']);

        // Only base-currency obligations can be summed honestly here.
        $total = Money::zero();
        foreach ($rows as $row) {
            if ($row['amount']->currency === Money::base()) {
                $total = $total->plus($row['amount']);
            }
        }

        return [
            'rows'     => $rows,
            'total'    => $total,
            'count'    => count($rows),
            'unfunded' => $total->minor > $this->finance->availableCash()->minor,
        ];
    }

    /** @return array<string,mixed> */
    private function dueRow(?int $id, string $title, Money $amount, string $dueOn, ?string $category): array
    {
        $days = Clock::daysUntil($dueOn);
        $date = new DateTimeImmutable($dueOn, Clock::zone());

        return [
            'id'          => $id,
            'title'       => $title,
            'amount'      => $amount,
            'due_on'      => $dueOn,
            'days'        => $days,
            'category'    => $category,
            'when'        => match (true) {
                $days < 0   => abs($days) . ' days late',
                $days === 0 => 'today',
                $days === 1 => 'tomorrow',
                $days <= 9  => $days . ' days',
                default     => $date->format('j M'),
            },
            'when_phrase' => match (true) {
                $days < 0   => abs($days) . ' days ago',
                $days === 0 => 'today',
                $days === 1 => 'tomorrow',
                default     => 'in ' . $days . ' days',
            },
            'tone'        => match (true) {
                $days <= 3 => 'critical',
                $days <= 7 => 'high',
                default    => 'calm',
            },
        ];
    }

    private function billCategory(string $name): ?string
    {
        $lower = mb_strtolower($name);

        return match (true) {
            str_contains($lower, 'rent')     => 'rent',
            str_contains($lower, 'electric'),
            str_contains($lower, 'water'),
            str_contains($lower, 'power')    => 'utilities',
            str_contains($lower, 'internet'),
            str_contains($lower, 'data'),
            str_contains($lower, 'airtime')  => 'data',
            str_contains($lower, 'school'),
            str_contains($lower, 'fees')     => 'school',
            default                          => null,
        };
    }
}
