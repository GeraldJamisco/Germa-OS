<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Hours;
use Germa\Support\Money;
use Germa\Support\Provenance;

/**
 * Intelligence → Insights.
 *
 * Alerts say what is wrong now. Patterns say what keeps being wrong, which is
 * the more useful thing and the harder one to be honest about.
 *
 * The discipline here is refusal. A pattern claimed from three data points is a
 * coincidence with a confident voice, so every observation carries the sample it
 * was drawn from and anything under its own threshold is not shown as a pattern
 * at all: it is listed as not yet knowable. That is the difference between this
 * screen and a horoscope.
 */
final class InsightService
{
    /** Below this many observations, an observation is not a pattern. */
    private const MIN_SAMPLE = 4;

    public function __construct(
        private readonly AlertService $alerts,
        private readonly PipelineService $pipeline = new PipelineService(),
        private readonly ProjectService $projects = new ProjectService(),
    ) {
    }

    public static function make(): self
    {
        $finance  = new FinanceService();
        $debt     = new DebtService();
        $pipeline = new PipelineService();
        $projects = new ProjectService();

        return new self(
            new AlertService($finance, $debt, $pipeline, $projects),
            $pipeline,
            $projects
        );
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        // Evaluate, not just read. This screen exists to say what is wrong, and
        // reading a table nothing has refreshed would report "nothing is wrong"
        // whenever the dashboard happened not to be opened first.
        $open     = $this->alerts->evaluate();
        $patterns = $this->patterns();
        $trend    = $this->scoreTrend();

        $found   = array_values(array_filter($patterns, static fn (array $p): bool => $p['established']));
        $pending = array_values(array_filter($patterns, static fn (array $p): bool => !$p['established']));

        return [
            'insight'     => $this->headline($open, $found, $trend),
            'summary'     => $this->summary($open, $found),
            'alerts'      => $this->alertRows($open),
            'patterns'    => $found,
            'pending'     => $pending,
            'trend'       => $trend,
            'min_sample'  => self::MIN_SAMPLE,
            'suggestions' => [
                'What patterns do you see in my behaviour?',
                'What am I consistently getting wrong?',
                'What is working that I should do more of?',
                'What am I avoiding?',
                'Call the board',
            ],
        ];
    }

    // ── Alerts ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $open
     * @return list<array<string,mixed>>
     */
    private function alertRows(array $open): array
    {
        $rows = [];

        foreach ($open as $alert) {
            $rows[] = [
                'id'       => $alert['id'],
                'title'    => $alert['title'],
                'body'     => $alert['body'],
                'severity' => $alert['severity'],
                'tone'     => match ((string) $alert['severity']) {
                    'CRITICAL' => 'critical',
                    'HIGH'     => 'high',
                    default    => 'quiet',
                },
                'label'    => $alert['action_label'] ?? null,
                'url'      => $alert['action_url'] ?? null,
                'source'   => $alert['source'],
            ];
        }

        return $rows;
    }

    // ── Patterns ────────────────────────────────────────────────────────────

    /**
     * Each observation states its own sample size, and says plainly when there
     * is not enough history to claim it.
     *
     * @return list<array<string,mixed>>
     */
    private function patterns(): array
    {
        return array_values(array_filter([
            $this->incomeTimingPattern(),
            $this->buildingVersusSellingPattern(),
            $this->followUpDecayPattern(),
            $this->spendConcentrationPattern(),
            $this->scoreDirectionPattern(),
        ]));
    }

