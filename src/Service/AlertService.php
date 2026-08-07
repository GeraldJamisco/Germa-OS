<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Money;
use Germa\Support\Settings;

/**
 * The Risk Director. Each rule is a pure function of recorded data returning
 * either nothing or one alert with a severity, an explanation and a recommended
 * action the three things the brief requires every alert to carry.
 *
 * Rules that stop firing resolve themselves, so the list never accumulates
 * stale warnings.
 */
final class AlertService
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly DebtService $debt,
        private readonly PipelineService $pipeline,
        private readonly ProjectService $projects,
    ) {}

    /**
     * Runs every rule, syncs the alerts table, and returns what is open.
     *
     * @return list<array<string,mixed>>
     */
    public function evaluate(): array
    {
        $fired = array_values(array_filter([
            $this->cashRunway(),
            $this->debtPaymentApproaching(),
            $this->billsUnfunded(),
            $this->followUpsOverdue(),
            $this->timeWithoutRevenue(),
            $this->monthlyIncomeBehind(),
            $this->pipelineStarved(),
            $this->projectsAwaitingReview(),
        ]));

        $this->sync($fired);

        return $this->open();
    }

    /** @return list<array<string,mixed>> */
    public function open(): array
    {
        $rows = Database::all(
            "SELECT * FROM alerts WHERE status = 'open'
             ORDER BY FIELD(severity, 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW'), last_seen_at DESC"
        );

        return array_map(static fn(array $row): array => [
            'id'           => (int) $row['id'],
            'code'         => (string) $row['code'],
            'title'        => (string) $row['title'],
            'body'         => (string) $row['body'],
            'severity'     => (string) $row['severity'],
            'action_label' => $row['action_label'] ?? null,
            'action_url'   => $row['action_url'] ?? null,
            'source'       => (string) $row['source'],
        ], $rows);
    }

    public function dismiss(int $alertId): void
    {
        $alert = Database::first('SELECT code FROM alerts WHERE id = :id', ['id' => $alertId]);
        if ($alert === null) {
            return;
        }

        Database::transaction(static function () use ($alert, $alertId): void {
            // The (code, status) uniqueness means an older dismissed row has to
            // give way to this one.
            Database::run(
                "DELETE FROM alerts WHERE code = :code AND status = 'dismissed' AND id <> :id",
                ['code' => $alert['code'], 'id' => $alertId]
            );
            Database::update('alerts', ['status' => 'dismissed', 'resolved_at' => Clock::now()->format('Y-m-d H:i:s')], ['id' => $alertId]);
        });
    }

    // ── Rules ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function cashRunway(): ?array
    {
        $days = $this->finance->runwayDays();

        if ($days === null || $days >= Settings::int('runway_warning_days', 21)) {
            return null;
        }

        $critical = Settings::int('runway_critical_days', 7);
        $cash     = $this->finance->availableCash();

        return [
            'code'         => 'cash_runway_low',
            'title'        => $days < $critical ? 'Cash runway critically low' : 'Cash runway is thin',
            'body'         => sprintf(
                'Available cash is %s against a %s monthly requirement. That is %d day%s of runway.',
                $cash->format(),
                $this->finance->monthlySurvivalCost()->format(),
                $days,
                $days === 1 ? '' : 's'
            ),
            'severity'     => $days < $critical ? 'CRITICAL' : 'HIGH',
            'action_label' => 'Open income plan',
            'action_url'   => 'income',
        ];
    }

    /** @return array<string,mixed>|null */
    private function debtPaymentApproaching(): ?array
    {
        $payments = $this->debt->upcomingPayments(14);

        if ($payments === []) {
            return null;
        }

        $next    = $payments[0];
        $amount  = Money::of((int) $next['amount_minor'], (string) $next['currency']);
        $days    = Clock::daysUntil((string) $next['due_on']);
        $when    = (new \DateTimeImmutable((string) $next['due_on'], Clock::zone()))->format('j F');

        // "Unfunded" is the part that matters a scheduled payment with money
        // behind it is not a risk.
        $rate      = Settings::float('usd_ugx_rate', 0.0);
        $inBase    = $amount->currency === Money::base() || $rate <= 0.0
            ? $amount
            : $amount->convertTo(Money::base(), $rate);
        $covered   = $amount->currency === Money::base() || $rate > 0.0
            ? $this->finance->availableCash()->minor >= $inBase->minor
            : true;

        if ($covered && $days > 7) {
            return null;
        }

        return [
            'code'         => 'debt_payment_due',
            'title'        => 'Debt payment approaching',
            'body'         => sprintf(
                '%s due %s (%d day%s)%s.',
                $amount->format(),
                $when,
                $days,
                $days === 1 ? '' : 's',
                $covered ? '' : ' with no funds currently identified'
            ),
            'severity'     => $covered ? 'MEDIUM' : 'HIGH',
            'action_label' => 'Schedule payment',
            'action_url'   => 'debt',
        ];
    }

    /** @return array<string,mixed>|null */
    private function billsUnfunded(): ?array
    {
        $window = Clock::today()->modify('+7 days')->format('Y-m-d');
        $due    = $this->finance->billsDueBetween(Clock::todayString(), $window);
        $cash   = $this->finance->availableCash();

        if ($due->isZero() || $cash->minor >= $due->minor) {
            return null;
        }

        $gap = Money::of($due->minor - max($cash->minor, 0));

        return [
            'code'         => 'bills_unfunded',
            'title'        => 'Bills due exceed available cash',
            'body'         => sprintf(
                '%s of bills fall due in the next 7 days against %s in hand a %s gap.',
                $due->format(),
                $cash->format(),
                $gap->format()
            ),
            'severity'     => 'HIGH',
            'action_label' => 'Review bills',
            'action_url'   => 'expenses',
        ];
    }

    /** @return array<string,mixed>|null */
    private function followUpsOverdue(): ?array
    {
        $stale = $this->pipeline->staleLeads();

        if ($stale === []) {
            return null;
        }

        $days = Settings::int('followup_stale_days', 6);

        return [
            'code'         => 'followups_overdue',
            'title'        => 'Follow-ups overdue',
            'body'         => sprintf(
                '%d live deal%s with no contact for %d+ days. Conversion decays sharply after the first week.',
                count($stale),
                count($stale) === 1 ? '' : 's',
                $days
            ),
            'severity'     => count($stale) >= 5 ? 'MEDIUM' : 'LOW',
            'action_label' => 'Review leads',
            'action_url'   => 'pipeline',
        ];
    }

    /** @return array<string,mixed>|null */
    private function timeWithoutRevenue(): ?array
    {
        $sinks = array_values(array_filter(
            $this->projects->timeSinksWithoutRevenue(),
            static fn(array $sink): bool => !$sink['defensible']
        ));

        if ($sinks === []) {
            return null;
        }

        $worst = $sinks[0];

        return [
            'code'         => 'project_time_no_revenue',
            'title'        => $worst['name'] . ' is consuming time without revenue',
            'body'         => sprintf(
                '%.1f hours logged in 14 days with no revenue and no milestone inside 30 days.',
                $worst['hours']
            ),
            'severity'     => 'MEDIUM',
            'action_label' => 'Reassess priority',
            'action_url'   => 'projects',
        ];
    }

    /** @return array<string,mixed>|null */
    private function monthlyIncomeBehind(): ?array
    {
        $survival = $this->finance->monthlySurvivalCost();

        if ($survival->isZero()) {
            return null;
        }

        $earned    = $this->finance->incomeThisMonth();
        $elapsed   = Clock::workingDaysInMonth() - Clock::workingDaysLeftInMonth() + 1;
        $expected  = (int) round($survival->minor * ($elapsed / max(Clock::workingDaysInMonth(), 1)));

        // Only complain once the month is far enough in to mean something.
        if ($elapsed < 5 || $earned->minor >= $expected * 0.6) {
            return null;
        }

        return [
            'code'         => 'income_behind_pace',
            'title'        => 'Monthly income below the pace needed',
            'body'         => sprintf(
                '%s earned this month against %s needed by this point to reach %s.',
                $earned->format(),
                Money::of($expected)->format(),
                $survival->format()
            ),
            'severity'     => 'HIGH',
            'action_label' => 'Open income plan',
            'action_url'   => 'income',
        ];
    }

    /** @return array<string,mixed>|null */
    private function pipelineStarved(): ?array
    {
        $added  = $this->pipeline->prospectsAddedSince(Clock::daysAgo(7));
        $target = Settings::int('daily_prospect_target', 20);

        // A week's worth of outreach at a quarter of the daily target is the
        // floor before the pipeline is called starved.
        $floor = max((int) round($target * 6 * 0.25), 5);

        if ($added >= $floor) {
            return null;
        }

        return [
            'code'         => 'pipeline_starved',
            'title'        => 'Not enough new prospects entering the pipeline',
            'body'         => sprintf(
                'Only %d new prospect%s in the last 7 days, against a %d/day target. Today\'s revenue comes from last week\'s outreach.',
                $added,
                $added === 1 ? '' : 's',
                $target
            ),
            'severity'     => 'MEDIUM',
            'action_label' => 'Add prospects',
            'action_url'   => 'pipeline',
        ];
    }

    /** @return array<string,mixed>|null */
    private function projectsAwaitingReview(): ?array
    {
        $due = $this->projects->awaitingReview();

        if ($due === []) {
            return null;
        }

        $names = implode(', ', array_map(static fn(array $p): string => (string) $p['name'], array_slice($due, 0, 3)));

        return [
            'code'         => 'project_review_due',
            'title'        => 'Projects are overdue a keep-or-kill decision',
            'body'         => sprintf(
                '%s %s awaiting a CONTINUE / PAUSE / PIVOT / KILL call.',
                $names,
                count($due) === 1 ? 'is' : 'are'
            ),
            'severity'     => 'LOW',
            'action_label' => 'Review projects',
            'action_url'   => 'projects',
        ];
    }

    // ── Persistence ─────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $fired */
    private function sync(array $fired): void
    {
        $now       = Clock::now()->format('Y-m-d H:i:s');
        $firedCodes = array_map(static fn(array $a): string => (string) $a['code'], $fired);

        Database::transaction(static function () use ($fired, $firedCodes, $now): void {
            foreach ($fired as $alert) {
                Database::run(
                    // Native prepares do not allow a named placeholder to
                    // repeat, hence the two distinct :now bindings.
                    "INSERT INTO alerts (code, title, body, severity, action_label, action_url, status, source, first_seen_at, last_seen_at)
                     VALUES (:code, :title, :body, :severity, :action_label, :action_url, 'open', 'rules', :first_seen, :last_seen)
                     ON DUPLICATE KEY UPDATE
                       title = VALUES(title),
                       body = VALUES(body),
                       severity = VALUES(severity),
                       action_label = VALUES(action_label),
                       action_url = VALUES(action_url),
                       last_seen_at = VALUES(last_seen_at)",
                    [
                        'code'         => $alert['code'],
                        'title'        => $alert['title'],
                        'body'         => $alert['body'],
                        'severity'     => $alert['severity'],
                        'action_label' => $alert['action_label'] ?? null,
                        'action_url'   => $alert['action_url'] ?? null,
                        'first_seen'   => $now,
                        'last_seen'    => $now,
                    ]
                );
            }

            // Anything rule-generated that no longer fires is resolved, so the
            // card only ever shows live problems.
            if ($firedCodes === []) {
                Database::run(
                    "UPDATE alerts SET status = 'resolved', resolved_at = :now
                     WHERE status = 'open' AND source = 'rules'",
                    ['now' => $now]
                );
                return;
            }

            $placeholders = [];
            $params       = ['now' => $now];
            foreach ($firedCodes as $index => $code) {
                $placeholders[]        = ':code' . $index;
                $params['code' . $index] = $code;
            }

            Database::run(
                "UPDATE alerts SET status = 'resolved', resolved_at = :now
                 WHERE status = 'open' AND source = 'rules'
                   AND code NOT IN (" . implode(', ', $placeholders) . ')',
                $params
            );
        });
    }
}
