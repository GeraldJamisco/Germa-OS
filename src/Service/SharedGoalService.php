<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Money;
use Germa\Support\Provenance;

/**
 * Household → Shared Goals.
 *
 * A shared goal is one somebody else is counting on, and that changes what
 * honesty about it requires. A business goal that slips costs a quarter. A shared
 * one that slips quietly costs trust, and the spec puts relationship commitments
 * and shared goals in the Life & Household Director's remit for that reason.
 *
 * So this screen refuses one particular comfort: it separates goals that money is
 * actually accumulating toward from goals nobody is funding. A savings goal with
 * deposits is a plan. The same goal with no deposits is a conversation someone is
 * still expecting to have gone somewhere, and the difference should be visible
 * without reading a bar chart carefully.
 */
final class SharedGoalService
{
    public function __construct(
        private readonly FinanceService $finance = new FinanceService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $goals   = $this->sharedGoals();
        $savings = $this->savingsGoals();

        $funded   = array_values(array_filter($savings, static fn (array $g): bool => $g['funded']));
        $unfunded = array_values(array_filter($savings, static fn (array $g): bool => !$g['funded']));

        return [
            'insight'     => $this->insight($goals, $savings, $unfunded),
            'summary'     => $this->summary($goals, $savings, $funded),
            'goals'       => $goals,
            'savings'     => $savings,
            'funded'      => $funded,
            'unfunded'    => $unfunded,
            'total'       => count($goals) + count($savings),
            'suggestions' => [
                'What have I promised that I am not funding?',
                'How do I explain a delay on this honestly?',
                'What would it take to fund this by December?',
                'Which shared goal should we drop?',
                'Call the board',
            ],
        ];
    }

    // ── Shared goals from the goals table ───────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function sharedGoals(): array
    {
        $rows = Database::all(
            "SELECT g.*,
                    (SELECT COUNT(*) FROM goal_milestones m WHERE m.goal_id = g.id AND m.done_on IS NULL) AS open_milestones,
                    (SELECT m.title FROM goal_milestones m WHERE m.goal_id = g.id AND m.done_on IS NULL
                      ORDER BY m.target_on IS NULL, m.target_on LIMIT 1) AS next_step
             FROM goals g
             WHERE g.status = 'active' AND g.scope IN ('household', 'shared')
             ORDER BY FIELD(g.horizon, '30d', '90d', '1y', 'long'), g.sort_order"
        );

        $goals = [];

        foreach ($rows as $row) {
            $target  = (int) $row['target_value'];
            $current = (int) $row['current_value'];
            $pct     = $target > 0 ? min(100.0, ($current / $target) * 100) : 0.0;
            $days    = $row['target_on'] === null ? null : Clock::daysUntil((string) $row['target_on']);

            $goals[] = [
                'id'        => (int) $row['id'],
                'title'     => (string) $row['title'],
                'why'       => $row['why'] ?? null,
                'scope'     => (string) $row['scope'],
                'scope_label' => (string) $row['scope'] === 'shared' ? 'SHARED' : 'HOUSEHOLD',
                'progress_pct' => $pct,
                'progress_label' => $pct > 0 && $pct < 1 ? '<1%' : round($pct) . '%',
                'detail'    => $target > 0 && (string) $row['metric'] === 'money'
                    ? Money::of($current, (string) ($row['currency'] ?? Money::base()))->compact()
                        . ' of ' . Money::of($target, (string) ($row['currency'] ?? Money::base()))->compact()
                    : null,
                'days_left' => $days,
                'when'      => $this->whenLabel($days),
                'overdue'   => $days !== null && $days < 0,
                'next_step' => $row['next_step'] ?? null,
                'ask'       => $row['next_step'] === null
                    ? sprintf('Break this shared goal into milestones we can both see: %s', $row['title'])
                    : sprintf('What is the fastest honest route to this: %s', $row['next_step']),
            ];
        }

        return $goals;
    }

    // ── Savings goals, which are household by nature ────────────────────────

    /**
     * Savings goals with the one distinction that matters: is money actually going
     * in. Progress alone hides a goal that reached 40% a year ago and has had
     * nothing since.
     *
     * @return list<array<string,mixed>>
     */
    private function savingsGoals(): array
    {
        $rows = Database::all(
            "SELECT * FROM savings_goals WHERE status = 'active' ORDER BY priority, id"
        );

        $goals = [];

        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $saved   = $this->savedToward($id);
            $target  = $this->targetFor($row);
            $pct     = $target->minor > 0 ? min(100.0, ($saved->minor / $target->minor) * 100) : 0.0;
            $lastIn  = $this->lastDeposit($id);
            $sinceIn = $lastIn === null ? null : abs(Clock::daysUntil($lastIn));

            // Funded means money moved recently, not that the total looks healthy.
            $funded = $sinceIn !== null && $sinceIn <= 45;

            $goals[] = [
                'id'      => $id,
                'name'    => (string) $row['name'],
                'kind'    => (string) $row['kind'],
                'note'    => $row['note'] ?? null,
                'saved'   => $saved,
                'target'  => $target,
                'progress_pct' => $pct,
                'progress_label' => $pct > 0 && $pct < 1 ? '<1%' : round($pct) . '%',
                'detail'  => $saved->compact() . ' of ' . $target->compact(),
                'funded'  => $funded,
                'last_in' => $lastIn,
                'since_label' => match (true) {
                    $lastIn === null => 'Nothing has ever gone in',
                    $sinceIn === 0   => 'Deposit made today',
                    $sinceIn === 1   => 'Last deposit yesterday',
                    default          => sprintf('Last deposit %d days ago', (int) $sinceIn),
                },
                'tone'    => $funded ? 'good' : ($lastIn === null ? 'high' : 'warn'),
                'ask'     => sprintf(
                    'This household goal has had no deposit in %s. What would it take to fund it, and what gives way to make room?',
                    $lastIn === null ? 'its lifetime' : sprintf('%d days', (int) $sinceIn)
                ),
            ];
        }

