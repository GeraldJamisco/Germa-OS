<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Money;
use Germa\Support\Settings;

/**
 * Business → Clients.
 *
 * The pipeline tracks deals; this tracks the people behind them. The question
 * it exists to answer is the one the Sales Director keeps asking: who has paid
 * before, and who has gone quiet, because a former client is the cheapest
 * conversation available and the easiest one to forget.
 */
final class ClientService
{
    public const FILTERS = ['all' => 'All', 'active' => 'Active', 'talking' => 'In talks', 'quiet' => 'Quiet'];

    /** Days without contact before a relationship counts as gone cold. */
    private const QUIET_DAYS = 60;

    /** @return array<string,mixed> */
    public function build(string $filter = 'all'): array
    {
        $filter  = array_key_exists($filter, self::FILTERS) ? $filter : 'all';
        $clients = $this->clients();

        $visible = $filter === 'all'
            ? $clients
            : array_values(array_filter($clients, static fn (array $c): bool => $c['group'] === $filter));

        return [
            'filter'      => $filter,
            'filters'     => $this->filterOptions($filter, $clients),
            'clients'     => $visible,
            'summary'     => $this->summary($clients),
            'insight'     => $this->insight($clients),
            'suggestions' => [
                "I should reconnect with someone I've worked with",
                "I don't know which client is worth chasing",
                "I want repeat work from an old client",
                "I'm relying on one client too much",
                'Call the board',
            ],
        ];
    }

    /**
     * Every client, with what they have actually paid and how cold the
     * relationship has gone.
     *
     * @return list<array<string,mixed>>
     */
    private function clients(): array
    {
        $rows = Database::all(
            "SELECT c.id, c.name, c.org, c.email, c.phone,
                    COUNT(d.id) AS deal_count,
                    COALESCE(SUM(CASE WHEN d.stage = 'won' THEN d.value_minor ELSE 0 END), 0) AS won_minor,
                    SUM(d.stage IN ('lead','proposal','negotiation')) AS open_deals,
                    SUM(d.stage = 'prospect') AS prospect_deals,
                    SUM(d.stage = 'won') AS won_deals,
                    MAX(d.last_contact_on) AS last_contact,
                    MIN(d.currency) AS currency
             FROM clients c
             LEFT JOIN deals d ON d.client_id = c.id
             GROUP BY c.id, c.name, c.org, c.email, c.phone
             ORDER BY won_minor DESC, last_contact DESC"
        );

        $clients = [];

        foreach ($rows as $row) {
            $won          = Money::of((int) $row['won_minor'], (string) ($row['currency'] ?? Money::base()));
            $lastContact  = $row['last_contact'] ?? null;
            $daysSince    = $lastContact === null ? null : abs(Clock::daysUntil((string) $lastContact));
            $openDeals    = (int) $row['open_deals'];
            $wonDeals     = (int) $row['won_deals'];
            $isQuiet      = $daysSince === null || $daysSince >= self::QUIET_DAYS;

            $status = match (true) {
                $wonDeals > 0 && !$isQuiet => ['label' => 'ACTIVE',   'tone' => 'good',  'group' => 'active'],
                $openDeals > 0 && !$isQuiet => ['label' => 'IN TALKS', 'tone' => 'warn',  'group' => 'talking'],
                $wonDeals > 0              => ['label' => 'QUIET',    'tone' => 'quiet', 'group' => 'quiet'],
                $openDeals > 0             => ['label' => 'STALLED',  'tone' => 'quiet', 'group' => 'quiet'],
                default                    => ['label' => 'PROSPECT', 'tone' => 'quiet', 'group' => 'talking'],
            };

            $clients[] = [
                'id'           => (int) $row['id'],
                'name'         => (string) ($row['org'] ?: $row['name']),
                'sub'          => $this->subtitle($openDeals, $wonDeals, (int) $row['prospect_deals']),
                'initials'     => $this->initials((string) ($row['org'] ?: $row['name'])),
                'status'       => $status['label'],
                'status_tone'  => $status['tone'],
                'group'        => $status['group'],
                'has_paid'     => $wonDeals > 0,
                'won'          => $won,
                'won_label'    => $won->isZero() ? 'None' : $won->compact(),
                'days_since'   => $daysSince,
                'last_contact' => $this->contactLabel($daysSince),
                'is_quiet'     => $isQuiet,
            ];
        }

        return $clients;
    }

