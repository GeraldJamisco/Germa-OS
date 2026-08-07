<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Money;
use Germa\Support\Settings;

/**
 * Execution → Tasks.
 *
 * The Operations Director's list. Today answers "what is the plan"; this
 * answers "what is outstanding", which is a different and less comfortable
 * question, so overdue work sorts to the top and gets counted separately.
 */
final class TaskBoardService
{
    public const FILTERS = [
        'all'      => 'All',
        'overdue'  => 'Overdue',
        'today'    => 'Today',
        'upcoming' => 'Upcoming',
        'done'     => 'Done',
    ];

    public function __construct(
        private readonly TaskService $tasks = new TaskService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $filter = 'all'): array
    {
        $filter = array_key_exists($filter, self::FILTERS) ? $filter : 'all';
        $counts = $this->tasks->boardCounts();
        $rows   = $this->tasks->all($filter);

        return [
            'filter'      => $filter,
            'filters'     => $this->filterOptions($filter),
            'tasks'       => $rows,
            'counts'      => $counts,
            'summary'     => $this->summary($counts),
            'insight'     => $this->insight($counts),
            'suggestions' => [
                "I have more open than I can finish",
                "I don't know which of these actually matters",
                "I keep pushing the same task forward",
                "I want today's plan rebuilt",
                'Call the board',
            ],
        ];
    }

    /** @return list<array{key:string,label:string,active:bool,count:?int}> */
    private function filterOptions(string $active): array
    {
        $counts  = $this->tasks->boardCounts();
        $options = [];

        foreach (self::FILTERS as $key => $label) {
            $options[] = [
                'key'    => $key,
                'label'  => $label,
                'active' => $key === $active,
                'count'  => match ($key) {
                    'overdue' => $counts['overdue'],
                    'today'   => $counts['today'],
                    'done'    => $counts['done_this_week'],
                    default   => null,
                },
            ];
        }

        return $options;
    }

    /**
     * @param array{open:int,overdue:int,today:int,done_this_week:int} $counts
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $counts): array
    {
        return [
            ['label' => 'Open tasks',           'value' => (string) $counts['open'],           'tone' => 'plain'],
            ['label' => 'Overdue',              'value' => (string) $counts['overdue'],        'tone' => $counts['overdue'] > 0 ? 'bad' : 'plain'],
            ['label' => 'Due today',            'value' => (string) $counts['today'],          'tone' => 'plain'],
            ['label' => 'Completed this week',  'value' => (string) $counts['done_this_week'], 'tone' => $counts['done_this_week'] > 0 ? 'good' : 'plain'],
        ];
    }

    /**
     * @param array{open:int,overdue:int,today:int,done_this_week:int} $counts
     * @return array{text:string,provenance:string}
     */
    private function insight(array $counts): array
    {
        $parts = [];

        if ($counts['open'] === 0) {
            $parts[] = 'Nothing is outstanding.';
        } else {
            $parts[] = sprintf(
                '%d task%s open%s.',
                $counts['open'],
                $counts['open'] === 1 ? '' : 's',
                $counts['overdue'] > 0 ? ', ' . $counts['overdue'] . ' overdue' : ''
            );
        }

        if ($counts['overdue'] > 0) {
            $stale = count((new PipelineService())->staleLeads());
            $parts[] = $stale > 0
                ? sprintf(
                    'Clear the overdue follow-ups first: conversion decays fast after day %d without contact, and %d lead%s are already past that.',
                    Settings::int('followup_stale_days', 6),
                    $stale,
                    $stale === 1 ? '' : 's'
                )
                : 'Clear what is overdue before adding anything new: a list that only grows stops being a plan.';
        } elseif ($counts['today'] > 0) {
            $parts[] = sprintf('%d due today and nothing behind, which is the position to hold.', $counts['today']);
        }

        // Volume itself is worth naming: a day cannot hold everything.
        $capacity = Settings::int('work_days_per_week', 6) > 0 ? 8 : 8;
        if ($counts['today'] > 5) {
            $parts[] = sprintf('%d items due today is more than %dh can hold: something here will slip.', $counts['today'], $capacity);
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }
}
