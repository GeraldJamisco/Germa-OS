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
 * The Money → Savings screen.
 *
 * Reserves are step 5 of the decision hierarchy, after survival, income and
 * debt, and before anything speculative. So this screen measures one thing
 * honestly: how many months of household survival are actually funded.
 *
 * Savings live apart from the ledger. A deposit moves money Gerald has already
 * counted into a pot he does not spend from; it is neither income nor
 * expenditure, and available cash stays anchored to what he can reach.
 */
final class SavingsService
{
    public const PERIODS = ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    private readonly FinanceService $finance;

    public function __construct(?FinanceService $finance = null)
    {
        $this->finance = $finance ?? new FinanceService();
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'month'): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'month';
        [$from, $to] = $this->window($period);

        $goals   = $this->goals();
        $trend   = $this->trend();
        $balance = $this->totalSaved();

        return [
            'period'       => $period,
            'period_label' => self::PERIODS[$period],
            'periods'      => $this->periodOptions($period),
            'range_label'  => $this->rangeLabel($period, $from, $to),
            'balance'      => $this->balanceCard($balance, $goals),
            'summary'      => $this->summaryTiles($period, $from, $to, $goals),
            'insight'      => $this->insight($balance, $goals),
            'trend'        => $trend,
            'goals'        => $goals,
            'activity'     => $this->activity(),
            'facts'        => $this->facts($trend, $balance),
            'suggestions'  => [
                "I want to start putting something aside",
                "I don't know if I should save or clear the debt first",
                "I'm worried about having no buffer",
                "I want three months of cover",
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

    // ── Balances ────────────────────────────────────────────────────────────

    /**
     * Public because the Household screen states cover in months, which needs the
     * reserve added to cash. A second copy of this sum would be a second answer.
     */
    public function totalSaved(): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0)
             FROM savings_entries WHERE currency = :c",
            ['c' => Money::base()],
            0
        );

