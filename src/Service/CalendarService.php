<?php

declare(strict_types=1);

namespace Germa\Service;

use DateTimeImmutable;
use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Money;

/**
 * Execution → Calendar.
 *
 * Deliberately not a general-purpose diary. Germa OS already knows every date
 * that matters: bills fall due, debt payments are scheduled, tasks have
 * deadlines, projects have milestones and keep-or-kill reviews, so the
 * calendar is a derived view of commitments rather than a second place to type
 * things in. Adding an events table would create a diary that disagrees with
 * the ledger.
 */
final class CalendarService
{
    public const VIEWS = ['week' => 'Week', 'month' => 'Month'];

    public function __construct(
        private readonly FinanceService $finance = new FinanceService(),
        private readonly DebtService $debt = new DebtService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $view = 'week'): array
    {
        $view    = array_key_exists($view, self::VIEWS) ? $view : 'week';
        $horizon = $view === 'week' ? 7 : 31;
        $events  = $this->events($horizon);

        return [
            'view'        => $view,
            'views'       => $this->viewOptions($view),
            'horizon'     => $horizon,
            'strip'       => $this->strip($view, $events),
            'events'      => $events,
            'recurring'   => $this->recurring(),
            'insight'     => $this->insight($events),
            'suggestions' => [
                "I don't think I can cover what's coming",
                "I need to move something",
                "I want to know what's unavoidable this week",
                "I'm not sure what order to pay these in",
                'Call the board',
            ],
        ];
    }

    /** @return list<array{key:string,label:string,active:bool}> */
    private function viewOptions(string $active): array
    {
        $options = [];
        foreach (self::VIEWS as $key => $label) {
            $options[] = ['key' => $key, 'label' => $label, 'active' => $key === $active];
        }
        return $options;
    }

