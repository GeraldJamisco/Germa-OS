<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Database;
use Germa\Support\Money;

/**
 * Opportunity mode. The spec ranks by "time-to-cash + probability of success +
 * expected value", and classifies into DO / CONSIDER / IGNORE. The ranking is
 * arithmetic, not taste so it can be argued with.
 */
final class OpportunityService
{
    private const PROBABILITY_WEIGHT = ['Low' => 0.25, 'Medium' => 0.55, 'High' => 0.85];

    /** @return list<array<string,mixed>> */
    public function ranked(int $limit = 2): array
    {
        $rows = Database::all("SELECT * FROM opportunities WHERE status = 'open'");

        $scored = [];

        foreach ($rows as $row) {
            $value       = Money::of((int) $row['potential_value_minor'], (string) $row['currency']);
            $probability = (string) $row['probability'];
            $weight      = self::PROBABILITY_WEIGHT[$probability] ?? 0.5;
            $ttc         = max((int) ($row['time_to_cash_days'] ?? 30), 1);
            $effortDays  = max((float) ($row['effort_days'] ?? 1), 0.25);

            // Expected value per day of waiting, per day of work. Rewards small,
            // likely, fast money exactly what the decision hierarchy wants
            // when survival is the binding constraint.
            $expected = $value->minor * $weight;
            $score    = $expected / ($ttc * $effortDays);

            $scored[] = [
                'id'              => (int) $row['id'],
                'title'           => (string) $row['title'],
                'summary'         => $row['summary'] ?? null,
                'value'           => $value,
                'effort_label'    => (string) ($row['effort_note'] ?? $effortDays . ' days'),
                'probability'     => $probability,
                'time_to_cash'    => $ttc,
                'expected_value'  => Money::of((int) round($expected), (string) $row['currency']),
                'score'           => $score,
                'verdict'         => $this->verdict($score, $ttc, $probability),
                'source'          => (string) $row['source'],
            ];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        foreach ($scored as $index => $opportunity) {
            $scored[$index]['rank']       = sprintf('%02d', $index + 1);
            $scored[$index]['facts']      = [
                ['k' => 'Potential value', 'v' => $opportunity['value']->compact()],
                ['k' => 'Effort',          'v' => $opportunity['effort_label']],
                ['k' => 'Probability',     'v' => $opportunity['probability']],
                ['k' => 'Time to cash',    'v' => $opportunity['time_to_cash'] . ' days'],
            ];

            Database::update('opportunities', [
                'rank_score' => round($opportunity['score'], 2),
                'verdict'    => strtolower($opportunity['verdict']),
            ], ['id' => $opportunity['id']]);
        }

        return array_slice($scored, 0, $limit);
    }

    public function count(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM opportunities WHERE status = 'open'", [], 0);
    }

    /**
     * DO / CONSIDER / IGNORE. Fast money with a real chance of landing gets a
     * DO; anything slow and unlikely is told to go away.
     */
    private function verdict(float $score, int $timeToCash, string $probability): string
    {
        if ($probability === 'Low' && $timeToCash > 21) {
            return 'IGNORE';
        }
        if ($timeToCash <= 14 && $probability !== 'Low' && $score >= 5000) {
            return 'DO';
        }
        if ($score < 500) {
            return 'IGNORE';
        }

        return 'CONSIDER';
    }
}
