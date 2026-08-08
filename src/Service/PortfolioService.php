<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Hours;
use Germa\Support\Period;
use Germa\Support\Provenance;

/**
 * Projects → Project Portfolio.
 *
 * Active Projects asks how the work is going. This asks the harder question the
 * spec puts to every project periodically: keep it, pause it, pivot it, or kill
 * it. It also shows where the hours actually went, because capital allocation
 * is decided by the clock rather than by intention.
 */
final class PortfolioService
{
    /**
     * The four verdicts the spec requires, plus the state a project is in when
     * its recorded verdict has expired. REASSESS is not stored, it is what an
     * overdue review looks like, and it should not be possible to hide it by
     * leaving the old decision in place.
     */
    private const VERDICTS = [
        'continue' => ['label' => 'CONTINUE', 'tone' => 'good'],
        'pause'    => ['label' => 'HOLD',     'tone' => 'quiet'],
        'pivot'    => ['label' => 'PIVOT',    'tone' => 'warn'],
        'kill'     => ['label' => 'KILL',     'tone' => 'high'],
    ];

    public function __construct(
        private readonly ProjectService $projects = new ProjectService(),
        private readonly FinanceService $finance = new FinanceService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'week'): array
    {
        $period    = Period::normalise($period);
        $portfolio = $this->projects->portfolio($period);
        $allocation = $this->projects->allocationByClass($period);

        $earning = array_values(array_filter($portfolio, static fn (array $p): bool => $p['earns_revenue']));
        $atRisk  = array_values(array_filter($portfolio, static fn (array $p): bool => $p['at_risk']));
        // A verdict is out of date either because its review date passed or
        // because the evidence moved under it. Both mean the same thing: the
        // recorded decision no longer describes the project.
        $stale = array_values(array_filter(
            $portfolio,
            fn (array $p): bool => $this->needsDecision($p)
        ));

        return [
            'period'      => $period,
            'periods'     => Period::options($period),
            'range_label' => Period::rangeLabel($period),
            'phrase'      => Period::phrase($period),
            'insight'     => $this->insight($portfolio, $earning, $atRisk, $allocation, $period),
            'do_not'      => $this->doNot($portfolio, $earning, $atRisk),
            'allocation'  => $allocation,
            'review'      => array_map(fn (array $p): array => $this->verdict($p), $portfolio),
            'stale_count' => count($stale),
            'invested'    => $this->invested($portfolio),
            'suggestions' => [
                'Which project should I kill?',
                'Am I spreading myself too thin?',
                'Review my projects',
                'Is this project worth continuing?',
                'Call the board',
            ],
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>>                                     $portfolio
     * @param list<array<string,mixed>>                                     $earning
     * @param list<array<string,mixed>>                                     $atRisk
     * @param array{rows:list<array<string,mixed>>,total_hours:float}       $allocation
     * @return array{text:string,provenance:string}
     */
    private function insight(array $portfolio, array $earning, array $atRisk, array $allocation, string $period): array
    {
        if ($portfolio === []) {
            return [
                'text' => 'Nothing in the portfolio. There is no allocation decision to make until '
                    . 'the work you are doing is written down.',
                'provenance' => Provenance::Unavailable->value,
            ];
        }

        $hours = $allocation['total_hours'];
        $parts = [];

        if ($hours === 0.0) {
            $parts[] = sprintf(
                'No hours logged %s across %d project%s, so this screen cannot tell you where your time went. Log time against a project and the allocation becomes visible.',
                Period::phrase($period),
                count($portfolio),
                count($portfolio) === 1 ? '' : 's'
            );

            return ['text' => implode(' ', $parts), 'provenance' => Provenance::Unavailable->value];
        }

        $parts[] = sprintf(
            '%s %s are split across %d project%s but %s.',
            Hours::format($hours),
            Period::phrase($period),
            count($portfolio),
            count($portfolio) === 1 ? '' : 's',
            $earning === []
                ? 'none of them returns cash'
                : sprintf('only %d return%s cash', count($earning), count($earning) === 1 ? 's' : '')
        );

        // The allocation verdict: is the share of hours going to paid work
        // defensible given how much runway there is?
        $revenueShare = 0;
        foreach ($allocation['rows'] as $row) {
            if ($row['status'] === 'revenue') {
                $revenueShare = $row['pct'];
            }
        }

        $runway = $this->finance->runwayDays();

        if ($revenueShare < 50 && $runway !== null && $runway < 30) {
            $parts[] = sprintf(
                'Only %d%% of your hours went to work that pays, with %d days of runway. Capital allocation is misaligned with survival.',
                $revenueShare,
                $runway
            );
        } elseif ($revenueShare < 50) {
            $parts[] = sprintf(
                '%d%% of your hours went to work that pays. The rest is a bet: it should be a bet you have chosen, not a default.',
                $revenueShare
            );
        }

        if ($atRisk !== []) {
            $names = array_map(static fn (array $p): string => (string) $p['name'], $atRisk);
            $parts[] = sprintf(
                'Reassess %s before the next planning cycle.',
                $this->list($names)
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    /**
     * The board's standing constraint on the portfolio. The spec insists every
     * brief names one thing NOT to do, and at portfolio level the answer is
     * almost always the same shape: do not widen what you cannot already carry.
     *
     * @param list<array<string,mixed>> $portfolio
     * @param list<array<string,mixed>> $earning
     * @param list<array<string,mixed>> $atRisk
     */
    private function doNot(array $portfolio, array $earning, array $atRisk): ?string
    {
        if ($portfolio === []) {
            return null;
        }

        $unpaid = array_values(array_filter($portfolio, static fn (array $p): bool => !$p['earns_revenue']));

        if ($unpaid === []) {
            return 'Do not add a fifth commitment without deciding which existing project gives up the hours. '
                . 'Every project here is paying. That is a position worth protecting, not diluting.';
        }

        $names = array_map(static fn (array $p): string => (string) $p['name'], $unpaid);

        if ($atRisk !== []) {
            return sprintf(
                'Do not start a new project or add scope to %s until %s shows a revenue signal or gets a decision to stop.',
                $this->list(array_map(static fn (array $p): string => (string) $p['name'], $atRisk)),
                count($atRisk) === 1 ? 'it' : 'they'
            );
        }

        return sprintf(
            'Do not start a new project while %s %s yet to produce money. %s',
            $this->list($names),
            count($unpaid) === 1 ? 'is' : 'are',
            $earning === []
                ? 'Nothing in the portfolio pays yet. The next hour should go to something that can.'
                : 'A fifth unpaid project does not diversify the risk. It multiplies it.'
        );
    }

    // ── Verdicts ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    private function verdict(array $project): array
    {
        if ($this->needsDecision($project)) {
            $verdict = ['label' => 'REASSESS', 'tone' => 'warn'];
        } else {
            $verdict = self::VERDICTS[$project['decision']] ?? ['label' => 'CONTINUE', 'tone' => 'good'];
        }

        return [
            'id'             => $project['id'],
            'name'           => $project['name'],
            'has_description' => (bool) ($project['has_description'] ?? false),
            'classification' => $project['status_label'],
            'hours_total'    => $project['hours_total'],
            'hours_label'    => $project['hours_total'] === 0.0
                ? 'no hours logged'
                : Hours::format($project['hours_total']) . ' invested',
            'revenue'        => $project['revenue'],
            'revenue_label'  => $project['earns_revenue'] ? $project['revenue']->compact() : 'None',
            'earns'          => $project['earns_revenue'],
            'verdict'        => $verdict['label'],
            'verdict_tone'   => $verdict['tone'],
            'review_due'     => $project['review_due'],
            'needs_decision' => $this->needsDecision($project),
            'recommendation' => $this->recommendation($project),
        ];
    }

    /**
     * Whether the recorded verdict still describes the project. It stops doing
     * so when its review date passes, or when the project turns out to be
     * burning hours for nothing, a project cannot keep displaying the CONTINUE
     * it was given before that was true, because the verdict would contradict
     * the recommendation printed beside it. A verdict recorded in the last
     * fortnight is exempt: that call was made with this evidence in view.
     *
     * @param array<string,mixed> $project
     */
    private function needsDecision(array $project): bool
    {
        if ($project['review_due']) {
            return true;
        }

        return $project['at_risk'] && !$project['decision_fresh'];
    }

    /**
     * What to actually do about this project. Derived from its own numbers, so
     * it is an argument the operator can check rather than a slogan.
     *
     * @param array<string,mixed> $project
     */
    private function recommendation(array $project): string
    {
        if ($project['at_risk']) {
            // A verdict recorded against this evidence deserves acknowledging;
            // repeating "decide something" at someone who just did is noise.
            if ($project['decision_fresh']) {
                return sprintf(
                    '%s recorded %s: it needs a revenue signal within 30 days to earn the next block of hours',
                    strtoupper((string) $project['decision']),
                    $this->day((string) $project['decided_on'])
                );
            }

            return sprintf(
                'Needs a revenue signal within 30 days or a decision to stop: %s spent, nothing back',
                Hours::format($project['hours_total'])
            );
        }

        if ($project['earns_revenue']) {
            return $this->projects->revenueProducingCount() === 1
                ? 'The only project that should take new hours'
                : 'Paying: protect the hours it already gets';
        }

        if ($project['review_due']) {
            return 'Review date has passed: the recorded verdict no longer means anything';
        }


        if ($project['milestone_days'] !== null && $project['milestone_days'] <= 30) {
            return sprintf(
                'Buying something specific in %d day%s: let it run to the milestone',
                $project['milestone_days'],
                $project['milestone_days'] === 1 ? '' : 's'
            );
        }

        if ($project['status'] === 'validation') {
            return 'Pause new hours until validation is finished';
        }

        if ($project['hours_total'] === 0.0) {
            return 'No hours logged: either start it or stop carrying it on the list';
        }

        return 'No allocation until the next portfolio review';
    }

    /**
     * Hours are the currency actually being spent here, so total investment is
     * stated in hours rather than money.
     *
     * @param list<array<string,mixed>> $portfolio
     */
    private function invested(array $portfolio): float
    {
        $hours = 0.0;

        foreach ($portfolio as $project) {
            $hours += (float) $project['hours_total'];
        }

        return round($hours, 1);
    }

    // ── Formatting ──────────────────────────────────────────────────────────

    private function day(string $date): string
    {
        return (new \DateTimeImmutable($date, Clock::zone()))->format('j M');
    }

    /** @param list<string> $items */
    private function list(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }

}
