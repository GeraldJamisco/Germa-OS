<?php

declare(strict_types=1);

namespace Germa\Service;

use DateTimeImmutable;
use Germa\Support\Clock;
use Germa\Support\Money;
use Germa\Support\Settings;

/**
 * Business → Sales Pipeline.
 *
 * The spec puts this above almost everything else: when money is urgent, sales
 * takes priority over product work. So the screen leads with movement: what
 * entered each stage this week, and ranks live deals by time-to-cash rather
 * than by size, because the constraint being solved is runway.
 */
final class SalesPipelineService
{
    public const PERIODS = ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    public function __construct(
        private readonly PipelineService $pipeline = new PipelineService(),
        private readonly FinanceService $finance = new FinanceService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $period = 'week'): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'week';
        [$from, $to] = $this->window($period);

        $deals      = $this->pipeline->activeDeals(12);
        $conversion = $this->pipeline->conversion();

        return [
            'period'         => $period,
            'periods'        => $this->periodOptions($period),
            'range_label'    => $this->rangeLabel($period),
            'funnel'         => $this->pipeline->funnel(),
            'has_deals'      => $this->pipeline->hasAnyDeals(),
            'open_value'     => $this->pipeline->openValueMetric(),
            'expected_value' => $this->pipeline->expectedValueMetric(),
            'conversion'     => $conversion,
            'deals'          => $deals,
            'won'            => $this->pipeline->wonBetween($from, $to),
            'insight'        => $this->insight($period, $from, $to, $deals, $conversion),
            'suggestions'    => [
                "I don't know which deal to push today",
                "I'm not converting the conversations I have",
                "I need something that pays inside a week",
                "I've been opening conversations and closing none",
                'Call the board',
            ],
        ];
    }

    /** @return array{0:string,1:string} */
    private function window(string $period): array
    {
        $today = Clock::today();

        return match ($period) {
            'month' => [Clock::monthStart(), Clock::monthEnd()],
            'year'  => [$today->format('Y') . '-01-01', $today->format('Y-m-d')],
            default => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    private function rangeLabel(string $period): string
    {
        return match ($period) {
            'month' => 'This month',
            'year'  => 'This year',
            default => 'Last 7 days',
        };
    }

    /** @return list<array{key:string,label:string,active:bool}> */
    private function periodOptions(string $active): array
    {
        $options = [];
        foreach (self::PERIODS as $key => $label) {
            $options[] = ['key' => $key, 'label' => $label, 'active' => $key === $active];
        }
        return $options;
    }

    /**
     * @param list<array<string,mixed>>                    $deals
     * @param array{rate:float,won:int,total:int}          $conversion
     * @return array{text:string,provenance:string}
     */
    private function insight(string $period, string $from, string $to, array $deals, array $conversion): array
    {
        $funnel = $this->pipeline->funnel();
        $counts = [];
        foreach ($funnel as $stage) {
            $counts[$stage['stage']] = $stage['count'];
        }

        $parts     = [];
        $prospects = $counts['prospect'] ?? 0;
        $contacted = ($counts['lead'] ?? 0) + ($counts['proposal'] ?? 0) + ($counts['negotiation'] ?? 0) + ($counts['won'] ?? 0);

        if ($prospects === 0 && $contacted === 0) {
            return [
                'text'       => 'The pipeline is empty. Nothing else on this screen can improve until prospects enter it.',
                'provenance' => 'calculated',
            ];
        }

        if ($prospects > 0) {
            $parts[] = sprintf(
                '%d prospect%s identified, %d taken further.',
                $prospects + $contacted,
                ($prospects + $contacted) === 1 ? '' : 's',
                $contacted
            );
        }

        // The fastest live deal is the one worth naming, because time-to-cash
        // is the ordering the spec asks for when runway is short.
        $soonest = null;
        foreach ($deals as $deal) {
            if (!$deal['is_won'] && $deal['days'] !== null) {
                $soonest = $deal;
                break;
            }
        }

        $runway = $this->finance->runwayDays();

        if ($soonest !== null) {
            $parts[] = sprintf(
                'The nearest money is %s at %s, %s.',
                $soonest['title'],
                mb_strtolower($soonest['stage_label']),
                mb_strtolower($soonest['time_to_cash'])
            );

            if ($runway !== null && $runway < 7) {
                $parts[] = 'Close that before opening new outreach: a conversation already underway converts faster than one that has not started.';
            }
        }

        $stale = count($this->pipeline->staleLeads());
        if ($stale > 0) {
            $parts[] = sprintf(
                '%d live deal%s past the %d-day follow-up mark and cooling.',
                $stale,
                $stale === 1 ? ' is' : 's are',
                Settings::int('followup_stale_days', 6)
            );
        }

        if ($conversion['total'] >= 10 && $conversion['rate'] < 5) {
            $parts[] = sprintf(
                'Conversion is %.1f%% across %d deals. At that rate the problem is the conversation, not the volume.',
                $conversion['rate'],
                $conversion['total']
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }
}