        return $goals;
    }

    private function savedToward(int $goalId): Money
    {
        $minor = (int) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_minor ELSE -amount_minor END), 0)
             FROM savings_entries WHERE goal_id = :id AND currency = :c",
            ['id' => $goalId, 'c' => Money::base()],
            0
        );

        return Money::of(max($minor, 0));
    }

    /** @param array<string,mixed> $row */
    private function targetFor(array $row): Money
    {
        // An emergency target follows the household rather than being typed in, so
        // it cannot go stale when the survival cost changes.
        if ((string) $row['kind'] === 'emergency') {
            $months = max(1, (int) $row['months_cover']);

            return Money::of($this->finance->monthlySurvivalCost()->minor * $months);
        }

        return Money::of((int) $row['target_minor'], (string) $row['currency']);
    }

    private function lastDeposit(int $goalId): ?string
    {
        $date = Database::value(
            "SELECT MAX(occurred_on) FROM savings_entries
             WHERE goal_id = :id AND direction = 'in'",
            ['id' => $goalId]
        );

        return $date === null || $date === '' ? null : (string) $date;
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $goals
     * @param list<array<string,mixed>> $savings
     * @param list<array<string,mixed>> $unfunded
     * @return array{text:string,provenance:string}
     */
    private function insight(array $goals, array $savings, array $unfunded): array
    {
        if ($goals === [] && $savings === []) {
            return [
                'text' => 'Nothing shared recorded. A goal the household is counting on should be written down '
                    . 'where both of you can see the same version of it, rather than living in a conversation '
                    . 'you each remember differently.',
                'provenance' => Provenance::Unavailable->value,
            ];
        }

        $parts = [];

        $parts[] = sprintf(
            '%d shared commitment%s on record.',
            count($goals) + count($savings),
            count($goals) + count($savings) === 1 ? '' : 's'
        );

        if ($unfunded !== []) {
            $never = array_values(array_filter($unfunded, static fn (array $g): bool => $g['last_in'] === null));

            if ($never !== []) {
                $parts[] = sprintf(
                    '%s has never received a deposit. Until money moves toward it, it is an intention, and calling it a goal only makes the eventual conversation harder.',
                    $never[0]['name']
                );
            } else {
                $parts[] = sprintf(
                    '%s %s stalled: nothing has gone in for over six weeks. Progress that stopped a while ago still shows as progress, which is the problem.',
                    count($unfunded) === 1 ? $unfunded[0]['name'] . ' has' : count($unfunded) . ' have',
                    ''
                );
            }
        }

        $overdue = array_values(array_filter($goals, static fn (array $g): bool => $g['overdue']));

        if ($overdue !== []) {
            $parts[] = sprintf(
                '%s is past the date it was promised for. Say so before it is noticed.',
                $overdue[0]['title']
            );
        }

        $unbroken = array_values(array_filter($goals, static fn (array $g): bool => $g['next_step'] === null));

        if ($unbroken !== []) {
            $parts[] = sprintf(
                '%d %s no next step written down, which makes %s hard to talk about honestly.',
                count($unbroken),
                count($unbroken) === 1 ? 'has' : 'have',
                count($unbroken) === 1 ? 'it' : 'them'
            );
        }

        return [
            'text'       => preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? implode(' ', $parts),
            'provenance' => Provenance::Calculated->value,
        ];
    }

    /**
     * @param list<array<string,mixed>> $goals
     * @param list<array<string,mixed>> $savings
     * @param list<array<string,mixed>> $funded
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function summary(array $goals, array $savings, array $funded): array
    {
        $stalled = count($savings) - count($funded);
        $overdue = count(array_filter($goals, static fn (array $g): bool => $g['overdue']));

        return [
            [
                'label' => 'Shared commitments',
                'value' => ($goals === [] && $savings === []) ? 'None' : (string) (count($goals) + count($savings)),
                'tone'  => 'neutral',
            ],
            [
                'label' => 'Money actually moving',
                'value' => $savings === [] ? 'n/a' : count($funded) . ' of ' . count($savings),
                'tone'  => $funded === [] && $savings !== [] ? 'bad' : 'good',
            ],
            [
                'label' => 'Stalled or unfunded',
                'value' => $stalled === 0 ? 'None' : (string) $stalled,
                'tone'  => $stalled === 0 ? 'good' : 'warn',
            ],
            [
                'label' => 'Past a promised date',
                'value' => $overdue === 0 ? 'None' : (string) $overdue,
                'tone'  => $overdue === 0 ? 'good' : 'bad',
            ],
        ];
    }

    private function whenLabel(?int $days): string
    {
        return match (true) {
            $days === null => 'No date agreed',
            $days < 0      => sprintf('%d day%s past the date', abs($days), abs($days) === 1 ? '' : 's'),
            $days === 0    => 'Today',
            $days <= 31    => sprintf('%d days left', $days),
            default        => sprintf('%d weeks left', (int) round($days / 7)),
        };
    }
}
