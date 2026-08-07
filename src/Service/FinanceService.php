<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Provenance;
use Germa\Support\Settings;

/**
 * The CFO's numbers. Available cash is anchored to the last balance Gerald
 * actually observed, plus ledger movement recorded after it so the headline
 * figure stays a fact he entered rather than an accumulated guess.
 */
final class FinanceService
{
    public const STATUS_CRITICAL = 'CRITICAL';
    public const STATUS_TIGHT    = 'TIGHT';
    public const STATUS_STABLE   = 'STABLE';

    private ?Money $cashCache = null;

    /** @return array{balance:Money,observed_on:?string,note:?string}|null */
    public function latestSnapshot(): ?array
    {
        $row = Database::first(
            'SELECT balance_minor, currency, observed_on, note, ledger_mark
             FROM cash_snapshots
             WHERE currency = :c
             ORDER BY observed_on DESC, id DESC
             LIMIT 1',
            ['c' => Money::base()]
        );

        if ($row === null) {
            return null;
        }

        return [
            'balance'     => Money::of((int) $row['balance_minor'], (string) $row['currency']),
            'observed_on' => (string) $row['observed_on'],
            'note'        => $row['note'] ?? null,
            'ledger_mark' => (int) $row['ledger_mark'],
        ];
    }

    /** Snapshot balance + every ledger entry recorded after it was taken. */
    public function availableCash(): Money
    {
        if ($this->cashCache instanceof Money) {
            return $this->cashCache;
        }

        $snapshot = $this->latestSnapshot();

        if ($snapshot === null) {
            return $this->cashCache = Money::zero();
        }

        $movement = (int) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0)
             FROM ledger_entries
             WHERE currency = :c AND id > :mark",
            ['c' => Money::base(), 'mark' => $snapshot['ledger_mark']],
            0
        );

        return $this->cashCache = $snapshot['balance']->plus(Money::of($movement));
    }

    public function cashMetric(): Metric
    {
        $snapshot = $this->latestSnapshot();

        if ($snapshot === null) {
            return Metric::unavailable('No cash balance recorded yet. Enter one to start the runway clock.');
        }

        $cash = $this->availableCash();
        $note = $snapshot['balance']->minor === $cash->minor
            ? 'As entered on ' . $this->humanDate($snapshot['observed_on'])
            : 'Entered ' . $this->humanDate($snapshot['observed_on']) . ', adjusted for movement since';

        return Metric::money($cash, Provenance::Entered, $note);
    }

    /** Second-currency equivalent. Always an estimate the rate is hand-maintained. */
    public function cashInUsd(): Metric
    {
        $rate = Settings::float('usd_ugx_rate', 0.0);

        if ($rate <= 0.0) {
            return Metric::unavailable('No USD rate set');
        }

        $usd    = $this->availableCash()->convertTo('USD', $rate);
        $setOn  = Settings::get('usd_ugx_rate_set_on');
        $suffix = $setOn === '' ? '' : ', confirmed ' . $this->humanDate($setOn);

        return Metric::money($usd, Provenance::Estimated, 'At UGX ' . number_format($rate) . '/$' . $suffix);
    }

    public function monthlySurvivalCost(): Money
    {
        return Money::of(Settings::int('monthly_survival_minor', 0));
    }

    public function dailySurvivalCost(): Money
    {
        $days = (int) Clock::today()->format('t');
        return Money::of((int) ceil($this->monthlySurvivalCost()->minor / max($days, 1)));
    }

    /**
     * Days the household can continue at the stated survival cost.
     * Null when the survival requirement has not been set an unknown runway
     * is reported as unknown, never as zero.
     */
    public function runwayDays(): ?int
    {
        $daily = $this->dailySurvivalCost();

        if ($daily->minor <= 0) {
            return null;
        }

        $cash = $this->availableCash();

        return $cash->minor <= 0 ? 0 : (int) floor($cash->minor / $daily->minor);
    }

    public function runwayMetric(): Metric
    {
        $days = $this->runwayDays();

        if ($days === null) {
            return Metric::unavailable('Set the monthly survival requirement to compute runway');
        }

        return Metric::number(
            $days,
            Provenance::Calculated,
            'Cash ÷ ' . $this->dailySurvivalCost()->format() . ' per day',
            $days === 1 ? ' day' : ' days'
        );
    }

    public function status(): string
    {
        $days = $this->runwayDays();

        if ($days === null) {
            return self::STATUS_TIGHT;
        }

        return match (true) {
            $days < Settings::int('runway_critical_days', 7) => self::STATUS_CRITICAL,
            $days < Settings::int('runway_warning_days', 21) => self::STATUS_TIGHT,
            default                                          => self::STATUS_STABLE,
        };
    }

    public function incomeBetween(string $from, string $to): Money
    {
        return $this->sumLedger('in', $from, $to);
    }

    public function expensesBetween(string $from, string $to): Money
    {
        return $this->sumLedger('out', $from, $to);
    }

    public function incomeToday(): Money
    {
        $today = Clock::todayString();
        return $this->incomeBetween($today, $today);
    }

    public function incomeThisMonth(): Money
    {
        return $this->incomeBetween(Clock::monthStart(), Clock::monthEnd());
    }

    public function expensesThisMonth(): Money
    {
        return $this->expensesBetween(Clock::monthStart(), Clock::monthEnd());
    }

    /** Non-essential spending in a window the discipline score reads this. */
    public function nonEssentialSpend(string $from, string $to): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM ledger_entries
             WHERE direction = 'out' AND is_essential = 0 AND currency = :c
               AND occurred_on BETWEEN :from AND :to",
            ['c' => Money::base(), 'from' => $from, 'to' => $to],
            0
        );

        return Money::of($minor);
    }

    /** Unpaid bills falling due between two dates. */
    public function billsDueBetween(string $from, string $to): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM bills
             WHERE status = 'due' AND currency = :c AND due_on BETWEEN :from AND :to",
            ['c' => Money::base(), 'from' => $from, 'to' => $to],
            0
        );

        return Money::of($minor);
    }

    /** @return list<array<string,mixed>> */
    public function overdueBills(): array
    {
        return Database::all(
            "SELECT id, name, amount_minor, currency, due_on FROM bills
             WHERE status = 'due' AND due_on < :today ORDER BY due_on",
            ['today' => Clock::todayString()]
        );
    }

    private function sumLedger(string $direction, string $from, string $to): Money
    {
        $minor = (int) Database::value(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM ledger_entries
             WHERE direction = :d AND currency = :c AND occurred_on BETWEEN :from AND :to',
            ['d' => $direction, 'c' => Money::base(), 'from' => $from, 'to' => $to],
            0
        );

        return Money::of($minor);
    }

    private function humanDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return 'an unknown date';
        }

        $stamp = new \DateTimeImmutable($date, Clock::zone());
        $diff  = (int) Clock::today()->diff($stamp->setTime(0, 0))->format('%r%a');

        return match (true) {
            $diff === 0  => 'today',
            $diff === -1 => 'yesterday',
            $diff < 0    => abs($diff) . ' days ago',
            default      => $stamp->format('j M'),
        };
    }
}
