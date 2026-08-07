<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Provenance;

/**
 * Goals → Milestones.
 *
 * The middle of the spec's chain, Goal → Milestones → Tasks → Deadline → Metric
 * → Review, gathered from everywhere it already exists: milestones under goals,
 * the next milestone on each project, and the goals' own target dates.
 *
 * Two decisions worth stating. Milestones are shown on one timeline rather than
 * grouped by what they belong to, because time is the constraint they compete
 * for and a project milestone and a goal deadline in the same week are the same
 * problem. And a milestone with no date is not put at the end of the list where
 * it would look distant: it is called out, because an undated milestone is the
 * abstract advice the Operations Director is told never to leave behind.
 */
final class MilestoneService
{
    public const WINDOWS = [
        'open'  => 'Ahead',
        'week'  => 'This week',
        'month' => 'This month',
        'all'   => 'Everything',
    ];

    /** @return array<string,mixed> */
    public function build(string $window = 'open'): array
    {
        $window = array_key_exists($window, self::WINDOWS) ? $window : 'open';
        $all    = $this->collect();

        $overdue = array_values(array_filter($all, static fn (array $m): bool => $m['state'] === 'overdue'));
        $undated = array_values(array_filter($all, static fn (array $m): bool => $m['days'] === null));
        $dated   = array_values(array_filter($all, static fn (array $m): bool => $m['days'] !== null));

        return [
            'window'      => $window,
            'windows'     => $this->windowOptions($window, $dated),
            'insight'     => $this->insight($all, $overdue, $undated),
            'summary'     => $this->summary($all, $overdue, $undated),
            'groups'      => $this->groups($dated, $window),
            'overdue'     => $overdue,
            'undated'     => $undated,
            'total'       => count($all),
            'suggestions' => [
                'What is the next milestone I should hit?',
                'I am going to miss this deadline',
                'Break this goal into milestones',
                'What should I move to make room?',
                'Call the board',
            ],
        ];
    }

    // ── Gathering ───────────────────────────────────────────────────────────

