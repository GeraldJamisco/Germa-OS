<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Money;

/**
 * Business → Opportunities: the spec's OPPORTUNITY MODE, rendered.
 *
 * The spec is unambiguous that opportunities get classified into DO THIS /
 * CONSIDER THIS / IGNORE THIS rather than listed as an undifferentiated feed.
 * The ranking already exists in OpportunityService; this groups it and says
 * what each verdict means, so an IGNORE is a decision the screen defends
 * rather than a place something quietly falls off the bottom.
 */
final class OpportunityBoardService
{
    private const CLASSES = [
        'DO' => [
            'label' => 'DO THIS',
            'sub'   => 'Fast time-to-cash and a real chance of landing',
            'tone'  => 'high',
        ],
        'CONSIDER' => [
            'label' => 'CONSIDER THIS',
            'sub'   => 'Worth pursuing once the immediate gap is covered',
            'tone'  => 'warn',
        ],
        'IGNORE' => [
            'label' => 'IGNORE THIS',
            'sub'   => 'Poor economics, or no evidence anyone will pay',
            'tone'  => 'quiet',
        ],
    ];

    public function __construct(
        private readonly OpportunityService $opportunities = new OpportunityService(),
        private readonly FinanceService $finance = new FinanceService(),
        private readonly ModeService $mode = new ModeService(new FinanceService()),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        // No limit: this screen is the full register, not the dashboard's top two.
        $ranked  = $this->opportunities->ranked(100);
        $grouped = $this->group($ranked);

        return [
            'mode'        => $this->mode->current(),
            'mode_label'  => $this->mode->label(),
            'groups'      => $grouped,
            'total'       => count($ranked),
            'insight'     => $this->insight($ranked, $grouped),
            'suggestions' => [
                "I've spotted something that might pay",
                "I don't know if this is worth my time",
                "I want the fastest money on this list",
                "I keep chasing things that go nowhere",
                'Call the board',
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $ranked
     * @return list<array<string,mixed>>
     */
    private function group(array $ranked): array
    {
        $buckets = ['DO' => [], 'CONSIDER' => [], 'IGNORE' => []];

        foreach ($ranked as $opportunity) {
            $verdict = $opportunity['verdict'];
            $buckets[$verdict][] = $opportunity;
        }

        $groups = [];

        foreach (self::CLASSES as $verdict => $meta) {
            $groups[] = [
                'verdict' => $verdict,
                'label'   => $meta['label'],
                'sub'     => $meta['sub'],
                'tone'    => $meta['tone'],
                'items'   => $buckets[$verdict],
                'count'   => count($buckets[$verdict]),
                'value'   => $this->sum($buckets[$verdict]),
            ];
        }

        return $groups;
    }

    /** @param list<array<string,mixed>> $items */
    private function sum(array $items): Money
    {
        $total = Money::zero();

        foreach ($items as $item) {
            if ($item['value']->currency === Money::base()) {
                $total = $total->plus($item['value']);
            }
        }

        return $total;
    }

    /**
     * @param list<array<string,mixed>> $ranked
     * @param list<array<string,mixed>> $groups
     * @return array{text:string,provenance:string}
     */
    private function insight(array $ranked, array $groups): array
    {
        if ($ranked === []) {
            return [
                'text'       => 'No open opportunities. Ranking is by time-to-cash, probability and expected value together, so add one as soon as you spot it. Even a rough estimate sorts better than an unrecorded hunch.',
                'provenance' => 'calculated',
            ];
        }

        $do     = $groups[0];
        $runway = $this->finance->runwayDays();
        $parts  = [];

        $parts[] = 'Ranked by expected value against time-to-cash and effort.';

        if ($runway !== null && $runway < 7) {
            $parts[] = $do['count'] === 0
                ? 'With runway this short, nothing on this list is fast or likely enough to solve the immediate gap. The pipeline has to.'
                : sprintf(
                    'With %d day%s of runway, only the %d marked DO THIS should get time this week.',
                    $runway,
                    $runway === 1 ? '' : 's',
                    $do['count']
                );
        } elseif ($do['count'] > 0) {
            $parts[] = sprintf('%d worth acting on now, %s of potential value.', $do['count'], $do['value']->compact());
        }

        $ignored = $groups[2];
        if ($ignored['count'] > 0) {
            $parts[] = sprintf(
                '%d classified IGNORE: kept visible on purpose, so passing on them stays a decision rather than a thing you forgot.',
                $ignored['count']
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }
}