    private function subtitle(int $open, int $won, int $prospects): string
    {
        $parts = [];

        if ($won > 0) {
            $parts[] = $won . ' won';
        }
        if ($open > 0) {
            $parts[] = $open . ' in play';
        }
        if ($prospects > 0 && $open === 0 && $won === 0) {
            $parts[] = 'Not yet contacted';
        }

        return $parts === [] ? 'No deals recorded' : implode(' · ', $parts);
    }

    private function contactLabel(?int $days): string
    {
        return match (true) {
            $days === null => 'Never contacted',
            $days === 0    => 'Today',
            $days === 1    => 'Yesterday',
            default        => $days . ' days ago',
        };
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr((string) ($words[0] ?? '?'), 0, 1);
        $second = count($words) > 1 ? mb_substr((string) $words[1], 0, 1) : mb_substr((string) ($words[0] ?? ''), 1, 1);

        return mb_strtoupper($first . $second);
    }

    /**
     * @param list<array<string,mixed>> $clients
     * @return list<array{key:string,label:string,active:bool,count:?int}>
     */
    private function filterOptions(string $active, array $clients): array
    {
        $counts = ['active' => 0, 'talking' => 0, 'quiet' => 0];

        foreach ($clients as $client) {
            $counts[$client['group']]++;
        }

        $options = [];

        foreach (self::FILTERS as $key => $label) {
            $options[] = [
                'key'    => $key,
                'label'  => $label,
                'active' => $key === $active,
                'count'  => $key === 'all' ? null : $counts[$key],
            ];
        }

        return $options;
    }

    /**
     * @param list<array<string,mixed>> $clients
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $clients): array
    {
        $paying = 0;
        $talking = 0;
        $quiet   = 0;

        foreach ($clients as $client) {
            if ($client['has_paid'] && !$client['is_quiet']) {
                $paying++;
            }
            if ($client['group'] === 'talking') {
                $talking++;
            }
            if ($client['group'] === 'quiet') {
                $quiet++;
            }
        }

        return [
            ['label' => 'Total clients',       'value' => (string) count($clients), 'tone' => 'plain'],
            ['label' => 'Currently paying',    'value' => (string) $paying,         'tone' => $paying === 0 ? 'bad' : 'good'],
            ['label' => 'In talks',            'value' => (string) $talking,        'tone' => 'plain'],
            ['label' => 'Quiet ' . self::QUIET_DAYS . '+ days', 'value' => (string) $quiet, 'tone' => $quiet > 0 ? 'bad' : 'plain'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $clients
     * @return array{text:string,provenance:string}
     */
    private function insight(array $clients): array
    {
        if ($clients === []) {
            return [
                'text'       => 'No clients recorded yet. Every prospect you add becomes one, so the list grows out of outreach rather than needing to be built separately.',
                'provenance' => 'calculated',
            ];
        }

        $paying = array_values(array_filter($clients, static fn (array $c): bool => $c['has_paid'] && !$c['is_quiet']));
        $lapsed = array_values(array_filter($clients, static fn (array $c): bool => $c['has_paid'] && $c['is_quiet']));
        $parts  = [];

        $parts[] = match (count($paying)) {
            0       => 'No client is currently producing revenue.',
            1       => sprintf('Only %s is producing revenue.', $paying[0]['name']),
            default => sprintf('%d clients are producing revenue.', count($paying)),
        };

        // A former client is the cheapest conversation available, the spec
        // ranks by time-to-cash, and a warm relationship beats cold outreach.
        if ($lapsed !== []) {
            $parts[] = sprintf(
                '%d former client%s gone quiet for %d+ days. A short reactivation message costs almost nothing and reaches someone who has already paid you once.',
                count($lapsed),
                count($lapsed) === 1 ? ' has' : 's have',
                self::QUIET_DAYS
            );
        }

        $stale = count((new PipelineService())->staleLeads());
        if ($stale > 0) {
            $parts[] = sprintf('%d live conversation%s also past the %d-day follow-up mark.', $stale, $stale === 1 ? ' is' : 's are', Settings::int('followup_stale_days', 6));
        }

        return ['text' => implode(' ', $parts), 'provenance' => 'calculated'];
    }
}