    /**
     * When in the month money actually arrives. If it clusters late, the first
     * three weeks are a cash-flow problem regardless of the monthly total.
     */
    private function incomeTimingPattern(): ?array
    {
        $rows = Database::all(
            "SELECT occurred_on, amount_minor, currency FROM ledger_entries
             WHERE direction = 'in' AND occurred_on >= :since",
            ['since' => Clock::daysAgo(180)]
        );

        $base    = Money::base();
        $halves  = [0, 0];
        $counted = 0;

        foreach ($rows as $row) {
            if ((string) $row['currency'] !== $base) {
                continue;
            }
            $day = (int) (new \DateTimeImmutable((string) $row['occurred_on'], Clock::zone()))->format('j');
            $halves[$day <= 15 ? 0 : 1] += (int) $row['amount_minor'];
            $counted++;
        }

        $total = $halves[0] + $halves[1];

        if ($counted < self::MIN_SAMPLE || $total === 0) {
            return $this->notYet(
                'When in the month money arrives',
                sprintf('%d receipt%s recorded', $counted, $counted === 1 ? '' : 's'),
                'Log income as it arrives and this shows whether your month is front or back loaded.'
            );
        }

        $lateShare = (int) round(($halves[1] / $total) * 100);

        if ($lateShare >= 65) {
            return $this->pattern(
                'Money arrives late in the month',
                sprintf(
                    '%d%% of income landed after the 15th, across %d receipts. Bills falling in the first half are '
                    . 'therefore funded by last month, not this one, so a good monthly total can still leave a bad first fortnight.',
                    $lateShare,
                    $counted
                ),
                $counted,
                'warn',
                'receipt'
            );
        }

        if ($lateShare <= 35) {
            return $this->pattern(
                'Money arrives early in the month',
                sprintf(
                    '%d%% of income landed in the first half of the month, across %d receipts. That is the easier '
                    . 'shape to run: commitments later in the month are covered by money already in hand.',
                    100 - $lateShare,
                    $counted
                ),
                $counted,
                'good',
                'receipt'
            );
        }

        return $this->pattern(
            'Income is spread evenly across the month',
            sprintf('Across %d receipts, neither half of the month dominates. No timing problem to solve here.', $counted),
            $counted,
            'quiet',
            'receipt'
        );
    }

    /**
     * The Critic's standing objection, measured: hours going into work that has
     * never paid, while the pipeline sits still.
     */
    private function buildingVersusSellingPattern(): ?array
    {
        $portfolio = $this->projects->portfolio('month');
        $unpaid    = 0.0;
        $paid      = 0.0;

        foreach ($portfolio as $project) {
            if ($project['earns_revenue']) {
                $paid += (float) $project['hours_window'];
            } else {
                $unpaid += (float) $project['hours_window'];
            }
        }

        $total = $paid + $unpaid;

        if ($total <= 0.0) {
            return $this->notYet(
                'Building versus selling',
                'No hours logged this month',
                'Log time against projects and this compares hours that can pay against hours that cannot.'
            );
        }

        $share = (int) round(($unpaid / $total) * 100);
        $added = $this->pipeline->prospectsAddedSince(Clock::daysAgo(7));

        if ($share >= 60 && $added === 0) {
            return $this->pattern(
                'Building while the pipeline stands still',
                sprintf(
                    '%s of %s logged this month went to work that has never paid, and no new prospect was added in the '
                    . 'last seven days. Building is measurable and immediate; selling is neither, which is exactly why it is the one that gets skipped.',
                    Hours::format($unpaid),
                    Hours::format($total)
                ),
                count($portfolio),
                'warn',
                'project'
            );
        }

        if ($share >= 60) {
            return $this->pattern(
                'Most hours go to unpaid work',
                sprintf(
                    '%s of %s this month went to projects that have never produced money. Outreach is happening, '
                    . 'so this is a bet you are making knowingly rather than a way of hiding from the phone.',
                    Hours::format($unpaid),
                    Hours::format($total)
                ),
                count($portfolio),
                'quiet',
                'project'
            );
        }

        return $this->pattern(
            'Hours are weighted toward paid work',
            sprintf(
                '%s of %s this month went to projects that have produced money. That is the right way round.',
                Hours::format($paid),
                Hours::format($total)
            ),
            count($portfolio),
            'good',
            'project'
        );
    }

    /** How much of the pipeline is decaying from silence rather than rejection. */
    private function followUpDecayPattern(): ?array
    {
        $stale = count($this->pipeline->staleLeads());
        $deals = count($this->pipeline->activeDeals(100));

        if ($deals < self::MIN_SAMPLE) {
            return $this->notYet(
                'Follow-up decay',
                sprintf('%d live deal%s', $deals, $deals === 1 ? '' : 's'),
                'With a handful of deals there is no rate to read yet, only individual conversations.'
            );
        }

        if ($stale === 0) {
            return $this->pattern(
                'Nothing is going cold',
                sprintf('All %d live deals have been contacted inside the follow-up window.', $deals),
                $deals,
                'good',
                'deal'
            );
        }

        $share = (int) round(($stale / max(1, $deals)) * 100);

        return $this->pattern(
            'Deals are lost to silence, not to no',
            sprintf(
                '%d of %d live deals (%d%%) are past the follow-up window. These were not refused, they were left. '
                . 'A message costs nothing and the ones already talking to you convert faster than anyone new.',
                $stale,
                $deals,
                $share
            ),
            $deals,
            $share >= 40 ? 'warn' : 'quiet',
            'deal'
        );
    }

