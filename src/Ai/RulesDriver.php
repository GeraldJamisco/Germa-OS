<?php

declare(strict_types=1);

namespace Germa\Ai;

/**
 * Deterministic driver. No network, no key, no invention every sentence is
 * assembled from figures already in the context.
 *
 * It exists for three reasons: the app must work before an API key is set, the
 * dashboard must still render when the API is unreachable, and a rules-derived
 * brief is honestly labelled as such in the UI rather than passed off as
 * model output.
 */
final class RulesDriver implements Driver
{
    public function name(): string
    {
        return 'rules';
    }

    public function dailyBrief(Context $context): array
    {
        $finance = $context->finance;
        $parts   = [];

        $pressure = match ($finance['status'] ?? 'TIGHT') {
            'CRITICAL' => 'high',
            'TIGHT'    => 'elevated',
            default    => 'manageable',
        };

        $runway = $finance['runway_days'] ?? null;

        $parts[] = sprintf(
            'Financial pressure is %s: %s available against a %s monthly requirement%s.',
            $pressure,
            (string) ($finance['available_cash'] ?? 'an unknown balance'),
            (string) ($finance['monthly_survival'] ?? 'an unset target'),
            $runway === null ? '' : sprintf(', giving %d day%s of runway', $runway, $runway === 1 ? '' : 's')
        );

        if (!empty($finance['today_target'])) {
            $earned  = (string) ($finance['earned_today'] ?? '');
            $parts[] = sprintf(
                "Today needs %s to stay on pace%s.",
                (string) $finance['today_target'],
                $earned === '' ? '' : ', and ' . $earned . ' has come in so far'
            );
        }

        $top = $context->priorities[0] ?? null;
        if ($top !== null && empty($top['done'])) {
            $parts[] = 'The first priority is still open: ' . (string) $top['title'] . '.';
        }

        return ['brief' => implode(' ', $parts), 'do_not' => $this->doNot($context)];
    }

    /**
     * The spec's standing instruction: name one thing Gerald should not do
     * today. Picked from whatever the recorded position most clearly argues
     * against, in the order the decision hierarchy would rank them.
     */
    private function doNot(Context $context): ?string
    {
        $finance = $context->finance;

        // Hours going into something that has never paid, while cash is short.
        foreach ($context->projects as $project) {
            if (
                empty($project['earns_revenue'])
                && (float) ($project['hours_14d'] ?? 0) > 0
                && ($finance['status'] ?? '') !== 'STABLE'
            ) {
                return sprintf(
                    'Put more hours into %s. %.1fh in the last fortnight with no revenue to show for it.',
                    (string) $project['name'],
                    (float) $project['hours_14d']
                );
            }
        }

        // New outreach before the pipeline already in hand has been worked.
        $stale = (int) ($context->pipeline['stale_leads'] ?? 0);

        if ($stale > 0) {
            return sprintf(
                'Open new conversations before the %d lead%s already in the pipeline have been contacted.',
                $stale,
                $stale === 1 ? '' : 's'
            );
        }

        if (($finance['status'] ?? '') === 'CRITICAL') {
            return 'Spend on anything non-essential until the month is covered.';
        }

        return null;
    }

    public function boardBrief(Context $context): BoardOutput
    {
        $finance  = $context->finance;
        $pipeline = $context->pipeline;
        $signals  = [];

        // CFO cash position.
        $signals[] = match ($finance['status'] ?? 'TIGHT') {
            'CRITICAL' => ['role' => 'CFO', 'signal' => 'warn', 'note' => 'Runway critical'],
            'TIGHT'    => ['role' => 'CFO', 'signal' => 'warn', 'note' => 'Runway thin'],
            default    => ['role' => 'CFO', 'signal' => 'ok', 'note' => 'Cover in place'],
        };

        // Sales is the funnel filling or draining?
        $added = (int) ($pipeline['prospects_added_7d'] ?? 0);
        $stale = (int) ($pipeline['stale_leads'] ?? 0);
        $signals[] = match (true) {
            $added > 0 && $stale === 0 => ['role' => 'Sales', 'signal' => 'up', 'note' => $added . ' new this week'],
            $added > 0                 => ['role' => 'Sales', 'signal' => 'up', 'note' => $added . ' new, ' . $stale . ' gone quiet'],
            $stale > 0                 => ['role' => 'Sales', 'signal' => 'down', 'note' => $stale . ' leads gone quiet'],
            default                    => ['role' => 'Sales', 'signal' => 'warn', 'note' => 'No new prospects'],
        };

        // Critic hours on work that does not pay.
        $sinkHours = 0.0;
        $sinkName  = null;
        foreach ($context->projects as $project) {
            if (empty($project['earns_revenue']) && (float) ($project['hours_14d'] ?? 0) > $sinkHours) {
                $sinkHours = (float) $project['hours_14d'];
                $sinkName  = (string) $project['name'];
            }
        }
        if ($sinkName !== null && $sinkHours >= 5) {
            $signals[] = ['role' => 'Critic', 'signal' => 'warn', 'note' => sprintf('%.0fh on %s, no revenue', $sinkHours, $sinkName)];
        }

        // Risk anything critical open.
        $critical = 0;
        foreach ($context->alerts as $alert) {
            if (($alert['severity'] ?? '') === 'CRITICAL') {
                $critical++;
            }
        }
        if ($critical > 0) {
            $signals[] = ['role' => 'Risk', 'signal' => 'warn', 'note' => $critical . ' critical open'];
        }

        // CEO is the day pointed at the binding constraint?
        $topOpen = ($context->priorities[0] ?? null) !== null && empty($context->priorities[0]['done']);
        $signals[] = $topOpen
            ? ['role' => 'CEO', 'signal' => 'neutral', 'note' => 'Day not yet started']
            : ['role' => 'CEO', 'signal' => 'ok', 'note' => 'Top priority cleared'];

        return new BoardOutput(
            $this->recommendationText($context, $sinkName, $sinkHours),
            'Derived from your recorded cash position, pipeline movement and logged hours no model involved.',
            $signals,
            $this->name(),
        );
    }

