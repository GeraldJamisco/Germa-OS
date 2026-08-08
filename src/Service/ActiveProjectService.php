<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Hours;
use Germa\Support\Period;
use Germa\Support\Provenance;

/**
 * Projects → Active Projects.
 *
 * The operational view: what is in flight, how far along, and where the hours
 * went. The spec's standing suspicion is that building feels like progress
 * while only selling produces money, so every row here carries the hours it
 * consumed next to the money it returned.
 */
final class ActiveProjectService
{
    public function __construct(
        private readonly ProjectService $projects = new ProjectService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'week'): array
    {
        $period    = Period::normalise($period);
        $portfolio = $this->projects->portfolio($period);
        $hours     = $this->projects->hoursInWindow($period);

        $earning = array_values(array_filter($portfolio, static fn (array $p): bool => $p['earns_revenue']));
        $atRisk  = array_values(array_filter($portfolio, static fn (array $p): bool => $p['at_risk']));

        return [
            'period'       => $period,
            'periods'      => Period::options($period),
            'range_label'  => Period::rangeLabel($period),
            'phrase'       => Period::phrase($period),
            'insight'      => $this->insight($portfolio, $earning, $atRisk, $hours, $period),
            'status_cards' => $this->statusCards($portfolio, $earning, $atRisk),
            'foot'         => $this->foot($portfolio, $earning, $atRisk, $hours, $period),
            'projects'     => array_map(fn (array $p): array => $this->row($p, $period), $portfolio),
            'total'        => count($portfolio),
            'suggestions'  => [
                'Which project should I drop?',
                "I'm building instead of selling",
                'What should I work on today?',
                'Review my projects',
                'Call the board',
            ],
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $portfolio
     * @param list<array<string,mixed>> $earning
     * @param list<array<string,mixed>> $atRisk
     * @return array{text:string,provenance:string}
     */
    private function insight(array $portfolio, array $earning, array $atRisk, float $hours, string $period): array
    {
        if ($portfolio === []) {
            return [
                'text' => 'No projects tracked. Add the work you are actually doing, until it is here, '
                    . 'the hours it consumes are invisible and nothing can tell you whether they are worth it.',
                'provenance' => Provenance::Unavailable->value,
            ];
        }

        $count = count($portfolio);
        $parts = [];

        $parts[] = sprintf(
            '%d project%s in flight, %s.',
            $count,
            $count === 1 ? '' : 's',
            $earning === []
                ? 'none generating revenue'
                : sprintf('%d generating revenue', count($earning))
        );

        // The strongest thing this screen can say is which project is eating
        // time without buying anything, by name and by number.
        if ($atRisk !== []) {
            $worst = $atRisk[0];
            $parts[] = sprintf(
                '%s has consumed %s in the last 14 days with no revenue and no milestone inside 30 days: do not add scope to it %s.',
                $worst['name'],
                Hours::format($worst['hours_14d']),
                Period::phrase($period)
            );
        }

        if ($earning !== []) {
            $lead = $earning[0];
            $parts[] = count($earning) === 1
                ? sprintf(
                    '%s is the only project that should take new hours until something else starts paying.',
                    $lead['name']
                )
                : sprintf('%s has returned the most, at %s.', $lead['name'], $lead['revenue']->format());
        } elseif ($hours > 0.0) {
            $parts[] = sprintf(
                'All %s went to work that has not paid yet. That is a position, not a plan. One of these needs to start earning or stop consuming.',
                Hours::format($hours)
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    // ── Status breakdown ────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $portfolio
     * @param list<array<string,mixed>> $earning
     * @param list<array<string,mixed>> $atRisk
     * @return list<array<string,mixed>>
     */
    private function statusCards(array $portfolio, array $earning, array $atRisk): array
    {
        $active   = count(array_filter($portfolio, static fn (array $p): bool => $p['status'] !== 'paused'));
        $building = count(array_filter(
            $portfolio,
            static fn (array $p): bool => in_array($p['status'], ['strategic', 'validation', 'development'], true)
        ));

        $cards = [
            [
                'label' => 'Active',
                'count' => $active,
                'delta' => sprintf('of %d tracked', count($portfolio)),
                'tone'  => 'quiet',
                'lead'  => false,
            ],
            [
                'label' => 'Revenue-generating',
                'count' => count($earning),
                'delta' => $earning === [] ? 'None earning yet' : 'Money has arrived',
                'tone'  => $earning === [] ? 'warn' : 'lead',
                'lead'  => $earning !== [],
            ],
            [
                'label' => 'Strategic / validation',
                'count' => $building,
                'delta' => $building === 0 ? 'None' : 'No revenue yet',
                'tone'  => 'quiet',
                'lead'  => false,
            ],
            [
                'label' => 'At risk',
                'count' => count($atRisk),
                'delta' => $atRisk === [] ? 'None' : 'Hours with no return',
                'tone'  => $atRisk === [] ? 'quiet' : 'warn',
                'lead'  => false,
            ],
        ];

        // Bar height compares the counts against each other, so the tallest
        // column is whichever class holds the most projects.
        $max = max(1, max(array_map(static fn (array $c): int => $c['count'], $cards)));

        foreach ($cards as $i => $card) {
            $cards[$i]['pct'] = (int) round(($card['count'] / $max) * 100);
        }

        return $cards;
    }

    /**
     * @param list<array<string,mixed>> $portfolio
     * @param list<array<string,mixed>> $earning
     * @param list<array<string,mixed>> $atRisk
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function foot(array $portfolio, array $earning, array $atRisk, float $hours, string $period): array
    {
        return [
            [
                'label' => 'Hours logged',
                'value' => $hours === 0.0 ? 'None logged' : Hours::format($hours) . ' ' . Period::phrase($period),
                'tone'  => $hours === 0.0 ? 'quiet' : 'neutral',
            ],
            [
                'label' => 'Revenue-generating',
                'value' => sprintf('%d of %d', count($earning), count($portfolio)),
                'tone'  => $earning === [] ? 'bad' : 'neutral',
            ],
            [
                'label' => 'At risk',
                'value' => $atRisk === []
                    ? 'None'
                    : sprintf('%d project%s', count($atRisk), count($atRisk) === 1 ? '' : 's'),
                'tone'  => $atRisk === [] ? 'good' : 'bad',
            ],
        ];
    }

    // ── Rows ────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    private function row(array $project, string $period): array
    {
        // At risk overrides the classification: it is the more urgent fact
        // about the project, and showing both would bury it.
        $statusLabel = $project['at_risk'] ? 'AT RISK' : strtoupper($project['status']);

        return [
            'id'         => $project['id'],
            'name'       => $project['name'],
            'sub'        => $project['milestone_label'] ?? 'No milestone set',
            'has_milestone' => $project['milestone_label'] !== null,
            'status'     => $statusLabel,
            'status_tone' => $this->statusTone($project),
            'priority'   => $project['priority'],
            'progress'   => $project['progress_pct'],
            'hours'      => $project['hours_window'] === 0.0
                ? 'No hours ' . Period::phrase($period)
                : Hours::format($project['hours_window']) . ' ' . Period::phrase($period),
            'revenue'    => $project['earns_revenue'] ? $project['revenue']->compact() : 'None',
            'earns'      => $project['earns_revenue'],
            'description' => $project['description'] ?? null,
            'has_description' => (bool) ($project['has_description'] ?? false),
        ];
    }

    /** @param array<string,mixed> $project */
    private function statusTone(array $project): string
    {
        if ($project['at_risk']) {
            return 'warn';
        }
        if ($project['earns_revenue'] || $project['status'] === 'revenue') {
            return 'high';
        }

        return 'quiet';
    }

}