    /**
     * Every dated commitment the OS already knows about, in one list.
     *
     * @return list<array<string,mixed>>
     */
    private function collect(): array
    {
        $items = array_merge(
            $this->goalMilestones(),
            $this->goalDeadlines(),
            $this->projectMilestones()
        );

        // Undated last, then soonest first. An overdue item sorts to the top,
        // which is where a missed commitment belongs.
        usort($items, static function (array $a, array $b): int {
            return [$a['days'] === null, $a['days'] ?? PHP_INT_MAX]
               <=> [$b['days'] === null, $b['days'] ?? PHP_INT_MAX];
        });

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function goalMilestones(): array
    {
        $rows = Database::all(
            "SELECT m.id, m.title, m.metric, m.target_on, m.done_on,
                    g.id AS goal_id, g.title AS goal, g.scope, g.horizon
             FROM goal_milestones m
             JOIN goals g ON g.id = m.goal_id
             WHERE g.status = 'active'"
        );

        return array_map(
            fn (array $row): array => $this->item(
                kind: 'milestone',
                id: (int) $row['id'],
                title: (string) $row['title'],
                belongsTo: (string) $row['goal'],
                belongsLabel: 'Goal',
                scope: (string) $row['scope'],
                date: $row['target_on'] ?? null,
                doneOn: $row['done_on'] ?? null,
                metric: $row['metric'] ?? null,
            ),
            $rows
        );
    }

    /**
     * A goal's own target date is a milestone too: the last one. It is included so
     * a goal with no milestones still appears on the timeline rather than being
     * invisible until someone breaks it down.
     *
     * @return list<array<string,mixed>>
     */
    private function goalDeadlines(): array
    {
        $rows = Database::all(
            "SELECT id, title, scope, horizon, target_on, target_value, current_value, metric
             FROM goals WHERE status = 'active' AND target_on IS NOT NULL"
        );

        return array_map(
            fn (array $row): array => $this->item(
                kind: 'deadline',
                id: (int) $row['id'],
                title: 'Goal deadline: ' . (string) $row['title'],
                belongsTo: (string) $row['title'],
                belongsLabel: 'Goal',
                scope: (string) $row['scope'],
                date: $row['target_on'] ?? null,
                doneOn: null,
                metric: (int) $row['target_value'] > 0
                    ? sprintf('%d%% there', min(100, (int) round(((int) $row['current_value'] / (int) $row['target_value']) * 100)))
                    : null,
            ),
            $rows
        );
    }

    /** @return list<array<string,mixed>> */
    private function projectMilestones(): array
    {
        $rows = Database::all(
            'SELECT id, name, next_milestone, next_milestone_on
             FROM projects
             WHERE archived = 0 AND next_milestone IS NOT NULL'
        );

        return array_map(
            fn (array $row): array => $this->item(
                kind: 'project',
                id: (int) $row['id'],
                title: (string) $row['next_milestone'],
                belongsTo: (string) $row['name'],
                belongsLabel: 'Project',
                scope: 'business',
                date: $row['next_milestone_on'] ?? null,
                doneOn: null,
                metric: null,
            ),
            $rows
        );
    }

    /** @return array<string,mixed> */
    private function item(
        string $kind,
        int $id,
        string $title,
        string $belongsTo,
        string $belongsLabel,
        string $scope,
        ?string $date,
        ?string $doneOn,
        ?string $metric,
    ): array {
        $days  = $date === null ? null : Clock::daysUntil($date);
        $done  = $doneOn !== null;

        $state = match (true) {
            $done          => 'done',
            $days === null => 'undated',
            $days < 0      => 'overdue',
            $days <= 7     => 'soon',
            default        => 'ahead',
        };

        return [
            'kind'         => $kind,
            'id'           => $id,
            'title'        => $title,
            'belongs_to'   => $belongsTo,
            'belongs_label' => $belongsLabel,
            'scope'        => $scope,
            'date'         => $date,
            'days'         => $days,
            'done'         => $done,
            'state'        => $state,
            'when'         => $this->whenLabel($days, $done),
            'metric'       => $metric,
            'tone'         => match ($state) {
                'done'    => 'good',
                'overdue' => 'high',
                'soon'    => 'warn',
                default   => 'quiet',
            },
            'ask'          => sprintf(
                'What is the fastest way to hit this milestone: %s (%s: %s)',
                $title,
                $belongsLabel,
                $belongsTo
            ),
        ];
    }

    // ── Grouping ────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $dated
     * @return list<array<string,mixed>>
     */
    private function groups(array $dated, string $window): array
    {
        $limit = match ($window) {
            'week'  => 7,
            'month' => 31,
            'all'   => PHP_INT_MAX,
            default => 90,
        };

        $buckets = [
            ['key' => 'overdue', 'label' => 'Missed',      'sub' => 'Past their date and still open', 'rows' => []],
            ['key' => 'week',    'label' => 'This week',   'sub' => 'The next seven days',            'rows' => []],
            ['key' => 'month',   'label' => 'This month',  'sub' => 'Inside thirty days',             'rows' => []],
            ['key' => 'later',   'label' => 'Beyond that', 'sub' => 'Further out than a month',       'rows' => []],
        ];

        foreach ($dated as $item) {
            if ($item['done']) {
                continue;
            }

            $days = (int) $item['days'];

            if ($days > $limit) {
                continue;
            }

            $index = match (true) {
                $days < 0   => 0,
                $days <= 7  => 1,
                $days <= 31 => 2,
                default     => 3,
            };

            $buckets[$index]['rows'][] = $item;
        }

        return array_values(array_filter($buckets, static fn (array $b): bool => $b['rows'] !== []));
    }

    /**
     * @param list<array<string,mixed>> $dated
     * @return list<array{key:string,label:string,count:?int,active:bool}>
     */
    private function windowOptions(string $active, array $dated): array
    {
        $open = array_values(array_filter($dated, static fn (array $m): bool => !$m['done']));

        $counts = [
            'week'  => count(array_filter($open, static fn (array $m): bool => (int) $m['days'] <= 7)),
            'month' => count(array_filter($open, static fn (array $m): bool => (int) $m['days'] <= 31)),
            'open'  => count(array_filter($open, static fn (array $m): bool => (int) $m['days'] <= 90)),
            'all'   => count($open),
        ];

        $options = [];

        foreach (self::WINDOWS as $key => $label) {
            $options[] = [
                'key'    => $key,
                'label'  => $label,
                'count'  => $counts[$key] ?? null,
                'active' => $key === $active,
            ];
        }

        return $options;
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $all
     * @param list<array<string,mixed>> $overdue
     * @param list<array<string,mixed>> $undated
     * @return array{text:string,provenance:string}
     */
    private function insight(array $all, array $overdue, array $undated): array
    {
        if ($all === []) {
            return [
                'text' => 'No milestones anywhere. Goals and projects both accept them, and until one has a '
                    . 'date attached there is nothing that can be late, which means nothing that can be tracked.',
                'provenance' => Provenance::Unavailable->value,
            ];
        }

        $soon  = array_values(array_filter($all, static fn (array $m): bool => $m['state'] === 'soon'));
        $parts = [];

        if ($overdue !== []) {
            $parts[] = sprintf(
                '%d milestone%s past its date, starting with "%s" on %s.',
                count($overdue),
                count($overdue) === 1 ? '' : 's',
                $overdue[0]['title'],
                $overdue[0]['belongs_to']
            );
        }

        if ($soon !== []) {
            $parts[] = sprintf(
                '%d land%s inside seven days. That is this week decided for you.',
                count($soon),
                count($soon) === 1 ? 's' : ''
            );
        } elseif ($overdue === []) {
            $parts[] = 'Nothing is due inside a week, which means this week is yours to point at whatever matters most.';
        }

        if ($undated !== []) {
            $parts[] = sprintf(
                '%d %s no date at all. Undated work is not scheduled, it is just remembered, and it slips without anyone noticing.',
                count($undated),
                count($undated) === 1 ? 'has' : 'have'
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    /**
     * @param list<array<string,mixed>> $all
     * @param list<array<string,mixed>> $overdue
     * @param list<array<string,mixed>> $undated
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $all, array $overdue, array $undated): array
    {
        $open = array_values(array_filter($all, static fn (array $m): bool => !$m['done']));
        $soon = count(array_filter($open, static fn (array $m): bool => $m['state'] === 'soon'));
        $done = count(array_filter($all, static fn (array $m): bool => $m['done']));

        return [
            [
                'label' => 'Open milestones',
                'value' => $open === [] ? 'None' : (string) count($open),
                'tone'  => 'neutral',
            ],
            [
                'label' => 'Inside seven days',
                'value' => $soon === 0 ? 'None' : (string) $soon,
                'tone'  => $soon === 0 ? 'good' : 'warn',
            ],
            [
                'label' => 'Missed',
                'value' => $overdue === [] ? 'None' : (string) count($overdue),
                'tone'  => $overdue === [] ? 'good' : 'bad',
            ],
            [
                'label' => 'Undated',
                'value' => $undated === [] ? 'None' : (string) count($undated),
                'tone'  => $undated === [] ? 'good' : 'warn',
            ],
        ];
    }

    private function whenLabel(?int $days, bool $done): string
    {
        if ($done) {
            return 'Done';
        }

        return match (true) {
            $days === null => 'No date',
            $days < 0      => sprintf('%d day%s late', abs($days), abs($days) === 1 ? '' : 's'),
            $days === 0    => 'Today',
            $days === 1    => 'Tomorrow',
            $days <= 31    => sprintf('In %d days', $days),
            default        => sprintf('In %d weeks', (int) round($days / 7)),
        };
    }
}