    /** Whether spending is concentrated somewhere worth naming. */
    private function spendConcentrationPattern(): ?array
    {
        $rows = Database::all(
            "SELECT category, SUM(amount_minor) total, COUNT(*) n
             FROM ledger_entries
             WHERE direction = 'out' AND currency = :cur AND occurred_on >= :since
             GROUP BY category ORDER BY total DESC",
            ['cur' => Money::base(), 'since' => Clock::daysAgo(90)]
        );

        $entries = array_sum(array_map(static fn (array $r): int => (int) $r['n'], $rows));

        if ($entries < self::MIN_SAMPLE || $rows === []) {
            return $this->notYet(
                'Where the money goes',
                sprintf('%d expense%s recorded', $entries, $entries === 1 ? '' : 's'),
                'Log spending for a few weeks and the concentration becomes readable.'
            );
        }

        $total = array_sum(array_map(static fn (array $r): int => (int) $r['total'], $rows));
        $top   = $rows[0];
        $share = $total === 0 ? 0 : (int) round(((int) $top['total'] / $total) * 100);

        if ($share < 40) {
            return $this->pattern(
                'Spending is spread across categories',
                sprintf(
                    'No single category takes more than %d%% of the last 90 days, across %d entries. There is no '
                    . 'one line to cut here, which means a gap has to close on the income side.',
                    $share,
                    $entries
                ),
                $entries,
                'quiet',
                'entry'
            );
        }

        return $this->pattern(
            sprintf('%s is where the money goes', ucfirst((string) $top['category'])),
            sprintf(
                '%s took %d%% of everything spent in the last 90 days, %s across %d entries. Any real reduction '
                . 'in spending has to come from here, because everything else is too small to matter.',
                ucfirst((string) $top['category']),
                $share,
                Money::of((int) $top['total'], Money::base())->format(),
                $entries
            ),
            $entries,
            'warn',
            'entry'
        );
    }

    /** Whether the daily score is trending up or down. */
    private function scoreDirectionPattern(): ?array
    {
        $rows = Database::all(
            'SELECT for_date, total FROM daily_scores ORDER BY for_date DESC LIMIT 14'
        );

        if (count($rows) < self::MIN_SAMPLE) {
            return $this->notYet(
                'Whether your days are improving',
                sprintf('%d day%s scored', count($rows), count($rows) === 1 ? '' : 's'),
                sprintf('Close out %d days and the direction becomes readable.', self::MIN_SAMPLE),
            );
        }

        $scores = array_reverse(array_map(static fn (array $r): int => (int) $r['total'], $rows));
        $half   = (int) floor(count($scores) / 2);
        $older  = array_slice($scores, 0, $half);
        $recent = array_slice($scores, $half);

        $olderAvg  = array_sum($older) / max(1, count($older));
        $recentAvg = array_sum($recent) / max(1, count($recent));
        $delta     = (int) round($recentAvg - $olderAvg);

        if (abs($delta) < 5) {
            return $this->pattern(
                'Your days score about the same',
                sprintf(
                    'Averaging %d recently against %d before that, across %d scored days. Flat is not failure, '
                    . 'but nothing you have changed lately has moved it.',
                    (int) round($recentAvg),
                    (int) round($olderAvg),
                    count($scores)
                ),
                count($scores),
                'quiet',
                'scored day'
            );
        }

        return $this->pattern(
            $delta > 0 ? 'Your days are getting better' : 'Your days are getting worse',
            sprintf(
                'Recent days average %d against %d earlier, a %s of %d points across %d scored days.',
                (int) round($recentAvg),
                (int) round($olderAvg),
                $delta > 0 ? 'gain' : 'drop',
                abs($delta),
                count($scores)
            ),
            count($scores),
            $delta > 0 ? 'good' : 'warn',
            'scored day'
        );
    }

    /** @return array<string,mixed> */
    /**
     * @param string $unit What was actually counted. "4 observations" is vaguer
     *        than "4 projects", and the vaguer version is easier to overtrust.
     */
    private function pattern(string $title, string $body, int $sample, string $tone, string $unit = 'observation'): array
    {
        return [
            'title'       => $title,
            'body'        => $body,
            'sample'      => $sample,
            'sample_note' => sprintf('from %d %s', $sample, $sample === 1 ? $unit : $this->plural($unit)),
            'tone'        => $tone,
            'established' => true,
        ];
    }

