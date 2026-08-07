<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Provenance;

/**
 * "What has to come in today." The brief asks for this to be derived by the CFO
 * from the survival requirement, bills, debt and working days rather than typed
 * in so it is a pure calculation over the ledger, and it explains itself.
 */
final class IncomeTargetService
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly DebtService $debt,
    ) {}

    /**
     * @return array{
     *   target: Money, earned: Money, shortfall: Money, progress_pct: float,
     *   working_days_left: int, basis: list<string>
     * }
     */
    public function today(): array
    {
        $survival = $this->finance->monthlySurvivalCost();
        $bills    = $this->finance->billsDueBetween(Clock::todayString(), Clock::monthEnd());
        $debtDue  = $this->debt->dueInBaseCurrencyBetween(Clock::todayString(), Clock::monthEnd());
        $earnedMo = $this->finance->incomeThisMonth();
        $cash     = $this->finance->availableCash();
        $daysLeft = Clock::workingDaysLeftInMonth();

        // What the month still has to produce, net of money already in hand.
        $needMinor = $survival->minor
            + $bills->minor
            + $debtDue->minor
            - $earnedMo->minor
            - max($cash->minor, 0);

        $needMinor = max($needMinor, 0);

        $perDay = (int) ceil($needMinor / max($daysLeft, 1));
        $target = Money::of($this->roundUpForReadability($perDay));

        $earned    = $this->finance->incomeToday();
        $shortfall = Money::of(max($target->minor - $earned->minor, 0));
        $progress  = $target->minor > 0 ? min(100.0, ($earned->minor / $target->minor) * 100) : 100.0;

        $basis = [
            'Survival requirement ' . $survival->format(),
            'Bills still due this month ' . $bills->format(),
        ];
        if ($debtDue->minor > 0) {
            $basis[] = 'Debt due this month ' . $debtDue->format();
        }
        if ($earnedMo->minor > 0) {
            $basis[] = 'less ' . $earnedMo->format() . ' already earned';
        }
        if ($cash->minor > 0) {
            $basis[] = 'less ' . $cash->format() . ' cash in hand';
        }
        $basis[] = 'over ' . $daysLeft . ' working ' . ($daysLeft === 1 ? 'day' : 'days') . ' left';

        return [
            'target'            => $target,
            'earned'            => $earned,
            'shortfall'         => $shortfall,
            'progress_pct'      => $progress,
            'working_days_left' => $daysLeft,
            'basis'             => $basis,
        ];
    }

    public function targetMetric(): Metric
    {
        $today = $this->today();

        if ($this->finance->monthlySurvivalCost()->isZero()) {
            return Metric::unavailable('Set the monthly survival requirement in Settings');
        }

        return Metric::money($today['target'], Provenance::Calculated, implode(' · ', $today['basis']));
    }

    /**
     * The 24h / 7d / 30d ladder the spec demands in emergency mode.
     *
     * @return array{h24: Money, d7: Money, d30: Money}
     */
    public function emergencyLadder(): array
    {
        $today    = $this->today();
        $daily    = $today['target'];
        $perWeek  = Money::of($daily->minor * min(6, $today['working_days_left']));
        $survival = $this->finance->monthlySurvivalCost();
        $earned   = $this->finance->incomeThisMonth();

        return [
            'h24' => $daily,
            'd7'  => $perWeek,
            'd30' => Money::of(max($survival->minor - $earned->minor, 0)),
        ];
    }

    /** UGX targets read better rounded; never round below the true requirement. */
    private function roundUpForReadability(int $minor): int
    {
        if ($minor <= 0) {
            return 0;
        }
        $step = Money::decimals(Money::base()) === 0 ? 1000 : 100;
        return (int) (ceil($minor / $step) * $step);
    }
}
