<?php

declare(strict_types=1);

namespace Germa\Service;

use DateTimeImmutable;
use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Provenance;

/**
 * The Money → Debt screen.
 *
 * Debt is step 4 of the spec's decision hierarchy, and the only number that
 * matters is whether the balance is actually falling. So the trend is the
 * balance over time rather than payments per month, a month of no payments
 * shows as a flat line, which is the honest picture.
 */
final class DebtOverviewService
{
    public const PERIODS = ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    private readonly DebtService $debt;
    private readonly FinanceService $finance;

    public function __construct(?DebtService $debt = null, ?FinanceService $finance = null)
    {
        $this->debt    = $debt ?? new DebtService();
        $this->finance = $finance ?? new FinanceService();
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'month'): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'month';
        [$from, $to] = $this->window($period);

        $summary     = $this->debt->summary();
        $obligations = $this->obligations();
        $trend       = $this->trend();

        return [
            'period'       => $period,
            'period_label' => self::PERIODS[$period],
            'periods'      => $this->periodOptions($period),
            'range_label'  => $this->rangeLabel($period, $from, $to),
            'balance'      => $this->balanceCard($summary),
            'summary'      => $this->summaryTiles($period, $from, $to, $summary, $obligations),
            'insight'      => $this->insight($summary, $obligations),
            'trend'        => $trend,
            'obligations'  => $obligations,
            'history'      => $this->history(),
            'facts'        => $this->facts($summary),
            'suggestions'  => [
                "I'm not sure I can make the next payment",
                "I want this cleared faster",
                "I don't know whether to pay debt or save first",
                "I'm worried about falling behind",
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

    // ── Balance ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function balanceCard(array $summary): array
    {
        return [
            'metric' => $summary['count'] === 0
                ? Metric::text('Clear', Provenance::Entered, 'No active debts recorded')
                : Metric::money($summary['remaining'], Provenance::Entered, 'Principal less everything repaid'),
            'principal'  => $summary['principal'],
            'repaid'     => $summary['repaid'],
            'remaining'  => $summary['remaining'],
            'repaid_pct' => $summary['repaid_pct'],
            'clear'      => $summary['count'] === 0,
        ];
    }

    /**
     * @param array<string,mixed>                                $summary
     * @param array{rows:list<array<string,mixed>>,count:int}    $obligations
     * @return list<array<string,mixed>>
     */
    private function summaryTiles(string $period, string $from, string $to, array $summary, array $obligations): array
    {
        $repaid = $this->repaidBetween($from, $to, $summary['currency']);
        $next   = $summary['next_payment'];

        return [
            [
                'label'  => 'Repaid this ' . strtolower(self::PERIODS[$period]),
                'metric' => Metric::money($repaid, Provenance::Entered, 'Payments recorded in this period'),
                'delta'  => $summary['count'] === 0
                    ? 'nothing outstanding'
                    : 'of ' . $summary['principal']->format() . ' total',
                'tone'   => $repaid->isZero() ? 'neutral' : 'good',
            ],
            [
                'label'  => 'Next due',
                'metric' => $next === null
                    ? Metric::unavailable('No payment scheduled')
                    : Metric::money($next['amount'], Provenance::Entered, 'The next scheduled payment'),
                'delta'  => $next === null
                    ? 'nothing scheduled'
                    : $this->whenPhrase($next['days']) . ' · ' . (new DateTimeImmutable($next['due_on'], Clock::zone()))->format('j M'),
                'tone'   => $next === null ? 'neutral' : ($next['days'] <= 7 ? 'bad' : 'neutral'),
            ],
            [
                'label'  => 'Open obligations',
                'metric' => Metric::number($obligations['count'], Provenance::Entered, 'Active lenders'),
                'delta'  => $obligations['count'] === 1 ? 'active lender' : 'active lenders',
                'tone'   => $obligations['count'] === 0 ? 'good' : 'neutral',
            ],
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>                             $summary
     * @param array{rows:list<array<string,mixed>>,count:int}  $obligations
     * @return array{text:string,provenance:string}
     */
    private function insight(array $summary, array $obligations): array
    {
        if ($summary['count'] === 0) {
            return [
                'text'       => 'No active debts recorded. Nothing here is claiming future income.',
                'provenance' => 'calculated',
            ];
        }

        $parts = [];
        $next  = $summary['next_payment'];

        if ($next !== null) {
            $covered = $this->canCover($next['amount']);
            $parts[] = sprintf(
                '%s is due %s%s.',
                $next['amount']->format(),
                (new DateTimeImmutable($next['due_on'], Clock::zone()))->format('j F'),
                $covered === false ? ' with no allocated funds identified' : ''
            );
        } else {
            $parts[] = sprintf('%s outstanding with no payment scheduled.', $summary['remaining']->format());
        }

        // A payoff date nobody is moving toward is worth naming as such.
        $paceMonths = $this->monthsAtCurrentPace($summary);

        if ($paceMonths === null) {
            $parts[] = 'Nothing has been repaid yet, so there is no pace to project a payoff from.';
        } else {
            $eta = Clock::today()->modify('+' . $paceMonths . ' months');
            $parts[] = sprintf(
                'At the recent repayment pace the balance clears around %s.',
                $eta->format('F Y')
            );
        }

        if ($summary['target_payoff_on'] !== null) {
            $target = new DateTimeImmutable($summary['target_payoff_on'], Clock::zone());
            $parts[] = sprintf('The target you set is %s.', $target->format('j F Y'));
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }

    /** Null when nothing has been repaid: there is no pace to extrapolate. */
    private function monthsAtCurrentPace(array $summary): ?int
    {
        $sixMonthsAgo = Clock::today()->modify('-6 months')->format('Y-m-d');
        $repaid       = $this->repaidBetween($sixMonthsAgo, Clock::todayString(), $summary['currency']);

        if ($repaid->isZero() || $summary['remaining']->isZero()) {
            return null;
        }

        $perMonth = $repaid->minor / 6;

        return (int) max(1, ceil($summary['remaining']->minor / $perMonth));
    }

    /** Null when the currencies cannot be compared honestly. */
    private function canCover(Money $amount): ?bool
    {
        if ($amount->currency !== Money::base()) {
            $rate = \Germa\Support\Settings::float('usd_ugx_rate', 0.0);
            if ($rate <= 0.0) {
                return null;
            }
            $amount = $amount->convertTo(Money::base(), $rate);
        }

        return $this->finance->availableCash()->minor >= $amount->minor;
    }

    // ── Trend ───────────────────────────────────────────────────────────────

    /**
     * The outstanding balance at the end of each of the last six months,
     * reconstructed by winding payments backwards from today.
     *
     * @return array<string,mixed>
     */
    private function trend(int $months = 6): array
    {
        $summary   = $this->debt->summary();
        $currency  = $summary['currency'];
        $remaining = $summary['remaining']->minor;

        if ($summary['count'] === 0) {
            return ['months' => [], 'max' => Money::of(0, $currency), 'has_data' => false];
        }

        $start = Clock::today()->modify('first day of this month')->modify('-' . ($months - 1) . ' months');

        $paidByMonth = [];
        foreach (Database::all(
            "SELECT DATE_FORMAT(p.paid_on, '%Y-%m') AS ym, COALESCE(SUM(p.amount_minor), 0) AS total
             FROM debt_payments p
             JOIN debts d ON d.id = p.debt_id
             WHERE p.status = 'paid' AND p.currency = :c AND p.paid_on >= :from
             GROUP BY ym",
            ['c' => $currency, 'from' => $start->format('Y-m-d')]
        ) as $row) {
            $paidByMonth[(string) $row['ym']] = (int) $row['total'];
        }

        // Walk backwards from today's balance, adding each month's payments
        // back on to recover what was outstanding before them.
        $series  = [];
        $balance = $remaining;
        $now     = Clock::today()->format('Y-m');

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $start->modify('+' . $i . ' months');
            $key   = $month->format('Y-m');

            array_unshift($series, [
                'key'     => $key,
                'label'   => $month->format('M'),
                'balance' => Money::of($balance, $currency),
                'paid'    => Money::of($paidByMonth[$key] ?? 0, $currency),
                'is_now'  => $key === $now,
            ]);

            $balance += $paidByMonth[$key] ?? 0;
        }

        $max = 0;
        foreach ($series as $month) {
            $max = max($max, $month['balance']->minor);
        }

        foreach ($series as $index => $month) {
            $series[$index]['pct'] = $max > 0
                ? max(($month['balance']->minor / $max) * 100, $month['balance']->minor > 0 ? 3 : 0)
                : 0;
        }

        return ['months' => $series, 'max' => Money::of($max, $currency), 'has_data' => true];
    }

    /** @param array<string,mixed> $summary */
    private function facts(array $summary): array
    {
        $next = $summary['next_payment'];

        return [
            [
                'label' => 'Next payment',
                'value' => $next === null
                    ? 'None'
                    : $next['amount']->format() . ' · ' . (new DateTimeImmutable($next['due_on'], Clock::zone()))->format('j M'),
                'tone'  => $next !== null && $next['days'] <= 7 ? 'bad' : null,
            ],
            [
                'label' => 'Target payoff',
                'value' => $summary['target_payoff_on'] === null
                    ? 'Not set'
                    : (new DateTimeImmutable($summary['target_payoff_on'], Clock::zone()))->format('j M Y'),
                'tone'  => null,
            ],
            [
                'label' => 'Interest accruing',
                'value' => $this->interestNote(),
                'tone'  => null,
            ],
        ];
    }

    /** Interest is a recorded note, not a computed rate: say so rather than guess. */
    private function interestNote(): string
    {
        $notes = Database::all(
            "SELECT interest_note FROM debts WHERE status = 'active' AND interest_note IS NOT NULL AND interest_note <> ''"
        );

        if ($notes === []) {
            return 'None recorded';
        }

        return (string) $notes[0]['interest_note'];
    }

    // ── Obligations ─────────────────────────────────────────────────────────

    /**
     * Each active debt, ranked by what is still owed.
     *
     * @return array{rows:list<array<string,mixed>>,count:int,total:Money}
     */
    private function obligations(): array
    {
        $debts = Database::all("SELECT * FROM debts WHERE status = 'active' ORDER BY principal_minor DESC");

        if ($debts === []) {
            return ['rows' => [], 'count' => 0, 'total' => Money::zero('USD')];
        }

        $repaid = [];
        foreach (Database::all(
            "SELECT debt_id, COALESCE(SUM(amount_minor), 0) AS total
             FROM debt_payments WHERE status = 'paid' GROUP BY debt_id"
        ) as $row) {
            $repaid[(int) $row['debt_id']] = (int) $row['total'];
        }

        $scheduled = [];
        foreach (Database::all(
            "SELECT debt_id, MIN(due_on) AS next_due FROM debt_payments
             WHERE status = 'scheduled' AND due_on IS NOT NULL GROUP BY debt_id"
        ) as $row) {
            $scheduled[(int) $row['debt_id']] = (string) $row['next_due'];
        }

        $maxOutstanding = 0;
        $rows           = [];

        foreach ($debts as $debt) {
            $id          = (int) $debt['id'];
            $currency    = (string) $debt['currency'];
            $outstanding = max((int) $debt['principal_minor'] - ($repaid[$id] ?? 0), 0);
            $maxOutstanding = max($maxOutstanding, $outstanding);

            $rows[] = [
                'id'          => $id,
                'name'        => (string) $debt['name'] . ($debt['lender'] === null ? '' : ' · ' . $debt['lender']),
                'amount'      => Money::of($outstanding, $currency),
                'outstanding' => $outstanding,
                'currency'    => $currency,
                'next_due'    => $scheduled[$id] ?? null,
                'repaid'      => Money::of($repaid[$id] ?? 0, $currency),
            ];
        }

        $total = Money::zero((string) $debts[0]['currency']);

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = sprintf('%02d', $index + 1);
            $rows[$index]['pct']  = $maxOutstanding > 0 ? ($row['outstanding'] / $maxOutstanding) * 100 : 0;
            $rows[$index]['status'] = $this->obligationStatus($row);
            // Whatever is due soonest is the one that can actually hurt.
            $rows[$index]['urgent'] = $row['next_due'] !== null && Clock::daysUntil($row['next_due']) <= 7;

            if ($row['currency'] === $total->currency) {
                $total = $total->plus($row['amount']);
            }
        }

        return ['rows' => $rows, 'count' => count($rows), 'total' => $total];
    }

    /** @param array<string,mixed> $row */
    private function obligationStatus(array $row): string
    {
        if ($row['next_due'] === null) {
            return $row['repaid']->isZero()
                ? 'Active · no payment scheduled, nothing repaid'
                : 'Active · no payment scheduled, ' . $row['repaid']->format() . ' repaid';
        }

        $days = Clock::daysUntil($row['next_due']);
        $when = (new DateTimeImmutable($row['next_due'], Clock::zone()))->format('j M');

        return 'Active · payment due ' . $when . ' (' . $this->whenPhrase($days) . ')';
    }

    private function whenPhrase(int $days): string
    {
        return match (true) {
            $days < 0   => abs($days) . ' days overdue',
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            default     => 'in ' . $days . ' days',
        };
    }

    // ── History ─────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function history(int $limit = 6): array
    {
        $rows = Database::all(
            "SELECT p.amount_minor, p.currency, p.paid_on, p.note, d.name
             FROM debt_payments p
             JOIN debts d ON d.id = p.debt_id
             WHERE p.status = 'paid'
             ORDER BY p.paid_on DESC, p.id DESC
             LIMIT " . max(1, $limit)
        );

        $out = [];

        foreach ($rows as $row) {
            $amount = Money::of((int) $row['amount_minor'], (string) $row['currency']);
            $when   = new DateTimeImmutable((string) $row['paid_on'], Clock::zone());

            $out[] = [
                'name'   => $row['name'] . ' payment',
                'meta'   => $row['note'] ?? $when->format('j M Y'),
                'amount' => '-' . $amount->format(),
            ];
        }

        return $out;
    }

    private function repaidBetween(string $from, string $to, string $currency): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(p.amount_minor), 0)
             FROM debt_payments p
             JOIN debts d ON d.id = p.debt_id
             WHERE p.status = 'paid' AND p.currency = :c
               AND p.paid_on BETWEEN :from AND :to",
            ['c' => $currency, 'from' => $from, 'to' => $to],
            0
        );

        return Money::of($minor, $currency);
    }
}
