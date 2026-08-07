<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Money;
use Germa\Support\Period;
use Germa\Support\Settings;

/**
 * Project discipline. The card exists to answer one question the brief states
 * outright: which projects actually produce money, and which only consume time.
 *
 * Project revenue is read from the ledger, never from a column on the project.
 * A stored total is a second source of truth that drifts the moment a payment
 * is logged without anyone remembering to update the project, which had
 * already happened in the demo data, by 1.6M. Attribute a receipt to a project
 * and it counts here; that is the only way it counts.
 */
final class ProjectService
{
    /**
     * Only receipts in the project's own currency are summed. Adding UGX to USD
     * would invent a total no exchange rate justifies. Money refuses to do it,
     * so the SQL refuses first rather than quietly producing a number.
     */
    private const REVENUE_SQL = "COALESCE((
        SELECT SUM(l.amount_minor) FROM ledger_entries l
        WHERE l.project_id = p.id AND l.direction = 'in' AND l.currency = p.currency
    ), 0)";

    /**
     * Every live project, with hours for the requested window and for all time.
     *
     * @return list<array<string,mixed>>
     */
    public function portfolio(string $period = 'month'): array
    {
        [$from, $to] = Period::window($period);

        $rows = Database::all(
            'SELECT p.*,
                    ' . self::REVENUE_SQL . ' AS revenue_received,
                    COALESCE(w.minutes, 0)    AS minutes_window,
                    COALESCE(a.minutes, 0)    AS minutes_total,
                    COALESCE(r.minutes, 0)    AS minutes_14d,
                    a.last_logged_on
             FROM projects p
             LEFT JOIN (
                 SELECT project_id, SUM(minutes) AS minutes
                 FROM project_time_logs WHERE logged_on BETWEEN :from AND :to GROUP BY project_id
             ) w ON w.project_id = p.id
             LEFT JOIN (
                 SELECT project_id, SUM(minutes) AS minutes, MAX(logged_on) AS last_logged_on
                 FROM project_time_logs GROUP BY project_id
             ) a ON a.project_id = p.id
             LEFT JOIN (
                 SELECT project_id, SUM(minutes) AS minutes
                 FROM project_time_logs WHERE logged_on >= :since GROUP BY project_id
             ) r ON r.project_id = p.id
             WHERE p.archived = 0
             ORDER BY ' . self::REVENUE_SQL . ' DESC,
                      FIELD(p.priority, :high, :medium, :low),
                      p.name',
            [
                'from'   => $from,
                'to'     => $to,
                'since'  => Clock::daysAgo(14),
                'high'   => 'HIGH',
                'medium' => 'MEDIUM',
                'low'    => 'LOW',
            ]
        );

        $capHours = Settings::int('non_revenue_hours_cap', 10);
        $projects = [];

        foreach ($rows as $row) {
            $revenue       = Money::of((int) $row['revenue_received'], (string) $row['currency']);
            $milestoneDays = $row['next_milestone_on'] === null
                ? null
                : Clock::daysUntil((string) $row['next_milestone_on']);

            // A project is at risk when it consumes real time, returns nothing,
            // and has no milestone close enough to justify the spend. A distant
            // milestone is not a defence, it is a way of never being wrong yet.
            $hours14d = ((int) $row['minutes_14d']) / 60;
            $atRisk   = $revenue->isZero()
                && $hours14d >= $capHours
                && ($milestoneDays === null || $milestoneDays > 30);

            $reviewDays = $row['next_review_on'] === null
                ? null
                : Clock::daysUntil((string) $row['next_review_on']);

            $projects[] = [
                'id'                => (int) $row['id'],
                'name'              => (string) $row['name'],
                'status'            => (string) $row['status'],
                'status_label'      => $this->statusLabel((string) $row['status']),
                'priority'          => (string) $row['priority'],
                'decision'          => (string) $row['decision'],
                'decided_on'        => $row['decided_on'] ?? null,
                // A verdict recorded recently was made with the current
                // evidence in view, so it stands on its own authority.
                'decision_fresh'    => $row['decided_on'] !== null
                    && Clock::daysUntil((string) $row['decided_on']) > -14,
                'progress_pct'      => (int) $row['progress_pct'],
                'revenue'           => $revenue,
                'earns_revenue'     => !$revenue->isZero(),
                'hours_window'      => round(((int) $row['minutes_window']) / 60, 1),
                'hours_total'       => round(((int) $row['minutes_total']) / 60, 1),
                'hours_14d'         => round($hours14d, 1),
                'last_logged_on'    => $row['last_logged_on'] ?? null,
                'at_risk'           => $atRisk,
                'next_milestone'    => $row['next_milestone'] ?? null,
                'next_milestone_on' => $row['next_milestone_on'] ?? null,
                'milestone_days'    => $milestoneDays,
                'milestone_label'   => $this->milestoneLabel($row['next_milestone'] ?? null, $row['next_milestone_on'] ?? null),
                'next_review_on'    => $row['next_review_on'] ?? null,
                'review_days'       => $reviewDays,
                'review_due'        => $reviewDays !== null && $reviewDays <= 0,
                'risk_note'         => $row['risk_note'] ?? null,
                'strategic_value'   => $row['strategic_value'] ?? null,
                'customer'          => $row['customer'] ?? null,
            ];
        }

        return $projects;
    }

    public function revenueProducingCount(): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM projects p WHERE p.archived = 0 AND ' . self::REVENUE_SQL . ' > 0',
            [],
            0
        );
    }

    public function activeCount(): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM projects WHERE archived = 0', [], 0);
    }

    /**
     * Hours logged per classification inside the window. This is the capital
     * allocation question the CFO keeps asking, not what you meant to work on,
     * what the clock says you worked on.
     *
     * @return array{rows:list<array<string,mixed>>,total_hours:float}
     */
    public function allocationByClass(string $period = 'month'): array
    {
        [$from, $to] = Period::window($period);

        $rows = Database::all(
            'SELECT p.status,
                    COUNT(DISTINCT p.id)       AS projects,
                    COALESCE(SUM(l.minutes),0) AS minutes
             FROM projects p
             LEFT JOIN project_time_logs l
                    ON l.project_id = p.id AND l.logged_on BETWEEN :from AND :to
             WHERE p.archived = 0
             GROUP BY p.status',
            ['from' => $from, 'to' => $to]
        );

        $byStatus = [];
        $total    = 0;

        foreach ($rows as $row) {
            $byStatus[(string) $row['status']] = [
                'projects' => (int) $row['projects'],
                'minutes'  => (int) $row['minutes'],
            ];
            $total += (int) $row['minutes'];
        }

        $out = [];

        // Fixed order, so the eye lands on revenue work first and a 0% there
        // reads as the finding it is.
        foreach (['revenue', 'strategic', 'validation', 'development', 'paused'] as $status) {
            if (!isset($byStatus[$status])) {
                continue;
            }

            $minutes = $byStatus[$status]['minutes'];

            $out[] = [
                'status'   => $status,
                'label'    => $this->statusLabel($status),
                'projects' => $byStatus[$status]['projects'],
                'hours'    => round($minutes / 60, 1),
                'pct'      => $total === 0 ? 0 : (int) round(($minutes / $total) * 100),
                'is_lead'  => $status === 'revenue',
            ];
        }

        return ['rows' => $out, 'total_hours' => round($total / 60, 1)];
    }

    /** Total hours logged inside the window, across every live project. */
    public function hoursInWindow(string $period = 'month'): float
    {
        [$from, $to] = Period::window($period);

        $minutes = (int) Database::value(
            'SELECT COALESCE(SUM(l.minutes), 0) FROM project_time_logs l
             JOIN projects p ON p.id = l.project_id
             WHERE p.archived = 0 AND l.logged_on BETWEEN :from AND :to',
            ['from' => $from, 'to' => $to],
            0
        );

        return round($minutes / 60, 1);
    }

    /**
     * Projects burning time with nothing to show. This is the evidence behind
     * the Critic's standing objection: "you are coding to avoid selling."
     *
     * @return list<array<string,mixed>>
     */
    public function timeSinksWithoutRevenue(): array
    {
        $capHours = Settings::int('non_revenue_hours_cap', 10);

        $rows = Database::all(
            'SELECT p.id, p.name, p.next_milestone_on, COALESCE(SUM(l.minutes), 0) AS minutes
             FROM projects p
             JOIN project_time_logs l ON l.project_id = p.id AND l.logged_on >= :since
             WHERE p.archived = 0 AND ' . self::REVENUE_SQL . ' = 0
             GROUP BY p.id, p.name, p.next_milestone_on
             HAVING minutes >= :cap
             ORDER BY minutes DESC',
            ['since' => Clock::daysAgo(14), 'cap' => $capHours * 60]
        );

        $sinks = [];

        foreach ($rows as $row) {
            // A near-term milestone is a defence: the time is buying something.
            $milestoneDays = $row['next_milestone_on'] === null
                ? null
                : Clock::daysUntil((string) $row['next_milestone_on']);

            $sinks[] = [
                'id'             => (int) $row['id'],
                'name'           => (string) $row['name'],
                'hours'          => round(((int) $row['minutes']) / 60, 1),
                'milestone_days' => $milestoneDays,
                'defensible'     => $milestoneDays !== null && $milestoneDays <= 30,
            ];
        }

        return $sinks;
    }

    /** Minutes logged today, split by whether the project earns anything. */
    public function todayFocusSplit(): array
    {
        $row = Database::first(
            'SELECT
               COALESCE(SUM(CASE WHEN ' . self::REVENUE_SQL . ' > 0 THEN l.minutes ELSE 0 END), 0) AS revenue_minutes,
               COALESCE(SUM(l.minutes), 0) AS total_minutes
             FROM project_time_logs l
             JOIN projects p ON p.id = l.project_id
             WHERE l.logged_on = :today',
            ['today' => Clock::todayString()]
        );

        return [
            'revenue_minutes' => (int) ($row['revenue_minutes'] ?? 0),
            'total_minutes'   => (int) ($row['total_minutes'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> Projects overdue a CONTINUE/PAUSE/PIVOT/KILL call. */
    public function awaitingReview(): array
    {
        return Database::all(
            'SELECT id, name, next_review_on FROM projects
             WHERE archived = 0 AND next_review_on IS NOT NULL AND next_review_on <= :today
             ORDER BY next_review_on',
            ['today' => Clock::todayString()]
        );
    }

    /** @return list<array{id:int,name:string}> For the pickers that need a project. */
    public function options(): array
    {
        $rows = Database::all('SELECT id, name FROM projects WHERE archived = 0 ORDER BY name');

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $rows
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'revenue'     => 'Revenue-generating',
            'strategic'   => 'Strategic',
            'validation'  => 'Validation',
            'development' => 'Development',
            'paused'      => 'Paused',
            default       => ucfirst($status),
        };
    }

    private function milestoneLabel(?string $milestone, ?string $date): ?string
    {
        if ($milestone === null || $milestone === '') {
            return null;
        }
        if ($date === null) {
            return $milestone;
        }

        return $milestone . ' ' . (new \DateTimeImmutable($date, Clock::zone()))->format('j M');
    }
}