    /** Enough pluralisation for the nouns this screen counts. */
    private function plural(string $unit): string
    {
        return str_ends_with($unit, 'y') && !str_ends_with($unit, 'ay')
            ? substr($unit, 0, -1) . 'ies'
            : $unit . 's';
    }

    /** @return array<string,mixed> */
    private function notYet(string $title, string $sampleNote, string $body): array
    {
        return [
            'title'       => $title,
            'body'        => $body,
            'sample'      => 0,
            'sample_note' => $sampleNote,
            'tone'        => 'quiet',
            'established' => false,
        ];
    }

    // ── Score trend ─────────────────────────────────────────────────────────

    /** @return array{days:list<array<string,mixed>>,has_data:bool,average:?int} */
    private function scoreTrend(): array
    {
        $rows = Database::all(
            'SELECT for_date, total FROM daily_scores ORDER BY for_date DESC LIMIT 14'
        );

        if ($rows === []) {
            return ['days' => [], 'has_data' => false, 'average' => null];
        }

        $rows  = array_reverse($rows);
        $days  = [];
        $sum   = 0;
        $today = Clock::todayString();

        foreach ($rows as $row) {
            $total = (int) $row['total'];
            $sum  += $total;
            $days[] = [
                'label'  => (new \DateTimeImmutable((string) $row['for_date'], Clock::zone()))->format('j'),
                'date'   => (string) $row['for_date'],
                'score'  => $total,
                'pct'    => max(0, min(100, $total)),
                'is_now' => (string) $row['for_date'] === $today,
            ];
        }

        return [
            'days'     => $days,
            'has_data' => true,
            'average'  => (int) round($sum / count($rows)),
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $open
     * @param list<array<string,mixed>> $found
     * @param array{days:list<array<string,mixed>>,has_data:bool,average:?int} $trend
     * @return array{text:string,provenance:string}
     */
    private function headline(array $open, array $found, array $trend): array
    {
        $critical = count(array_filter($open, static fn (array $a): bool => (string) $a['severity'] === 'CRITICAL'));
        $parts    = [];

        if ($critical > 0) {
            $parts[] = sprintf(
                '%d critical alert%s open. Those are the ones where waiting makes the problem bigger rather than clearer.',
                $critical,
                $critical === 1 ? '' : 's'
            );
        } elseif ($open !== []) {
            $parts[] = sprintf('%d alert%s open, none critical.', count($open), count($open) === 1 ? '' : 's');
        } else {
            $parts[] = 'No alerts open.';
        }

        // Lead with the pattern that argues for a change of behaviour.
        $worrying = array_values(array_filter($found, static fn (array $p): bool => $p['tone'] === 'warn'));

        if ($worrying !== []) {
            $parts[] = sprintf(
                'The pattern worth acting on: %s.',
                lcfirst(rtrim((string) $worrying[0]['title'], '.'))
            );
        } elseif ($found !== []) {
            $parts[] = sprintf(
                '%d pattern%s established from your own records, none of them currently arguing for a change.',
                count($found),
                count($found) === 1 ? '' : 's'
            );
        }

        if ($trend['has_data'] && $trend['average'] !== null) {
            $parts[] = sprintf('Your days average %d out of 100 over the period shown.', $trend['average']);
        }

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    /**
     * @param list<array<string,mixed>> $open
     * @param list<array<string,mixed>> $found
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $open, array $found): array
    {
        $critical = count(array_filter($open, static fn (array $a): bool => (string) $a['severity'] === 'CRITICAL'));
        $worrying = count(array_filter($found, static fn (array $p): bool => $p['tone'] === 'warn'));

        return [
            [
                'label' => 'Critical alerts',
                'value' => $critical === 0 ? 'None' : (string) $critical,
                'tone'  => $critical === 0 ? 'good' : 'bad',
            ],
            [
                'label' => 'All open alerts',
                'value' => $open === [] ? 'None' : (string) count($open),
                'tone'  => $open === [] ? 'good' : 'neutral',
            ],
            [
                'label' => 'Patterns established',
                'value' => $found === [] ? 'None yet' : (string) count($found),
                'tone'  => 'neutral',
            ],
            [
                'label' => 'Arguing for a change',
                'value' => $worrying === 0 ? 'None' : (string) $worrying,
                'tone'  => $worrying === 0 ? 'good' : 'warn',
            ],
        ];
    }
}