        return Money::of(max($minor, 0));
    }

    private function savedBetween(string $from, string $to): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0)
             FROM savings_entries
             WHERE currency = :c AND occurred_on BETWEEN :from AND :to",
            ['c' => Money::base(), 'from' => $from, 'to' => $to],
            0
        );

        return Money::of($minor);
    }

    /** Three months of household survival, the reserve the spec asks for. */
    private function emergencyTarget(int $monthsCover = 3): Money
    {
        return Money::of($this->finance->monthlySurvivalCost()->minor * max($monthsCover, 1));
    }

    /**
     * @param array{rows:list<array<string,mixed>>,total_target:Money} $goals
     * @return array<string,mixed>
     */
    private function balanceCard(Money $balance, array $goals): array
    {
        $target = $goals['total_target'];
        $pct    = $target->minor > 0 ? ($balance->minor / $target->minor) * 100 : 0.0;

        return [
            'metric'       => Metric::money($balance, Provenance::Entered, 'Deposits less withdrawals'),
            'balance'      => $balance,
            'target'       => $target,
            'progress_pct' => $pct,
            'has_target'   => $target->minor > 0,
        ];
    }

    /**
     * @param array{rows:list<array<string,mixed>>} $goals
     * @return list<array<string,mixed>>
     */
    private function summaryTiles(string $period, string $from, string $to, array $goals): array
    {
        $saved     = $this->savedBetween($from, $to);
        $emergency = $this->emergencyProgress($goals);
        $active    = count($goals['rows']);

        return [
            [
                'label'  => 'Saved this ' . strtolower(self::PERIODS[$period]),
                'metric' => Metric::money($saved, Provenance::Entered, 'Deposits less withdrawals in this period'),
                'delta'  => $saved->isZero() ? 'no deposits yet' : 'set aside',
                'tone'   => $saved->isZero() ? 'neutral' : 'good',
            ],
            [
                'label'  => 'Emergency fund',
                'metric' => Metric::number((int) round($emergency['pct']), Provenance::Calculated, 'Against three months of survival costs', '%'),
                'delta'  => 'of ' . $emergency['months_cover'] . '-month target',
                'tone'   => $emergency['pct'] <= 0 ? 'bad' : ($emergency['pct'] >= 100 ? 'good' : 'neutral'),
            ],
            [
                'label'  => 'Active goals',
                'metric' => Metric::number($active, Provenance::Entered, 'Goals still being funded'),
                'delta'  => $active === 1 ? 'in progress' : 'in progress',
                'tone'   => 'neutral',
            ],
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param array{rows:list<array<string,mixed>>} $goals
     * @return array{text:string,provenance:string}
     */
    private function insight(Money $balance, array $goals): array
    {
        $emergency = $this->emergencyProgress($goals);
        $runway    = $this->finance->runwayDays();
        $parts     = [];

        if ($balance->isZero()) {
            $parts[] = 'There is no reserve funded yet.';
        } else {
            $parts[] = sprintf(
                '%s set aside, %s of household survival costs.',
                $balance->format(),
                $this->monthsPhrase($emergency['months_funded'])
            );
        }

        // The hierarchy is the point: a reserve is step 5, and stepping over
        // the earlier ones to fund it would be the wrong call.
        if ($runway !== null && $runway < 7) {
            $parts[] = 'With runway this short, income comes before the reserve: funding savings now would only move the same shortage around.';
        } elseif ($balance->isZero()) {
            $parts[] = sprintf(
                'Once income covers the month, directing a fixed share of every payment into the fund is what gets the first %s in place.',
                $this->emergencyTarget($emergency['months_cover'])->compact()
            );
        } elseif ($emergency['pct'] < 100) {
            $parts[] = sprintf(
                '%s still to go before three months are covered.',
                Money::of(max($emergency['target']->minor - $balance->minor, 0))->format()
            );
        } else {
            $parts[] = 'The emergency fund is fully funded. The next goal can start taking deposits.';
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }

    private function monthsPhrase(float $months): string
    {
        if ($months <= 0) {
            return 'under a month';
        }

        return $months < 1
            ? sprintf('%.1f months', $months)
            : sprintf('%.1f months', $months);
    }

    /**
     * @param array{rows:list<array<string,mixed>>} $goals
     * @return array{pct:float,target:Money,months_cover:int,months_funded:float}
     */
    private function emergencyProgress(array $goals): array
    {
        $monthsCover = 3;
        $saved       = Money::zero();

        foreach ($goals['rows'] as $row) {
            if ($row['kind'] === 'emergency') {
                $monthsCover = $row['months_cover'];
                $saved       = $row['saved'];
                break;
            }
        }

        $target  = $this->emergencyTarget($monthsCover);
        $monthly = $this->finance->monthlySurvivalCost();

        return [
            'pct'           => $target->minor > 0 ? min(100.0, ($saved->minor / $target->minor) * 100) : 0.0,
            'target'        => $target,
            'months_cover'  => $monthsCover,
            'months_funded' => $monthly->minor > 0 ? $saved->minor / $monthly->minor : 0.0,
        ];
    }

    // ── Trend ───────────────────────────────────────────────────────────────

    /**
     * The savings balance at the end of each of the last six months. A flat
     * line is the honest picture of a month with no deposits.
     *
     * @return array<string,mixed>
     */
    private function trend(int $months = 6): array
    {
        $start = Clock::today()->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
        $now   = Clock::today()->format('Y-m');

        // Everything banked before the window is the opening balance.
        $opening = (int) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0)
             FROM savings_entries WHERE currency = :c AND occurred_on < :from",
            ['c' => Money::base(), 'from' => $start->format('Y-m-d')],
            0
        );

        $movement = [];
        foreach (Database::all(
            "SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym,
                    COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0) AS net
             FROM savings_entries
             WHERE currency = :c AND occurred_on >= :from
             GROUP BY ym",
            ['c' => Money::base(), 'from' => $start->format('Y-m-d')]
        ) as $row) {
            $movement[(string) $row['ym']] = (int) $row['net'];
        }

        $series   = [];
        $running  = $opening;
        $max      = 0;
        $deposits = [];

        for ($i = 0; $i < $months; $i++) {
            $month    = $start->modify('+' . $i . ' months');
            $key      = $month->format('Y-m');
            $net      = $movement[$key] ?? 0;
            $running += $net;
            $max      = max($max, $running);

            if ($key !== $now) {
                $deposits[] = max($net, 0);
            }

            $series[] = [
                'key'     => $key,
                'label'   => $month->format('M'),
                'balance' => Money::of(max($running, 0)),
                'net'     => Money::of($net),
                'is_now'  => $key === $now,
            ];
        }

        foreach ($series as $index => $month) {
            $series[$index]['pct'] = $max > 0
                ? max(($month['balance']->minor / $max) * 100, $month['balance']->minor > 0 ? 3 : 0)
                : 0;
        }

        return [
            'months'   => $series,
            'max'      => Money::of($max),
            'average'  => Money::of($deposits === [] ? 0 : (int) round(array_sum($deposits) / count($deposits))),
            'has_data' => $max > 0,
        ];
    }

    /** @param array<string,mixed> $trend */
    private function facts(array $trend, Money $balance): array
    {
        $goals     = $this->goals();
        $emergency = $this->emergencyProgress($goals);
        $funded    = $emergency['months_funded'];

        return [
            [
                'label' => 'Average monthly deposit',
                'value' => $trend['average']->format(),
                'tone'  => null,
            ],
            [
                'label' => 'Emergency fund target',
                'value' => $emergency['target']->isZero()
                    ? 'Set a survival cost first'
                    : $emergency['target']->format(),
                'tone'  => null,
            ],
            [
                'label' => 'Months of runway funded',
                'value' => sprintf('%.1f of %d', $funded, $emergency['months_cover']),
                'tone'  => $funded < 1 ? 'bad' : ($funded >= $emergency['months_cover'] ? 'good' : null),
            ],
        ];
    }

    // ── Goals ───────────────────────────────────────────────────────────────

    /**
     * Savings goals in funding order. The emergency fund's target is derived
     * from the survival cost rather than stored, so it follows the household.
     *
     * @return array{rows:list<array<string,mixed>>,total_target:Money}
     */
    private function goals(): array
    {
        $saved = [];
        foreach (Database::all(
            "SELECT goal_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0) AS net
             FROM savings_entries WHERE goal_id IS NOT NULL GROUP BY goal_id"
        ) as $row) {
            $saved[(int) $row['goal_id']] = (int) $row['net'];
        }

        $rows        = [];
        $totalTarget = 0;

        foreach (Database::all(
            "SELECT * FROM savings_goals WHERE status = 'active' ORDER BY priority, id"
        ) as $index => $goal) {
            $id          = (int) $goal['id'];
            $kind        = (string) $goal['kind'];
            $monthsCover = (int) $goal['months_cover'];

            $target = $kind === 'emergency'
                ? $this->emergencyTarget($monthsCover)
                : Money::of((int) $goal['target_minor'], (string) $goal['currency']);

            $goalSaved    = Money::of(max($saved[$id] ?? 0, 0), $target->currency);
            $totalTarget += $target->minor;

            $rows[] = [
                'id'           => $id,
                'rank'         => sprintf('%02d', $index + 1),
                'name'         => (string) $goal['name'],
                'kind'         => $kind,
                'months_cover' => $monthsCover,
                'saved'        => $goalSaved,
                'target'       => $target,
                'amount_label' => $goalSaved->format() . ' / ' . $target->compact(),
                'pct'          => $target->minor > 0 ? min(100.0, ($goalSaved->minor / $target->minor) * 100) : 0.0,
                'is_priority'  => $kind === 'emergency',
                'status'       => $this->goalStatus($goal, $kind, $monthsCover, $goalSaved, $target),
            ];
        }

        return ['rows' => $rows, 'total_target' => Money::of($totalTarget)];
    }

    /** @param array<string,mixed> $goal */
    private function goalStatus(array $goal, string $kind, int $monthsCover, Money $saved, Money $target): string
    {
        if ($kind === 'emergency') {
            return $target->isZero()
                ? 'Priority · set a monthly survival cost to give this a target'
                : sprintf('Priority · %d months of survival costs', $monthsCover);
        }

        if ($saved->isZero()) {
            return 'Secondary · after the emergency fund';
        }

        return $saved->minor >= $target->minor
            ? 'Funded'
            : sprintf('Secondary · %s still needed', Money::of($target->minor - $saved->minor, $target->currency)->format());
    }

    // ── Activity ────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function activity(int $limit = 6): array
    {
        $rows = Database::all(
            "SELECT e.direction, e.amount_minor, e.currency, e.occurred_on, e.note, g.name AS goal
             FROM savings_entries e
             LEFT JOIN savings_goals g ON g.id = e.goal_id
             ORDER BY e.occurred_on DESC, e.id DESC
             LIMIT " . max(1, $limit)
        );

        $out = [];

        foreach ($rows as $row) {
            $isIn   = (string) $row['direction'] === 'in';
            $amount = Money::of((int) $row['amount_minor'], (string) $row['currency']);
            $when   = new DateTimeImmutable((string) $row['occurred_on'], Clock::zone());

            $out[] = [
                'name'    => $row['note'] ?? ($isIn ? 'Deposit' : 'Withdrawal'),
                'meta'    => ($row['goal'] ?? 'General savings') . ' · ' . $when->format('j M'),
                'amount'  => ($isIn ? '+' : '-') . $amount->format(),
                'is_in'   => $isIn,
                'variant' => $isIn ? 'in' : 'debt',
            ];
        }

        return $out;
    }
}
