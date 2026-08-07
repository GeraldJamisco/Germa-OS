<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Provenance;

/**
 * Intelligence → Research.
 *
 * The spec asks the Research Director for two things that most notes apps do not
 * do. First, label every claim: FACT, ESTIMATE or ASSUMPTION, because a price
 * someone quoted and a price you are guessing at should never look the same on a
 * screen. Second, "do not assume old information is still accurate".
 *
 * So a note here is not a permanent truth. It has a date it was checked and a
 * shelf life, and when that runs out the note moves to the top of the screen as
 * something to re-check rather than quietly continuing to look true. An
 * assumption gets a shorter default shelf life than a verified fact, because it
 * deserves less patience.
 */
final class ResearchService
{
    public const KINDS = [
        'fact'       => 'FACT',
        'estimate'   => 'ESTIMATE',
        'assumption' => 'ASSUMPTION',
    ];

    /** Default shelf life by kind, in days. A guess should expire soonest. */
    public const SHELF_LIFE = ['fact' => 180, 'estimate' => 90, 'assumption' => 45];

    /** Inside this many days of expiry, a note is "going stale" rather than fine. */
    private const WARN_WINDOW = 14;

    /** @return array<string,mixed> */
    public function build(): array
    {
        $notes = $this->notes();

        $expired = array_values(array_filter($notes, static fn (array $n): bool => $n['state'] === 'expired'));
        $ageing  = array_values(array_filter($notes, static fn (array $n): bool => $n['state'] === 'ageing'));
        $current = array_values(array_filter($notes, static fn (array $n): bool => $n['state'] === 'current'));

        return [
            'insight'     => $this->insight($notes, $expired, $ageing),
            'summary'     => $this->summary($notes, $expired),
            'recheck'     => array_merge($expired, $ageing),
            'groups'      => $this->groups($notes),
            'current'     => $current,
            'total'       => count($notes),
            'questions'   => $this->openQuestions(),
            'suggestions' => [
                'What do I need to find out before deciding?',
                'What am I assuming that I have not checked?',
                'What does this cost in Kampala right now?',
                'Is this market real or am I guessing?',
                'Call the board',
            ],
        ];
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function notes(): array
    {
        $rows = Database::all(
            'SELECT * FROM research_notes WHERE archived = 0
             ORDER BY DATE_ADD(checked_on, INTERVAL shelf_life_days DAY) ASC'
        );

        return array_map(fn (array $row): array => $this->row($row), $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function row(array $row): array
    {
        $kind     = (string) $row['kind'];
        $checked  = (string) $row['checked_on'];
        $shelf    = (int) $row['shelf_life_days'];
        $expires  = (new \DateTimeImmutable($checked, Clock::zone()))->modify('+' . $shelf . ' days');
        $daysLeft = Clock::daysUntil($expires->format('Y-m-d'));

        $state = match (true) {
            $daysLeft < 0                    => 'expired',
            $daysLeft <= self::WARN_WINDOW    => 'ageing',
            default                           => 'current',
        };

        return [
            'id'         => (int) $row['id'],
            'question'   => (string) $row['question'],
            'finding'    => (string) $row['finding'],
            'kind'       => $kind,
            'kind_label' => self::KINDS[$kind] ?? strtoupper($kind),
            'source'     => $row['source'] ?? null,
            'applies_to' => $row['applies_to'] ?? null,
            'checked_on' => $checked,
            'checked_label' => 'Checked ' . $this->day($checked),
            'days_left'  => $daysLeft,
            'state'      => $state,
            'state_label' => match ($state) {
                'expired' => $daysLeft === -1
                    ? 'Out of date by a day'
                    : sprintf('Out of date by %d days', abs($daysLeft)),
                'ageing'  => $daysLeft === 0
                    ? 'Expires today'
                    : sprintf('Expires in %d day%s', $daysLeft, $daysLeft === 1 ? '' : 's'),
                default   => sprintf('Good for %d more days', $daysLeft),
            },
            'tone'       => match ($state) {
                'expired' => 'high',
                'ageing'  => 'warn',
                default   => 'good',
            },
            // Re-checking is the action, and the question is already written down,
            // so the board can be asked it verbatim.
            'ask'        => sprintf(
                'Re-check this and tell me if it still holds: %s Currently recorded as: %s',
                rtrim((string) $row['question'], '?') . '?',
                (string) $row['finding']
            ),
        ];
    }

    /**
     * Notes grouped by how they are known. Assumptions last, because the screen
     * should not let a guess sit at the top looking like a finding.
     *
     * @param list<array<string,mixed>> $notes
     * @return list<array<string,mixed>>
     */
    private function groups(array $notes): array
    {
        $groups = [];

        foreach (self::KINDS as $kind => $label) {
            $rows = array_values(array_filter($notes, static fn (array $n): bool => $n['kind'] === $kind));

            $groups[] = [
                'kind'  => $kind,
                'label' => $label,
                'sub'   => match ($kind) {
                    'fact'     => 'Verified, with a source',
                    'estimate' => 'Reasoned from something real',
                    default    => 'Believed, but never checked',
                },
                'count' => count($rows),
                'rows'  => $rows,
                'stale' => count(array_filter($rows, static fn (array $n): bool => $n['state'] === 'expired')),
            ];
        }

        return $groups;
    }

    /**
     * Questions asked of the board that read like research questions but have no
     * recorded finding. The point is not to be clever about it: if you asked and
     * never wrote down the answer, the answer is gone.
     *
     * @return list<array<string,mixed>>
     */
    private function openQuestions(): array
    {
        $rows = Database::all(
            "SELECT prompt, created_at FROM commands
             WHERE error IS NULL
               AND (prompt LIKE '%how much%' OR prompt LIKE '%what does%' OR prompt LIKE '%cost%'
                    OR prompt LIKE '%market%' OR prompt LIKE '%price%' OR prompt LIKE '%charge%')
             ORDER BY created_at DESC LIMIT 20"
        );

        $recorded = array_map(
            static fn (array $r): string => mb_strtolower((string) $r['question']),
            Database::all('SELECT question FROM research_notes WHERE archived = 0')
        );

        $questions = [];

        foreach ($rows as $row) {
            $prompt = trim((string) $row['prompt']);
            $key    = mb_strtolower($prompt);

            foreach ($recorded as $known) {
                if ($known !== '' && (str_contains($key, $known) || str_contains($known, $key))) {
                    continue 2;
                }
            }

            $questions[] = [
                'prompt' => $prompt,
                'when'   => Clock::relative((string) $row['created_at']),
                'ask'    => $prompt,
            ];

            if (count($questions) === 4) {
                break;
            }
        }

        return $questions;
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $notes
     * @param list<array<string,mixed>> $expired
     * @param list<array<string,mixed>> $ageing
     * @return array{text:string,provenance:string}
     */
    private function insight(array $notes, array $expired, array $ageing): array
    {
        if ($notes === []) {
            return [
                'text' => 'Nothing recorded. Every decision rests on beliefs about prices, customers and '
                    . 'competitors, and the ones you have never written down are the ones you cannot check. '
                    . 'Record one and label how you know it.',
                'provenance' => Provenance::Unavailable->value,
            ];
        }

        $assumptions = count(array_filter($notes, static fn (array $n): bool => $n['kind'] === 'assumption'));
        $estimates   = count(array_filter($notes, static fn (array $n): bool => $n['kind'] === 'estimate'));
        $facts       = count(array_filter($notes, static fn (array $n): bool => $n['kind'] === 'fact'));
        $parts       = [];

        // All three counts, because "4 recorded: 1 verified, 1 assumed" reads as
        // a partition that does not add up.
        $parts[] = sprintf(
            '%d thing%s recorded: %d verified, %d estimated, %d assumed.',
            count($notes),
            count($notes) === 1 ? '' : 's',
            $facts,
            $estimates,
            $assumptions
        );

        if ($expired !== []) {
            $parts[] = sprintf(
                '%d %s out of date, starting with "%s Old information does not announce itself, which is why it is dangerous.',
                count($expired),
                count($expired) === 1 ? 'is' : 'are',
                rtrim((string) $expired[0]['question'], '?') . '?"'
            );
        } elseif ($ageing !== []) {
            $parts[] = sprintf(
                '%d %s within a fortnight of expiring. Re-check before leaning on %s.',
                count($ageing),
                count($ageing) === 1 ? 'is' : 'are',
                count($ageing) === 1 ? 'it' : 'them'
            );
        } else {
            $parts[] = 'Nothing has gone out of date.';
        }

        // The structural point: what proportion of your beliefs are unverified.
        if ($assumptions > $facts && count($notes) >= 3) {
            $parts[] = 'More of what you are working from is assumed than verified. '
                . 'Any one of those assumptions turning out wrong changes a plan built on it.';
        }

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    /**
     * @param list<array<string,mixed>> $notes
     * @param list<array<string,mixed>> $expired
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $notes, array $expired): array
    {
        $count = static fn (string $kind): int => count(
            array_filter($notes, static fn (array $n): bool => $n['kind'] === $kind)
        );

        $facts       = $count('fact');
        $estimates   = $count('estimate');
        $assumptions = $count('assumption');

        return [
            [
                'label' => 'Verified facts',
                'value' => $facts === 0 ? 'None' : (string) $facts,
                'tone'  => $facts === 0 ? 'warn' : 'good',
            ],
            [
                'label' => 'Estimates',
                'value' => $estimates === 0 ? 'None' : (string) $estimates,
                'tone'  => 'neutral',
            ],
            [
                'label' => 'Unchecked assumptions',
                'value' => $assumptions === 0 ? 'None' : (string) $assumptions,
                'tone'  => $assumptions > $facts ? 'bad' : 'neutral',
            ],
            [
                'label' => 'Out of date',
                'value' => $expired === [] ? 'None' : (string) count($expired),
                'tone'  => $expired === [] ? 'good' : 'bad',
            ],
        ];
    }

    private function day(string $date): string
    {
        return (new \DateTimeImmutable($date, Clock::zone()))->format('j M Y');
    }
}
