<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Money;

/**
 * Goals are the one thing on the dashboard Gerald committed to deliberately, so
 * they are labelled "Committed" rather than calculated or AI-proposed. Progress
 * is derived from current_value against target_value never typed in directly.
 */
final class GoalService
{
    private const HORIZON_LABELS = [
        '30d'  => '30 days',
        '90d'  => '90 days',
        '1y'   => '1 year',
        'long' => 'Long term',
    ];

    /** @return list<array<string,mixed>> */
    public function active(): array
    {
        $rows = Database::all(
            "SELECT * FROM goals WHERE status = 'active'
             ORDER BY sort_order, FIELD(horizon, '30d', '90d', '1y', 'long')"
        );

        $goals = [];

        foreach ($rows as $row) {
            $target  = (int) $row['target_value'];
            $current = (int) $row['current_value'];
            $pct     = $target > 0 ? min(100.0, ($current / $target) * 100) : 0.0;

            $goals[] = [
                'id'            => (int) $row['id'],
                'horizon'       => (string) $row['horizon'],
                'horizon_label' => self::HORIZON_LABELS[(string) $row['horizon']] ?? (string) $row['horizon'],
                'title'         => (string) $row['title'],
                'metric'        => (string) $row['metric'],
                'progress_pct'  => $pct,
                'progress_label' => $this->progressLabel($pct),
                'detail'        => $this->detail($row, $current, $target),
                'days_left'     => $row['target_on'] === null ? null : Clock::daysUntil((string) $row['target_on']),
            ];
        }

        return $goals;
    }

    /**
     * Refreshes money goals from the ledger so progress reflects reality rather
     * than an intention. Called on dashboard load.
     */
    public function refreshProgress(FinanceService $finance, DebtService $debt): void
    {
        foreach (Database::all("SELECT * FROM goals WHERE status = 'active' AND metric = 'money'") as $row) {
            $goalId = (int) $row['id'];
            $start  = $row['start_on'] ?? Clock::monthStart();

            $current = match ((string) $row['horizon']) {
                // Income goals measure money actually received in the window.
                '30d', '90d' => $finance->incomeBetween((string) $start, Clock::todayString())->minor,
                // The one-year goal is debt elimination: progress is what's repaid.
                '1y'         => $debt->summary()['repaid']->minor,
                default      => (int) $row['current_value'],
            };

            if ($current !== (int) $row['current_value']) {
                Database::update('goals', ['current_value' => $current], ['id' => $goalId]);
            }
        }
    }

    private function progressLabel(float $pct): string
    {
        // Sub-1% progress reads as "0%" and looks like a bug; say so instead.
        if ($pct > 0 && $pct < 1) {
            return '<1%';
        }
        return round($pct) . '%';
    }

    /** @param array<string,mixed> $row */
    private function detail(array $row, int $current, int $target): ?string
    {
        if ((string) $row['metric'] !== 'money' || $target <= 0) {
            return null;
        }

        $currency = (string) ($row['currency'] ?? Money::base());

        return Money::of($current, $currency)->compact() . ' of ' . Money::of($target, $currency)->compact();
    }
}
