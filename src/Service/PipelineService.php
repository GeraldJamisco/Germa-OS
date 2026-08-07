<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Metric;
use Germa\Support\Money;
use Germa\Support\Provenance;
use Germa\Support\Settings;

/**
 * The Sales Director's view. Week-on-week deltas come from deal_stage_events,
 * not from a stored counter the brief requires the pipeline card to make it
 * obvious whether income generation is improving or declining, and that claim
 * has to be backed by history.
 */
final class PipelineService
{
    public const STAGES = ['prospect', 'lead', 'proposal', 'negotiation', 'won'];

    private const LABELS = [
        'prospect'    => 'Prospects',
        'lead'        => 'Leads',
        'proposal'    => 'Proposals',
        'negotiation' => 'Negotiations',
        'won'         => 'Won',
    ];

    /**
     * @return list<array{
     *   stage:string, label:string, count:int, delta:int, delta_label:string,
     *   delta_direction:string, height_pct:float, is_won:bool
     * }>
     */
    public function funnel(): array
    {
        $counts = [];
        foreach (Database::all('SELECT stage, COUNT(*) AS c FROM deals GROUP BY stage') as $row) {
            $counts[(string) $row['stage']] = (int) $row['c'];
        }

        $since  = Clock::daysAgo(7);
        $deltas = [];
        foreach (
            Database::all(
                'SELECT to_stage, COUNT(*) AS c FROM deal_stage_events WHERE changed_at >= :since GROUP BY to_stage',
                ['since' => $since . ' 00:00:00']
            ) as $row
        ) {
            $deltas[(string) $row['to_stage']] = (int) $row['c'];
        }

        $max = 0;
        foreach (self::STAGES as $stage) {
            $max = max($max, $counts[$stage] ?? 0);
        }

        $funnel = [];
        foreach (self::STAGES as $stage) {
            $count = $counts[$stage] ?? 0;
            $delta = $deltas[$stage] ?? 0;

            $funnel[] = [
                'stage'           => $stage,
                'label'           => self::LABELS[$stage],
                'count'           => $count,
                'delta'           => $delta,
                'delta_label'     => $delta > 0 ? '+' . $delta . ' this week' : 'No change',
                'delta_direction' => $delta > 0 ? 'up' : 'flat',
                // Bars are proportional to the largest stage, so the shape of the
                // funnel is readable without inventing a scale.
                'height_pct'      => $max > 0 ? max(($count / $max) * 100, $count > 0 ? 8 : 2) : 2,
                'is_won'          => $stage === 'won',
            ];
        }

        return $funnel;
    }

    public function hasAnyDeals(): bool
    {
        return (int) Database::value('SELECT COUNT(*) FROM deals', [], 0) > 0;
    }

