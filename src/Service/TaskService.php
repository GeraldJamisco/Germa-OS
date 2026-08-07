<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;

final class TaskService
{
    /**
     * The Top 3 Priorities card. Exactly three slots the spec is emphatic
     * about "only three", so a fourth ranked task cannot sneak in.
     *
     * @return list<array<string,mixed>>
     */
    public function topPriorities(?string $date = null): array
    {
        $date = $date ?? Clock::todayString();

        $rows = Database::all(
            "SELECT t.*, p.name AS project_name
             FROM tasks t
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.for_date = :d AND t.priority_rank IS NOT NULL AND t.status <> 'dropped'
             ORDER BY t.priority_rank
             LIMIT 3",
            ['d' => $date]
        );

        return array_map(fn(array $row): array => $this->present($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function otherTasks(?string $date = null): array
    {
        $date = $date ?? Clock::todayString();

        $rows = Database::all(
            "SELECT t.*, p.name AS project_name
             FROM tasks t
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.for_date = :d AND t.priority_rank IS NULL AND t.status <> 'dropped'
             ORDER BY t.id",
            ['d' => $date]
        );

        return array_map(fn(array $row): array => $this->present($row), $rows);
    }

    /** @return array{planned:int,done:int,top_planned:int,top_done:int} */
    public function todayCounts(?string $date = null): array
    {
        $date = $date ?? Clock::todayString();

        $row = Database::first(
            "SELECT
               COUNT(*) AS planned,
               SUM(status = 'done') AS done,
               SUM(priority_rank IS NOT NULL) AS top_planned,
               SUM(priority_rank IS NOT NULL AND status = 'done') AS top_done
             FROM tasks
             WHERE for_date = :d AND status <> 'dropped'",
            ['d' => $date]
        );

        return [
            'planned'     => (int) ($row['planned'] ?? 0),
            'done'        => (int) ($row['done'] ?? 0),
            'top_planned' => (int) ($row['top_planned'] ?? 0),
            'top_done'    => (int) ($row['top_done'] ?? 0),
        ];
    }

    /** @return array{id:int,status:string,done:bool} */
    public function toggle(int $taskId): array
    {
        $task = Database::first('SELECT id, status FROM tasks WHERE id = :id', ['id' => $taskId]);

        if ($task === null) {
            throw new \RuntimeException('Task not found');
        }

        $done = (string) $task['status'] !== 'done';

        Database::update('tasks', [
            'status'       => $done ? 'done' : 'open',
            'completed_at' => $done ? Clock::now()->format('Y-m-d H:i:s') : null,
        ], ['id' => $taskId]);

        return ['id' => $taskId, 'status' => $done ? 'done' : 'open', 'done' => $done];
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $forDate = (string) ($data['for_date'] ?? Clock::todayString());
        $rank    = $data['priority_rank'] ?? null;

        if ($rank !== null) {
            $rank = max(1, min(3, (int) $rank));
            // Ranks are unique per day; bump whatever held the slot.
            Database::run(
                'UPDATE tasks SET priority_rank = NULL WHERE for_date = :d AND priority_rank = :r',
                ['d' => $forDate, 'r' => $rank]
            );
        }

        return Database::insert('tasks', [
            'title'             => (string) $data['title'],
            'detail'            => $data['detail'] ?? null,
            'project_id'        => $data['project_id'] ?? null,
            'deal_id'           => $data['deal_id'] ?? null,
            'priority_rank'     => $rank,
            'estimated_minutes' => $data['estimated_minutes'] ?? null,
            'expected_outcome'  => $data['expected_outcome'] ?? null,
            'priority'          => in_array($data['priority'] ?? null, ['HIGH', 'MEDIUM', 'LOW'], true)
                ? $data['priority']
                // A ranked task is today's top three, so it is not low priority.
                : ($rank === null ? 'MEDIUM' : ($rank === 1 ? 'HIGH' : 'MEDIUM')),
            'for_date'          => $forDate,
            'due_on'            => $data['due_on'] ?? null,
            'origin'            => $data['origin'] ?? 'user',
        ]);
    }

    /** @param array<string,mixed> $row */
    private function present(array $row): array
    {
        $minutes = $row['estimated_minutes'] === null ? null : (int) $row['estimated_minutes'];

        return [
            'id'               => (int) $row['id'],
            'title'            => (string) $row['title'],
            'rank'             => $row['priority_rank'] === null ? null : (int) $row['priority_rank'],
            'rank_label'       => $row['priority_rank'] === null ? null : sprintf('Priority %02d', (int) $row['priority_rank']),
            'estimate_label'   => $this->duration($minutes),
            'expected_outcome' => $row['expected_outcome'] ?? null,
            'project_name'     => $row['project_name'] ?? null,
            'origin'           => (string) $row['origin'],
            'done'             => (string) $row['status'] === 'done',
            'priority'         => (string) ($row['priority'] ?? 'MEDIUM'),
            'for_date'         => (string) $row['for_date'],
            'due_on'           => $row['due_on'] ?? null,
        ] + $this->dueState($row);
    }

    /**
     * When a task is actually due, and whether that has already passed. The
     * effective date is due_on when set, otherwise the day it was planned for
     * a task planned for Monday and untouched on Wednesday is overdue.
     *
     * @param array<string,mixed> $row
     * @return array{due_label:string,due_state:string,days_late:int}
     */
    private function dueState(array $row): array
    {
        if ((string) $row['status'] === 'done') {
            return ['due_label' => 'Done', 'due_state' => 'done', 'days_late' => 0];
        }

        $effective = (string) ($row['due_on'] ?? $row['for_date']);
        $days      = Clock::daysUntil($effective);

        return match (true) {
            $days < 0 => [
                'due_label'  => 'OVERDUE · ' . abs($days) . 'd',
                'due_state'  => 'overdue',
                'days_late'  => abs($days),
            ],
            $days === 0 => ['due_label' => 'Today', 'due_state' => 'today', 'days_late' => 0],
            $days === 1 => ['due_label' => 'Tomorrow', 'due_state' => 'upcoming', 'days_late' => 0],
            default     => [
                'due_label' => (new \DateTimeImmutable($effective, Clock::zone()))->format('j M'),
                'due_state' => 'upcoming',
                'days_late' => 0,
            ],
        };
    }

    /**
     * Every task that is still live or was finished recently, newest deadline
     * first. Overdue work sorts to the top because it is the only kind that is
     * already costing something.
     *
     * @return list<array<string,mixed>>
     */
    public function all(string $filter = 'all'): array
    {
        $rows = Database::all(
            "SELECT t.*, p.name AS project_name
             FROM tasks t
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.status <> 'dropped'
               AND (t.status = 'open' OR t.completed_at >= :since)
             ORDER BY COALESCE(t.due_on, t.for_date), t.priority_rank IS NULL, t.priority_rank, t.id",
            ['since' => Clock::daysAgo(7) . ' 00:00:00']
        );

        $tasks = array_map(fn (array $row): array => $this->present($row), $rows);

        usort($tasks, static function (array $a, array $b): int {
            $rank = ['overdue' => 0, 'today' => 1, 'upcoming' => 2, 'done' => 3];
            return [$rank[$a['due_state']], $a['due_on'] ?? $a['for_date']]
               <=> [$rank[$b['due_state']], $b['due_on'] ?? $b['for_date']];
        });

        if ($filter === 'all') {
            return $tasks;
        }

        return array_values(array_filter(
            $tasks,
            static fn (array $t): bool => $t['due_state'] === $filter
        ));
    }

    /**
     * Board counts. "Completed this week" is the only one that looks backwards
     *, the rest describe what is still owed.
     *
     * @return array{open:int,overdue:int,today:int,done_this_week:int}
     */
    public function boardCounts(): array
    {
        $tasks = $this->all();
        $counts = ['open' => 0, 'overdue' => 0, 'today' => 0, 'done_this_week' => 0];

        foreach ($tasks as $task) {
            if ($task['done']) {
                $counts['done_this_week']++;
                continue;
            }

            $counts['open']++;

            if ($task['due_state'] === 'overdue') {
                $counts['overdue']++;
            } elseif ($task['due_state'] === 'today') {
                $counts['today']++;
            }
        }

        return $counts;
    }

    private function duration(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }
        if ($minutes < 60) {
            return $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return $rest === 0 ? $hours . 'h' : $hours . 'h ' . $rest . 'm';
    }
}