    /**
     * Three priorities straight from the decision hierarchy: money already in
     * the pipeline first, then work that can be invoiced, then new outreach.
     */
    public function dailyPriorities(Context $context): array
    {
        $pipeline = $context->pipeline;
        $stale    = (int) ($pipeline['stale_leads'] ?? 0);
        $target   = (int) ($pipeline['daily_prospect_target'] ?? 20);
        $items    = [];

        if ($stale > 0) {
            $items[] = [
                'title'             => sprintf('Follow up with the %d lead%s that have gone quiet', $stale, $stale === 1 ? '' : 's'),
                'estimated_minutes' => min(120, max(30, $stale * 5)),
                'expected_outcome'  => '1 proposal',
            ];
        }

        // The nearest milestone on something that already earns is the closest
        // thing to an invoice.
        foreach ($context->projects as $project) {
            if (!empty($project['earns_revenue']) && !empty($project['next_milestone'])) {
                $items[] = [
                    'title'             => 'Ship the next milestone on ' . $project['name'] . ': ' . $project['next_milestone'],
                    'estimated_minutes' => 120,
                    'expected_outcome'  => 'Invoice ready',
                ];
                break;
            }
        }

        $items[] = [
            'title'             => sprintf('Contact %d potential clients', $target),
            'estimated_minutes' => 180,
            'expected_outcome'  => '5+ replies',
        ];

        // The top opportunity is worth a slot when nothing else fills it.
        if (count($items) < 3 && ($context->opportunities[0] ?? null) !== null) {
            $items[] = [
                'title'             => 'Make contact on ' . $context->opportunities[0]['title'],
                'estimated_minutes' => 45,
                'expected_outcome'  => 'A reply and a price discussion',
            ];
        }

        if (count($items) < 3) {
            $items[] = [
                'title'             => 'Record yesterday\'s spending and confirm the cash balance',
                'estimated_minutes' => 15,
                'expected_outcome'  => 'An accurate runway',
            ];
        }

        return array_slice($items, 0, 3);
    }

    public function answer(string $question, Context $context): string
    {
        // dailyBrief() returns a structure, not a sentence: concatenating it
        // rendered the literal word "Array" into every fallback answer.
        $brief = $this->dailyBrief($context);
        $lines = [
            'No model answered, so this is arithmetic on your recorded figures rather than reasoning about the question.',
            '',
            $brief['brief'],
        ];

        if ($brief['do_not'] !== null) {
            $lines[] = '';
            $lines[] = 'Do not: ' . $brief['do_not'];
        }

        $lines[] = '';
        $lines[] = sprintf('Ask again for a reasoned answer to "%s".', trim($question));

        return implode("\n", $lines);
    }

    private function recommendationText(Context $context, ?string $sinkName, float $sinkHours): string
    {
        $finance  = $context->finance;
        $pipeline = $context->pipeline;
        $lines    = [];

        if (($finance['status'] ?? '') === 'CRITICAL') {
            $lines[] = 'Cash is the binding constraint, so today belongs to income, not to building.';
        } else {
            $lines[] = 'The position is holding, so today should protect the pipeline while the current work ships.';
        }

        $added  = (int) ($pipeline['prospects_added_7d'] ?? 0);
        $target = (int) ($pipeline['daily_prospect_target'] ?? 20);
        $stale  = (int) ($pipeline['stale_leads'] ?? 0);

        if ($stale > 0) {
            $lines[] = sprintf(
                'The cheapest available revenue is the %d lead%s already in the pipeline that have gone quiet contact those before opening any new conversation.',
                $stale,
                $stale === 1 ? '' : 's'
            );
        } elseif ($added < $target) {
            $lines[] = sprintf('Only %d prospect%s entered the pipeline this week against a %d/day target outreach is the gap.', $added, $added === 1 ? '' : 's', $target);
        }

        if ($sinkName !== null && $sinkHours >= 5) {
            $lines[] = sprintf('Hold %s until something is sold; it has absorbed %.0f hours with no revenue path.', $sinkName, $sinkHours);
        }

        return implode(' ', $lines);
    }
}