    /**
     * Every dated commitment inside the horizon, from wherever it is recorded.
     *
     * @return list<array<string,mixed>>
     */
    private function events(int $days): array
    {
        $today = Clock::todayString();
        $until = Clock::today()->modify("+$days days")->format('Y-m-d');
        $rows  = [];

        // Bills.
        foreach (Database::all(
            "SELECT id, name, amount_minor, currency, due_on FROM bills
             WHERE status = 'due' AND due_on BETWEEN :from AND :until
             ORDER BY due_on",
            ['from' => Clock::daysAgo(7), 'until' => $until]
        ) as $bill) {
            $rows[] = $this->event(
                (string) $bill['name'] . ' due',
                'Bill · essential',
                (string) $bill['due_on'],
                Money::of((int) $bill['amount_minor'], (string) $bill['currency']),
                'bill'
            );
        }

        // Scheduled debt payments.
        foreach ($this->debt->upcomingPayments($days) as $payment) {
            $rows[] = $this->event(
                'Debt payment: ' . $payment['name'],
                'Repayment',
                (string) $payment['due_on'],
                Money::of((int) $payment['amount_minor'], (string) $payment['currency']),
                'debt'
            );
        }

        // Task deadlines.
        foreach (Database::all(
            "SELECT t.title, COALESCE(t.due_on, t.for_date) AS on_date, t.priority, p.name AS project
             FROM tasks t
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.status = 'open'
               AND COALESCE(t.due_on, t.for_date) BETWEEN :from AND :until
             ORDER BY on_date",
            ['from' => Clock::daysAgo(7), 'until' => $until]
        ) as $task) {
            $rows[] = $this->event(
                (string) $task['title'],
                ($task['project'] ?? 'Task') . ' · ' . strtolower((string) $task['priority']) . ' priority',
                (string) $task['on_date'],
                null,
                'task'
            );
        }

        // Project milestones and keep-or-kill reviews.
        foreach (Database::all(
            "SELECT name, next_milestone, next_milestone_on, next_review_on FROM projects
             WHERE archived = 0",
        ) as $project) {
            $milestone = $project['next_milestone_on'] ?? null;

            if ($milestone !== null && $milestone >= $today && $milestone <= $until) {
                $rows[] = $this->event(
                    (string) $project['name'] . ' · ' . ($project['next_milestone'] ?? 'milestone'),
                    'Project milestone',
                    (string) $milestone,
                    null,
                    'milestone'
                );
            }

            $review = $project['next_review_on'] ?? null;

            if ($review !== null && $review >= $today && $review <= $until) {
                $rows[] = $this->event(
                    (string) $project['name'] . ': keep or kill review',
                    'Project decision due',
                    (string) $review,
                    null,
                    'review'
                );
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);

        return $rows;
    }

    /** @return array<string,mixed> */
    private function event(string $title, string $meta, string $date, ?Money $amount, string $kind): array
    {
        $when  = new DateTimeImmutable($date, Clock::zone());
        $days  = Clock::daysUntil($date);

        return [
            'title'  => $title,
            'meta'   => $meta,
            'date'   => $date,
            'month'  => mb_strtoupper($when->format('M')),
            'day'    => $when->format('j'),
            'days'   => $days,
            'amount' => $amount,
            'kind'   => $kind,
            // Money already committed is what can actually go wrong, so only
            // those escalate; a task deadline is a plan, not an obligation.
            'tone'   => $this->tone($kind, $days),
            'tag'    => $this->tag($kind, $days),
        ];
    }

    private function tone(string $kind, int $days): string
    {
        if (!in_array($kind, ['bill', 'debt'], true)) {
            return $days < 0 ? 'high' : 'calm';
        }

        return match (true) {
            $days <= 3 => 'critical',
            $days <= 7 => 'high',
            default    => 'calm',
        };
    }

    private function tag(string $kind, int $days): string
    {
        if ($days < 0) {
            return 'LATE';
        }

        return match ($kind) {
            'bill', 'debt' => $days <= 3 ? 'CRITICAL' : ($days <= 7 ? 'HIGH' : 'DUE'),
            'milestone'    => 'MILESTONE',
            'review'       => 'DECISION',
            default        => 'TASK',
        };
    }

    /**
     * The week or month strip. Days carry a marker when something falls on
     * them, so the shape of the period is readable before the list is.
     *
     * @param list<array<string,mixed>> $events
     * @return array{days:list<array<string,mixed>>,label:string}
     */
    private function strip(string $view, array $events): array
    {
        $byDate = [];
        foreach ($events as $event) {
            $byDate[$event['date']][] = $event;
        }

        $today = Clock::today();

        if ($view === 'week') {
            $start = $today->modify('monday this week');
            $count = 7;
            $label = $start->format('j M') . ' – ' . $start->modify('+6 days')->format('j M Y');
        } else {
            $start = $today->modify('first day of this month');
            $count = (int) $today->format('t');
            $label = $today->format('F Y');
        }

        $days = [];

        for ($i = 0; $i < $count; $i++) {
            $day  = $start->modify("+$i days");
            $key  = $day->format('Y-m-d');
            $list = $byDate[$key] ?? [];

            // One dot per day, coloured by the most urgent thing on it.
            $tone = 'none';
            foreach ($list as $event) {
                if ($event['tone'] === 'critical') {
                    $tone = 'critical';
                    break;
                }
                $tone = $tone === 'critical' ? $tone : ($event['tone'] === 'high' ? 'high' : ($tone === 'none' ? 'calm' : $tone));
            }

            $days[] = [
                'date'    => $key,
                'dow'     => $day->format('D'),
                'num'     => $day->format('j'),
                'today'   => $key === $today->format('Y-m-d'),
                'past'    => $key < $today->format('Y-m-d'),
                'count'   => count($list),
                'tone'    => $tone,
            ];
        }

        return ['days' => $days, 'label' => $label];
    }

    /**
     * Standing commitments, the ones that will land again whether or not
     * anything is done about them.
     *
     * @return list<array<string,mixed>>
     */
    private function recurring(): array
    {
        $rows = [];

        foreach (Database::all(
            "SELECT name, amount_minor, currency, due_on, recurrence FROM bills
             WHERE status = 'due' AND recurrence <> 'none'
             ORDER BY due_on"
        ) as $bill) {
            $when = new DateTimeImmutable((string) $bill['due_on'], Clock::zone());

            $rows[] = [
                'title'   => (string) $bill['name'],
                'cadence' => $this->cadence((string) $bill['recurrence'], $when),
                'amount'  => Money::of((int) $bill['amount_minor'], (string) $bill['currency']),
            ];
        }

        foreach ($this->debt->upcomingPayments(365) as $payment) {
            $when = new DateTimeImmutable((string) $payment['due_on'], Clock::zone());

            $rows[] = [
                'title'   => (string) $payment['name'] . ' repayment',
                'cadence' => 'Scheduled · ' . $when->format('j M'),
                'amount'  => Money::of((int) $payment['amount_minor'], (string) $payment['currency']),
            ];
        }

        return $rows;
    }

    private function cadence(string $recurrence, DateTimeImmutable $next): string
    {
        $ordinal = $next->format('jS');

        return match ($recurrence) {
            'weekly'    => 'Weekly · ' . $next->format('l'),
            'monthly'   => 'Monthly · ' . $ordinal,
            'quarterly' => 'Quarterly · next ' . $next->format('j M'),
            'yearly'    => 'Yearly · ' . $next->format('j M'),
            default     => 'One-off · ' . $next->format('j M'),
        };
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array{text:string,provenance:string}
     */
    private function insight(array $events): array
    {
        $committed = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['amount'] !== null && $e['days'] >= 0
        ));

        if ($committed === []) {
            return [
                'text'       => 'Nothing is committed in this window. Task deadlines and milestones are plans you control, not obligations.',
                'provenance' => 'calculated',
            ];
        }

        // Only base-currency commitments can be summed honestly, so the
        // sentence names exactly what the total covers and reports the rest
        // separately rather than implying they were counted.
        $total     = Money::zero();
        $counted   = [];
        $uncounted = [];

        foreach ($committed as $event) {
            if ($event['amount']->currency === Money::base()) {
                $total     = $total->plus($event['amount']);
                $counted[] = $event;
            } else {
                $uncounted[] = $event;
            }
        }

        $cash  = $this->finance->availableCash();
        $parts = [];

        if ($counted === []) {
            $parts[] = sprintf('Nothing in %s falls due in this window.', Money::base());
        } else {
            $names = array_slice(array_map(static fn (array $e): string => $e['title'], $counted), 0, 3);

            $parts[] = sprintf(
                '%s of committed money falls in this window: %s%s.',
                $total->format(),
                implode(', ', $names),
                count($counted) > 3 ? ' and ' . (count($counted) - 3) . ' more' : ''
            );
        }

        foreach ($uncounted as $event) {
            $parts[] = sprintf(
                '%s (%s) also falls due and is not included in that total: different currency.',
                $event['title'],
                $event['amount']->format()
            );
        }

        $parts[] = $total->minor > $cash->minor
            ? sprintf(
                'Against %s in hand, that is a %s shortfall: sequence income to cover these dates before anything else.',
                $cash->format(),
                Money::of($total->minor - $cash->minor)->format()
            )
            : sprintf('Cash in hand (%s) covers it.', $cash->format());

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }
}
