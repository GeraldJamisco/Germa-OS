<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Provenance;

/**
 * Intelligence → Decisions.
 *
 * The spec's DECISION FRAMEWORK ends with two fields that most decision logs
 * leave out: what to do next, and how you will know it worked. This screen is
 * built around those two, because a decision with neither is a preference.
 *
 * So the accountability signal here is not "how many decisions have you made".
 * It is how many are unfalsifiable: no metric to watch, or no date on which
 * anyone looks again. The spec's own words: do not assume old information is
 * still accurate.
 */
final class DecisionService
{
    public const FILTERS = [
        'live'     => 'Live',
        'open'     => 'Awaiting a call',
        'closed'   => 'Closed',
        'all'      => 'All',
    ];

    /** @return array<string,mixed> */
    public function build(string $filter = 'live'): array
    {
        $filter = array_key_exists($filter, self::FILTERS) ? $filter : 'live';
        $all    = $this->all();

        $open   = array_values(array_filter($all, static fn (array $d): bool => $d['status'] === 'open'));
        $live   = array_values(array_filter($all, static fn (array $d): bool => $d['status'] === 'decided'));
        $closed = array_values(array_filter(
            $all,
            static fn (array $d): bool => in_array($d['status'], ['reviewed', 'reversed'], true)
        ));

        $unfalsifiable = array_values(array_filter($live, static fn (array $d): bool => $d['unfalsifiable']));
        $dueReview     = array_values(array_filter($live, static fn (array $d): bool => $d['review_due']));

        return [
            'filter'        => $filter,
            'filters'       => $this->filterOptions($filter, $open, $live, $closed),
            'insight'       => $this->insight($all, $open, $live, $dueReview, $unfalsifiable, $closed),
            'summary'       => $this->summary($open, $live, $dueReview, $closed),
            'open'          => $open,
            'live'          => $live,
            'closed'        => $closed,
            'due_review'    => $dueReview,
            'unfalsifiable' => $unfalsifiable,
            'rows'          => match ($filter) {
                'open'   => $open,
                'closed' => $closed,
                'all'    => $all,
                default  => $live,
            },
            'suggestions'   => [
                'Give me the decision framework on this',
                'What am I deciding by not deciding?',
                'Was that the right call?',
                'What should I stop doing?',
                'Call the board',
            ],
        ];
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function all(): array
    {
        $rows = Database::all(
            'SELECT * FROM decisions
             ORDER BY FIELD(status, :open, :decided, :reviewed, :reversed),
                      COALESCE(review_on, decided_on, created_at) ASC',
            ['open' => 'open', 'decided' => 'decided', 'reviewed' => 'reviewed', 'reversed' => 'reversed']
        );

        return array_map(fn (array $row): array => $this->row($row), $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function row(array $row): array
    {
        $status     = (string) $row['status'];
        $metric     = $row['metric'] ?? null;
        $reviewOn   = $row['review_on'] ?? null;
        $reviewDays = $reviewOn === null ? null : Clock::daysUntil((string) $reviewOn);

        // Decided, but with nothing to watch and no date to look again: there is
        // no way for this to turn out to have been wrong.
        $unfalsifiable = $status === 'decided'
            && (($metric === null || trim((string) $metric) === '') || $reviewOn === null);

        return [
            'id'             => (int) $row['id'],
            'title'          => (string) $row['title'],
            'situation'      => $row['situation'] ?? null,
            'options'        => $this->options($row['options_json'] ?? null),
            'recommendation' => $row['recommendation'] ?? null,
            'next_action'    => $row['next_action'] ?? null,
            'metric'         => $metric,
            'has_metric'     => $metric !== null && trim((string) $metric) !== '',
            'status'         => $status,
            'status_label'   => $this->statusLabel($status),
            'status_tone'    => $this->statusTone($status),
            'decided_on'     => $row['decided_on'] ?? null,
            'decided_label'  => $row['decided_on'] === null
                ? 'Not yet decided'
                : 'Decided ' . $this->day((string) $row['decided_on']),
            'review_on'      => $reviewOn,
            'review_days'    => $reviewDays,
            'review_label'   => $this->reviewLabel($status, $reviewOn, $reviewDays),
            'review_due'     => $status === 'decided' && $reviewDays !== null && $reviewDays <= 0,
            'unfalsifiable'  => $unfalsifiable,
            'outcome'        => $row['outcome'] ?? null,
            'age_label'      => $this->age((string) $row['created_at']),
            'ask'            => $status === 'open'
                ? sprintf('Give me the decision framework on: %s', $row['title'])
                : sprintf('Review the decision "%s". Did it work, and what does the evidence say?', $row['title']),
        ];
    }

    /**
     * The spec lists options with upside, downside, cost and probability. They
     * are stored as JSON so the shape can grow without a migration, and read
     * defensively because hand-edited JSON is a real possibility.
     *
     * @return list<array{label:string,note:?string}>
     */
    private function options(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        $options = [];

        foreach ($decoded as $option) {
            if (is_string($option)) {
                $options[] = ['label' => $option, 'note' => null];
                continue;
            }
            if (is_array($option) && isset($option['label'])) {
                $options[] = [
                    'label' => (string) $option['label'],
                    'note'  => isset($option['note']) ? (string) $option['note'] : null,
                ];
            }
        }

        return $options;
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $all
     * @param list<array<string,mixed>> $open
     * @param list<array<string,mixed>> $live
     * @param list<array<string,mixed>> $dueReview
     * @param list<array<string,mixed>> $unfalsifiable
     * @param list<array<string,mixed>> $closed
     * @return array{text:string,provenance:string}
     */
    private function insight(array $all, array $open, array $live, array $dueReview, array $unfalsifiable, array $closed): array
    {
        if ($all === []) {
            return [
                'text' => 'No decisions recorded. The point of writing one down is not the record, '
                    . 'it is that a decision with a metric and a review date can be shown to have been wrong. '
                    . 'Without that, every past call looks like it worked.',
                'provenance' => Provenance::Unavailable->value,
            ];
        }

        $parts = [];

        if ($open !== []) {
            $parts[] = sprintf(
                '%d decision%s waiting on you%s.',
                count($open),
                count($open) === 1 ? '' : 's',
                count($open) === 1 ? ': ' . $open[0]['title'] : ''
            );
        }

        if ($dueReview !== []) {
            $worst = $dueReview[0];
            $parts[] = sprintf(
                '%s is past its review date%s. Look at it before making the next call in the same area.',
                $worst['title'],
                $worst['review_days'] === null || $worst['review_days'] === 0
                    ? ''
                    : sprintf(' by %d days', abs((int) $worst['review_days']))
            );
        }

        // The real finding: decisions that cannot be graded.
        if ($unfalsifiable !== []) {
            $parts[] = sprintf(
                '%d of your %d live decision%s %s no metric or no review date, so %s cannot be judged later.',
                count($unfalsifiable),
                count($live),
                count($live) === 1 ? '' : 's',
                count($unfalsifiable) === 1 ? 'has' : 'have',
                count($unfalsifiable) === 1 ? 'it' : 'they'
            );
        } elseif ($live !== []) {
            $parts[] = sprintf(
                'All %d live decision%s carry a metric and a review date, which is what makes them checkable.',
                count($live),
                count($live) === 1 ? '' : 's'
            );
        }

        $reversed = count(array_filter($closed, static fn (array $d): bool => $d['status'] === 'reversed'));

        if ($reversed > 0) {
            $parts[] = sprintf(
                '%d %s been reversed. That is the system working, not a failure: an unreversed record usually means nobody checked.',
                $reversed,
                $reversed === 1 ? 'has' : 'have'
            );
        }

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }

    /**
     * @param list<array<string,mixed>> $open
     * @param list<array<string,mixed>> $live
     * @param list<array<string,mixed>> $dueReview
     * @param list<array<string,mixed>> $closed
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $open, array $live, array $dueReview, array $closed): array
    {
        return [
            [
                'label' => 'Awaiting a call',
                'value' => $open === [] ? 'None' : (string) count($open),
                'tone'  => $open === [] ? 'good' : 'warn',
            ],
            [
                'label' => 'Live decisions',
                'value' => $live === [] ? 'None' : (string) count($live),
                'tone'  => 'neutral',
            ],
            [
                'label' => 'Past review date',
                'value' => $dueReview === [] ? 'None' : (string) count($dueReview),
                'tone'  => $dueReview === [] ? 'good' : 'bad',
            ],
            [
                'label' => 'Closed and graded',
                'value' => $closed === [] ? 'None' : (string) count($closed),
                'tone'  => 'neutral',
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $open
     * @param list<array<string,mixed>> $live
     * @param list<array<string,mixed>> $closed
     * @return list<array{key:string,label:string,count:?int,active:bool}>
     */
    private function filterOptions(string $active, array $open, array $live, array $closed): array
    {
        $counts = [
            'live'   => count($live),
            'open'   => count($open),
            'closed' => count($closed),
            'all'    => count($live) + count($open) + count($closed),
        ];

        $options = [];

        foreach (self::FILTERS as $key => $label) {
            $options[] = [
                'key'    => $key,
                'label'  => $label,
                'count'  => $counts[$key] ?? null,
                'active' => $key === $active,
            ];
        }

        return $options;
    }

    // ── Formatting ──────────────────────────────────────────────────────────

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'open'     => 'NEEDS A CALL',
            'decided'  => 'DECIDED',
            'reviewed' => 'REVIEWED',
            'reversed' => 'REVERSED',
            default    => strtoupper($status),
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'open'     => 'warn',
            'decided'  => 'good',
            'reversed' => 'high',
            default    => 'quiet',
        };
    }

    private function reviewLabel(string $status, ?string $reviewOn, ?int $days): string
    {
        if ($status !== 'decided') {
            return $reviewOn === null ? 'No review date' : 'Reviewed';
        }
        if ($reviewOn === null) {
            return 'No review date set';
        }
        if ($days === 0) {
            return 'Review due today';
        }
        if ($days !== null && $days < 0) {
            return sprintf('Review %d day%s overdue', abs($days), abs($days) === 1 ? '' : 's');
        }

        return sprintf('Review in %d day%s', (int) $days, $days === 1 ? '' : 's');
    }

    private function day(string $date): string
    {
        return (new \DateTimeImmutable($date, Clock::zone()))->format('j M Y');
    }

    private function age(string $createdAt): string
    {
        $days = abs(Clock::daysUntil((new \DateTimeImmutable($createdAt, Clock::zone()))->format('Y-m-d')));

        return match (true) {
            $days === 0 => 'Logged today',
            $days === 1 => 'Logged yesterday',
            $days < 30  => sprintf('Logged %d days ago', $days),
            default     => 'Logged ' . $this->day($createdAt),
        };
    }
}
