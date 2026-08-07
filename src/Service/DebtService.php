<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Provenance;
use Germa\Support\Settings;

final class DebtService
{
    /**
     * Aggregate across all active debts. Debts are held in their own currency
     * (the known loan is in USD) and are not silently merged into the base
     * currency only the explicitly converted figures are.
     *
     * @return array{
     *   currency:string, principal:Money, repaid:Money, remaining:Money,
     *   repaid_pct:float, next_payment:?array{amount:Money,due_on:string,days:int},
     *   target_payoff_on:?string, count:int
     * }
     */
    public function summary(): array
    {
        $debts = Database::all("SELECT * FROM debts WHERE status = 'active' ORDER BY id");

        if ($debts === []) {
            return [
                'currency'         => 'USD',
                'principal'        => Money::of(0, 'USD'),
                'repaid'           => Money::of(0, 'USD'),
                'remaining'        => Money::of(0, 'USD'),
                'repaid_pct'       => 0.0,
                'next_payment'     => null,
                'target_payoff_on' => null,
                'count'            => 0,
            ];
        }

        // Report in the currency the largest debt is denominated in.
        $currency  = (string) $debts[0]['currency'];
        $principal = 0;
        foreach ($debts as $debt) {
            if ((string) $debt['currency'] === $currency) {
                $principal += (int) $debt['principal_minor'];
            }
        }

        $repaid = (int) Database::value(
            "SELECT COALESCE(SUM(p.amount_minor), 0)
             FROM debt_payments p
             JOIN debts d ON d.id = p.debt_id
             WHERE p.status = 'paid' AND d.status = 'active' AND p.currency = :c",
            ['c' => $currency],
            0
        );

        $remaining = max($principal - $repaid, 0);

        $next = Database::first(
            "SELECT p.amount_minor, p.currency, p.due_on
             FROM debt_payments p
             JOIN debts d ON d.id = p.debt_id
             WHERE p.status = 'scheduled' AND d.status = 'active' AND p.due_on IS NOT NULL
             ORDER BY p.due_on
             LIMIT 1"
        );

        $payoff = Database::value(
            "SELECT MIN(target_payoff_on) FROM debts WHERE status = 'active' AND target_payoff_on IS NOT NULL"
        );

        return [
            'currency'         => $currency,
            'principal'        => Money::of($principal, $currency),
            'repaid'           => Money::of($repaid, $currency),
            'remaining'        => Money::of($remaining, $currency),
            'repaid_pct'       => $principal > 0 ? ($repaid / $principal) * 100 : 0.0,
            'next_payment'     => $next === null ? null : [
                'amount' => Money::of((int) $next['amount_minor'], (string) $next['currency']),
                'due_on' => (string) $next['due_on'],
                'days'   => Clock::daysUntil((string) $next['due_on']),
            ],
            'target_payoff_on' => $payoff === null ? null : (string) $payoff,
            'count'            => count($debts),
        ];
    }

    public function totalMetric(): Metric
    {
        $summary = $this->summary();

        if ($summary['count'] === 0) {
            return Metric::text('None', Provenance::Entered, 'No active debts recorded');
        }

        return Metric::money(
            $summary['principal'],
            Provenance::Entered,
            $summary['count'] === 1 ? 'One active debt' : $summary['count'] . ' active debts'
        );
    }

    public function nextPaymentMetric(): Metric
    {
        $next = $this->summary()['next_payment'];

        if ($next === null) {
            return Metric::unavailable('No payment scheduled');
        }

        $when = (new \DateTimeImmutable($next['due_on'], Clock::zone()))->format('j M');
        $days = $next['days'];

        $note = match (true) {
            $days < 0  => abs($days) . ' days overdue',
            $days === 0 => 'due today',
            default     => 'in ' . $days . ' days',
        };

        return Metric::text($next['amount']->format() . ' · ' . $when, Provenance::Entered, $note);
    }

    /**
     * Debt payments falling due in a window, converted to the base currency so
     * the income target can include them. Conversion makes this an estimate.
     */
    public function dueInBaseCurrencyBetween(string $from, string $to): Money
    {
        $rows = Database::all(
            "SELECT p.amount_minor, p.currency
             FROM debt_payments p
             JOIN debts d ON d.id = p.debt_id
             WHERE p.status = 'scheduled' AND d.status = 'active'
               AND p.due_on BETWEEN :from AND :to",
            ['from' => $from, 'to' => $to]
        );

        $rate  = Settings::float('usd_ugx_rate', 0.0);
        $total = Money::zero();

        foreach ($rows as $row) {
            $amount = Money::of((int) $row['amount_minor'], (string) $row['currency']);

            if ($amount->currency !== Money::base()) {
                if ($rate <= 0.0) {
                    continue; // No rate: leave it out rather than guess.
                }
                $amount = $amount->convertTo(Money::base(), $rate);
            }

            $total = $total->plus($amount);
        }

        return $total;
    }

    /** @return list<array<string,mixed>> */
    public function upcomingPayments(int $withinDays = 14): array
    {
        return Database::all(
            "SELECT p.id, p.amount_minor, p.currency, p.due_on, d.name
             FROM debt_payments p
             JOIN debts d ON d.id = p.debt_id
             WHERE p.status = 'scheduled' AND d.status = 'active'
               AND p.due_on BETWEEN :today AND :until
             ORDER BY p.due_on",
            ['today' => Clock::todayString(), 'until' => Clock::today()->modify("+$withinDays days")->format('Y-m-d')]
        );
    }
}