    /** Value of everything still in play won and lost excluded. */
    public function openValue(): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(value_minor), 0) FROM deals
             WHERE stage NOT IN ('won','lost') AND currency = :c",
            ['c' => Money::base()],
            0
        );

        return Money::of($minor);
    }

    /** Probability-weighted value. An estimate by construction. */
    public function expectedValue(): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(value_minor * probability_pct / 100), 0) FROM deals
             WHERE stage NOT IN ('won','lost') AND currency = :c",
            ['c' => Money::base()],
            0
        );

        return Money::of($minor);
    }

    public function openValueMetric(): Metric
    {
        return $this->hasAnyDeals()
            ? Metric::money($this->openValue(), Provenance::Entered, 'Sum of open deal values')
            : Metric::unavailable('No deals recorded');
    }

    public function expectedValueMetric(): Metric
    {
        return $this->hasAnyDeals()
            ? Metric::money($this->expectedValue(), Provenance::Estimated, 'Open value weighted by your win probabilities')
            : Metric::unavailable('No deals recorded');
    }

    /** Leads that have gone quiet conversion decays fast and the spec knows it. */
    public function staleLeads(): array
    {
        $days = Settings::int('followup_stale_days', 6);

        return Database::all(
            "SELECT d.id, d.title, d.stage, d.last_contact_on, c.name AS client
             FROM deals d
             LEFT JOIN clients c ON c.id = d.client_id
             WHERE d.stage IN ('lead','proposal','negotiation')
               AND (d.last_contact_on IS NULL OR d.last_contact_on <= :cutoff)
             ORDER BY d.last_contact_on IS NULL DESC, d.last_contact_on",
            ['cutoff' => Clock::daysAgo($days)]
        );
    }

    public function prospectsAddedSince(string $date): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM deal_stage_events WHERE to_stage = 'prospect' AND changed_at >= :since",
            ['since' => $date . ' 00:00:00'],
            0
        );
    }

    public function stageAdvancesToday(): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM deal_stage_events
             WHERE to_stage IN ('lead','proposal','negotiation','won')
               AND DATE(changed_at) = :today",
            ['today' => Clock::todayString()],
            0
        );
    }

    /**
     * Live deals ranked by time-to-cash, the spec's first ordering criterion.
     * A deal that could pay this week outranks a bigger one that could not,
     * because the constraint being solved is runway, not revenue.
     *
     * @return list<array<string,mixed>>
     */
    public function activeDeals(int $limit = 12): array
    {
        $rows = Database::all(
            "SELECT d.*, c.name AS client_name, c.org
             FROM deals d
             LEFT JOIN clients c ON c.id = d.client_id
             WHERE d.stage <> 'lost'
             ORDER BY d.expected_close_on IS NULL, d.expected_close_on
             LIMIT " . max(1, $limit)
        );

        $deals = [];

        foreach ($rows as $row) {
            $stage  = (string) $row['stage'];
            $value  = Money::of((int) $row['value_minor'], (string) $row['currency']);
            $close  = $row['expected_close_on'] ?? null;
            $days   = $close === null ? null : Clock::daysUntil((string) $close);

            $deals[] = [
                'id'            => (int) $row['id'],
                'title'         => (string) $row['title'],
                'contact'       => (string) ($row['org'] ?? $row['client_name'] ?? 'No contact recorded'),
                'stage'         => $stage,
                'stage_label'   => mb_strtoupper($stage),
                'stage_tone'    => $this->stageTone($stage),
                'value'         => $value,
                'value_label'   => $value->isZero() ? 'None' : $value->compact(),
                'probability'   => (int) $row['probability_pct'],
                'time_to_cash'  => $this->timeToCash($stage, $days),
                'days'          => $days,
                'last_contact'  => $row['last_contact_on'] ?? null,
                'next_action'   => $row['next_action'] ?? null,
                'is_won'        => $stage === 'won',
            ];
        }

        // Won deals are history. Uncontacted prospects have no honest
        // time-to-cash, so they sort below live conversations rather than
        // borrowing a close date nobody has discussed yet.
        usort($deals, static function (array $a, array $b): int {
            return [$a['is_won'], $a['stage'] === 'prospect', $a['days'] ?? PHP_INT_MAX]
               <=> [$b['is_won'], $b['stage'] === 'prospect', $b['days'] ?? PHP_INT_MAX];
        });

        return $deals;
    }

    private function stageTone(string $stage): string
    {
        return match ($stage) {
            'won'         => 'good',
            'negotiation' => 'high',
            'proposal'    => 'warn',
            default       => 'quiet',
        };
    }

    private function timeToCash(string $stage, ?int $days): string
    {
        if ($stage === 'won') {
            return 'Closed';
        }
        if ($stage === 'prospect') {
            return 'Not yet contacted';
        }
        if ($days === null) {
            return 'Unknown';
        }

        return match (true) {
            $days < 0   => abs($days) . ' days overdue',
            $days === 0 => 'Today',
            default     => $days . ' days',
        };
    }

    /**
     * Won as a share of everything that ever entered the pipeline. The spec
     * tracks conversion rate explicitly, it is the number that says whether
     * outreach volume is the problem or the conversation is.
     *
     * @return array{rate:float,won:int,total:int}
     */
    public function conversion(): array
    {
        $won   = (int) Database::value("SELECT COUNT(*) FROM deals WHERE stage = 'won'", [], 0);
        $total = (int) Database::value('SELECT COUNT(*) FROM deals', [], 0);

        return [
            'rate'  => $total > 0 ? ($won / $total) * 100 : 0.0,
            'won'   => $won,
            'total' => $total,
        ];
    }

    /** Deals won inside a window, by value. */
    public function wonBetween(string $from, string $to): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(d.value_minor), 0)
             FROM deals d
             JOIN deal_stage_events e ON e.deal_id = d.id AND e.to_stage = 'won'
             WHERE d.currency = :c AND DATE(e.changed_at) BETWEEN :from AND :to",
            ['c' => Money::base(), 'from' => $from, 'to' => $to],
            0
        );

        return Money::of($minor);
    }

    /** Moves a deal and records the transition. */
    public function moveStage(int $dealId, string $toStage): void
    {
        if (!in_array($toStage, [...self::STAGES, 'lost'], true)) {
            throw new \InvalidArgumentException("Unknown stage: $toStage");
        }

        Database::transaction(static function () use ($dealId, $toStage): void {
            $deal = Database::first('SELECT stage FROM deals WHERE id = :id', ['id' => $dealId]);
            if ($deal === null) {
                throw new \RuntimeException("Deal $dealId not found");
            }

            $from = (string) $deal['stage'];
            if ($from === $toStage) {
                return;
            }

            Database::update('deals', ['stage' => $toStage], ['id' => $dealId]);
            Database::insert('deal_stage_events', [
                'deal_id'    => $dealId,
                'from_stage' => $from,
                'to_stage'   => $toStage,
                'changed_at' => Clock::now()->format('Y-m-d H:i:s'),
            ]);
        });
    }
}
