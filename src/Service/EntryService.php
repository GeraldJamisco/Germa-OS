<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Hours;
use Germa\Support\Money;
use Germa\Support\Settings;
use InvalidArgumentException;

/**
 * The write side of the Quick Actions row. Everything Gerald records about
 * money, work or people enters through here so validation and the audit-worthy
 * side effects (stage events, snapshots) live in one place.
 */
final class EntryService
{
    /** @param array<string,mixed> $input */
    public function addIncome(array $input): array
    {
        $amount = $this->money($input, 'Income');

        $id = Database::insert('ledger_entries', [
            'direction'    => 'in',
            'amount_minor' => $amount->minor,
            'currency'     => $amount->currency,
            'occurred_on'  => $this->date($input),
            'category'     => (string) ($input['category'] ?? 'client_payment'),
            'description'  => $this->text($input, 'description', 200),
            'is_essential' => 1,
            'project_id'   => $this->id($input, 'project_id'),
            'deal_id'      => $this->id($input, 'deal_id'),
            'source'       => 'entered',
        ]);

        return ['id' => $id, 'message' => $amount->format() . ' recorded as income.'];
    }

    /** @param array<string,mixed> $input */
    public function addExpense(array $input): array
    {
        $amount    = $this->money($input, 'Expense');
        $essential = !isset($input['is_essential']) || (bool) $input['is_essential'];

        $id = Database::insert('ledger_entries', [
            'direction'    => 'out',
            'amount_minor' => $amount->minor,
            'currency'     => $amount->currency,
            'occurred_on'  => $this->date($input),
            'category'     => (string) ($input['category'] ?? 'general'),
            'description'  => $this->text($input, 'description', 200),
            'is_essential' => $essential ? 1 : 0,
            'project_id'   => $this->id($input, 'project_id'),
            'source'       => 'entered',
        ]);

        return [
            'id'      => $id,
            'message' => $amount->format() . ' recorded as ' . ($essential ? 'essential' : 'non-essential') . ' spending.',
        ];
    }

    /**
     * Records a payment against a debt. Also writes a matching ledger entry
     * money leaving the household is money leaving the household, and the
     * runway has to reflect it.
     *
     * @param array<string,mixed> $input
     */
    public function addDebtPayment(array $input): array
    {
        $debtId = $this->id($input, 'debt_id');

        if ($debtId === null) {
            $debtId = $this->id(['debt_id' => Database::value("SELECT id FROM debts WHERE status = 'active' ORDER BY id LIMIT 1")], 'debt_id');
        }

        if ($debtId === null) {
            throw new InvalidArgumentException('There is no active debt to pay.');
        }

        $debt = Database::first('SELECT currency FROM debts WHERE id = :id', ['id' => $debtId]);
        if ($debt === null) {
            throw new InvalidArgumentException('That debt does not exist.');
        }

        $amount = $this->money($input, 'Payment', (string) $debt['currency']);
        $paidOn = $this->date($input);

        return Database::transaction(function () use ($debtId, $amount, $paidOn, $input): array {
            $id = Database::insert('debt_payments', [
                'debt_id'      => $debtId,
                'amount_minor' => $amount->minor,
                'currency'     => $amount->currency,
                'due_on'       => $input['due_on'] ?? $paidOn,
                'paid_on'      => $paidOn,
                'status'       => 'paid',
                'note'         => $this->text($input, 'note', 200),
            ]);

            $baseAmount = $this->inBaseCurrency($amount);
            if ($baseAmount !== null) {
                Database::insert('ledger_entries', [
                    'direction'    => 'out',
                    'amount_minor' => $baseAmount->minor,
                    'currency'     => $baseAmount->currency,
                    'occurred_on'  => $paidOn,
                    'category'     => 'debt_payment',
                    'description'  => 'Debt payment',
                    'is_essential' => 1,
                    'source'       => 'system',
                ]);
            }

            $this->settleIfCleared($debtId);

            return ['id' => $id, 'message' => $amount->format() . ' recorded against the debt.'];
        });
    }

    /** @param array<string,mixed> $input */
    public function addTask(array $input): array
    {
        $title = $this->text($input, 'title', 200);

        if ($title === null) {
            throw new InvalidArgumentException('A task needs a title.');
        }

        $tasks = new TaskService();
        $id    = $tasks->create([
            'title'             => $title,
            'detail'            => $this->text($input, 'detail', 2000),
            'project_id'        => $this->id($input, 'project_id'),
            'priority_rank'     => isset($input['priority_rank']) && $input['priority_rank'] !== '' ? (int) $input['priority_rank'] : null,
            'estimated_minutes' => isset($input['estimated_minutes']) && $input['estimated_minutes'] !== '' ? (int) $input['estimated_minutes'] : null,
            'expected_outcome'  => $this->text($input, 'expected_outcome', 160),
            'for_date'          => $this->date($input),
            'origin'            => 'user',
        ]);

        return ['id' => $id, 'message' => 'Task added.'];
    }

    /**
     * A new prospect. Creates the client, the deal, and the stage event that
     * makes it count toward this week's outreach.
     *
     * @param array<string,mixed> $input
     */
    public function addProspect(array $input): array
    {
        $name = $this->text($input, 'name', 120);

        if ($name === null) {
            throw new InvalidArgumentException('A prospect needs a name.');
        }

        $value = isset($input['value']) && $input['value'] !== ''
            ? Money::fromMajor((string) $input['value'])
            : Money::zero();

        return Database::transaction(function () use ($input, $name, $value): array {
            $clientId = Database::insert('clients', [
                'name'  => $name,
                'org'   => $this->text($input, 'org', 120),
                'email' => $this->text($input, 'email', 160),
                'phone' => $this->text($input, 'phone', 60),
                'notes' => $this->text($input, 'notes', 2000),
            ]);

            $dealId = Database::insert('deals', [
                'client_id'       => $clientId,
                'title'           => $this->text($input, 'title', 160) ?? $name,
                'stage'           => 'prospect',
                'value_minor'     => $value->minor,
                'currency'        => $value->currency,
                'probability_pct' => 5,
                'last_contact_on' => $this->date($input),
                'next_action'     => $this->text($input, 'next_action', 200) ?? 'Send intro message',
                'source'          => $this->text($input, 'source', 80) ?? 'Direct outreach',
            ]);

            Database::insert('deal_stage_events', [
                'deal_id'    => $dealId,
                'from_stage' => null,
                'to_stage'   => 'prospect',
                'changed_at' => Clock::now()->format('Y-m-d H:i:s'),
            ]);

            $this->bumpProspectsContacted();

            return ['id' => $dealId, 'message' => $name . ' added to the pipeline.'];
        });
    }

    /** @param array<string,mixed> $input */
    public function addProject(array $input): array
    {
        $name = $this->text($input, 'name', 120);

        if ($name === null) {
            throw new InvalidArgumentException('A project needs a name.');
        }

        $status   = (string) ($input['status'] ?? 'development');
        $priority = strtoupper((string) ($input['priority'] ?? 'LOW'));

        if (!in_array($status, ['revenue', 'strategic', 'validation', 'development', 'paused'], true)) {
            throw new InvalidArgumentException('Unknown project status.');
        }
        if (!in_array($priority, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            throw new InvalidArgumentException('Priority must be HIGH, MEDIUM or LOW.');
        }

        $id = Database::insert('projects', [
            'name'           => $name,
            'purpose'        => $this->text($input, 'purpose', 2000),
            'problem'        => $this->text($input, 'problem', 2000),
            'customer'       => $this->text($input, 'customer', 160),
            'status'         => $status,
            'priority'       => $priority,
            'currency'       => Money::base(),
            // The spec insists every project gets a periodic keep-or-kill call.
            'next_review_on' => Clock::today()->modify('+14 days')->format('Y-m-d'),
        ]);

        return ['id' => $id, 'message' => $name . ' added to the portfolio.'];
    }

    /**
     * A deal at any stage, with the client created alongside it. Records the
     * opening stage event so the deal counts toward this week's movement.
     *
     * @param array<string,mixed> $input
     */
    /**
     * The description of a project: what it is, what it solves, who it is for.
     * This is the field the board reads to judge whether a project is worth doing
     * at all, as opposed to how it is performing. Kept separate from creation so a
     * project added months ago can be described now, which is exactly when the
     * gap tends to be noticed.
     *
     * @param array<string,mixed> $input
     */
    public function describeProject(array $input): array
    {
        $projectId = $this->id($input, 'project_id');

        if ($projectId === null) {
            throw new InvalidArgumentException('Choose the project to describe.');
        }

        $project = Database::first('SELECT name FROM projects WHERE id = :id', ['id' => $projectId]);

        if ($project === null) {
            throw new InvalidArgumentException('That project no longer exists.');
        }

        $purpose = $this->text($input, 'purpose', 2000);

        if ($purpose === null) {
            throw new InvalidArgumentException('A description needs at least a sentence on what the project is.');
        }

        // Only the fields that were provided are touched, so describing the
        // purpose does not wipe a problem or customer entered earlier.
        $fields = ['purpose' => $purpose];

        if (($problem = $this->text($input, 'problem', 2000)) !== null) {
            $fields['problem'] = $problem;
        }
        if (($customer = $this->text($input, 'customer', 160)) !== null) {
            $fields['customer'] = $customer;
        }

        Database::update('projects', $fields, ['id' => $projectId]);

        return [
            'id'      => $projectId,
            'message' => sprintf('%s described. The board can now judge whether it is worth doing.', (string) $project['name']),
        ];
    }

    /**
     * Hours against a project. Without this the whole Projects group is a
     * read-only story: allocation, at-risk and the Critic's "you are coding to
     * avoid selling" objection are all measured from these rows.
     *
     * @param array<string,mixed> $input
     */
    public function addProjectHours(array $input): array
    {
        $projectId = $this->id($input, 'project_id');

        if ($projectId === null) {
            throw new InvalidArgumentException('Choose the project the hours went to.');
        }

        $project = Database::first('SELECT name FROM projects WHERE id = :id', ['id' => $projectId]);

        if ($project === null) {
            throw new InvalidArgumentException('That project no longer exists.');
        }

        $raw = $input['hours'] ?? null;

        if ($raw === null || $raw === '' || !is_numeric((string) $raw)) {
            throw new InvalidArgumentException('How many hours? A decimal like 1.5 is fine.');
        }

        $minutes = (int) round(((float) $raw) * 60);

        if ($minutes <= 0) {
            throw new InvalidArgumentException('Hours must be more than zero.');
        }
        // The column is a SMALLINT of minutes: one entry covers at most a day.
        if ($minutes > 1440) {
            throw new InvalidArgumentException('That is more than a day in one entry: log it per day instead.');
        }

        $id = Database::insert('project_time_logs', [
            'project_id' => $projectId,
            'minutes'    => $minutes,
            'logged_on'  => $this->date($input, 'logged_on'),
            'note'       => $this->text($input, 'note', 255),
        ]);

        // Progress is optional: worked hours and moved-forward are not the same
        // claim, and conflating them is how a project sits at 90% for months.
        $progress = $input['progress'] ?? null;
        $moved    = '';

        if ($progress !== null && $progress !== '' && is_numeric((string) $progress)) {
            $pct = max(0, min(100, (int) round((float) $progress)));
            Database::update('projects', ['progress_pct' => $pct], ['id' => $projectId]);
            $moved = sprintf(' Now at %d%%.', $pct);
        }

        return [
            'id'      => $id,
            'message' => sprintf(
                '%s logged against %s.%s',
                Hours::format($minutes / 60),
                (string) $project['name'],
                $moved
            ),
        ];
    }

    /**
     * The spec's periodic verdict: CONTINUE, PAUSE, PIVOT or KILL. Recording one
     * resets the review clock, so a decision made today stops the Portfolio
     * screen from flagging the project as overdue.
     *
     * @param array<string,mixed> $input
     */
    public function recordProjectDecision(array $input): array
    {
        $projectId = $this->id($input, 'project_id');

        if ($projectId === null) {
            throw new InvalidArgumentException('Choose the project you are deciding on.');
        }

        $project = Database::first('SELECT name FROM projects WHERE id = :id', ['id' => $projectId]);

        if ($project === null) {
            throw new InvalidArgumentException('That project no longer exists.');
        }

        $decision = strtolower((string) ($input['decision'] ?? ''));

        if (!in_array($decision, ['continue', 'pause', 'pivot', 'kill'], true)) {
            throw new InvalidArgumentException('The verdict must be continue, pause, pivot or kill.');
        }

        $today  = Clock::todayString();
        $fields = [
            'decision'       => $decision,
            'decided_on'     => $today,
            'next_review_on' => Clock::today()->modify('+14 days')->format('Y-m-d'),
            'risk_note'      => $this->text($input, 'note', 255),
        ];

        // A verdict that changes nothing is not a decision. KILL takes the
        // project out of the portfolio; PAUSE stops it claiming to be in flight.
        $tail = ' Next review in 14 days.';

        if ($decision === 'kill') {
            $fields['archived']       = 1;
            $fields['next_review_on'] = null;
            $tail = ' It is out of the portfolio. The hours it was taking are now free.';
        } elseif ($decision === 'pause') {
            $fields['status'] = 'paused';
        }

        Database::update('projects', $fields, ['id' => $projectId]);

        return [
            'id'      => $projectId,
            'message' => sprintf('%s: %s.%s', (string) $project['name'], strtoupper($decision), $tail),
        ];
    }

    /**
     * A decision, logged with the two fields that make it checkable later: what
     * you will watch, and when you will look. Both are optional here because a
     * decision half-recorded beats one not recorded, but the screen counts how
     * many are missing them and says so.
     *
     * @param array<string,mixed> $input
     */
    public function addDecision(array $input): array
    {
        $title = $this->text($input, 'title', 200);

        if ($title === null) {
            throw new InvalidArgumentException('What is the decision about?');
        }

        $status = strtolower((string) ($input['status'] ?? 'open'));

        if (!in_array($status, ['open', 'decided'], true)) {
            throw new InvalidArgumentException('A new decision is either open or already decided.');
        }

        $reviewDays = max(1, min(365, (int) ($input['review_days'] ?? 14)));
        $options    = $this->text($input, 'options', 2000);

        $id = Database::insert('decisions', [
            'title'          => $title,
            'situation'      => $this->text($input, 'situation', 4000),
            // One option per line is the fastest thing to type. JSON is what the
            // column holds, so the shape can grow later without a migration.
            'options_json'   => $options === null ? null : json_encode(
                array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $options) ?: []))),
                JSON_UNESCAPED_UNICODE
            ),
            'recommendation' => $this->text($input, 'recommendation', 4000),
            'next_action'    => $this->text($input, 'next_action', 255),
            'metric'         => $this->text($input, 'metric', 160),
            'status'         => $status,
            'decided_on'     => $status === 'decided' ? Clock::todayString() : null,
            'review_on'      => $status === 'decided'
                ? Clock::today()->modify('+' . $reviewDays . ' days')->format('Y-m-d')
                : null,
        ]);

        return [
            'id'      => $id,
            'message' => $status === 'decided'
                ? sprintf('%s recorded as decided. Review in %d days.', $title, $reviewDays)
                : sprintf('%s logged as awaiting a call.', $title),
        ];
    }

    /**
     * Closing the loop on a decision. The spec's monthly review asks what failed
     * and what was learned, so an outcome is required: a review with no finding
     * is just a date change.
     *
     * @param array<string,mixed> $input
     */
    public function reviewDecision(array $input): array
    {
        $id = $this->id($input, 'decision_id');

        if ($id === null) {
            throw new InvalidArgumentException('Choose the decision you are reviewing.');
        }

        $decision = Database::first('SELECT title, metric FROM decisions WHERE id = :id', ['id' => $id]);

        if ($decision === null) {
            throw new InvalidArgumentException('That decision no longer exists.');
        }

        $verdict = strtolower((string) ($input['verdict'] ?? ''));

        if (!in_array($verdict, ['decided', 'reviewed', 'reversed'], true)) {
            throw new InvalidArgumentException('The verdict must be decided, reviewed or reversed.');
        }

        $outcome = $this->text($input, 'outcome', 4000);

        if ($verdict !== 'decided' && $outcome === null) {
            throw new InvalidArgumentException('What actually happened? A review with no finding changes nothing.');
        }

        $fields = ['status' => $verdict, 'outcome' => $outcome];

        if ($verdict === 'decided') {
            // Moving an open decision to decided is what starts the review clock.
            $days = max(1, min(365, (int) ($input['review_days'] ?? 14)));
            $fields['decided_on'] = Clock::todayString();
            $fields['review_on']  = Clock::today()->modify('+' . $days . ' days')->format('Y-m-d');
            $fields['metric']     = $this->text($input, 'metric', 160) ?? $decision['metric'];
            $tail = sprintf(' Review in %d days.', $days);
        } else {
            $fields['review_on'] = null;
            $tail = $verdict === 'reversed'
                ? ' Reversing on evidence is the system working.'
                : ' Closed with the outcome on record.';
        }

        Database::update('decisions', $fields, ['id' => $id]);

        return [
            'id'      => $id,
            'message' => sprintf('%s: %s.%s', (string) $decision['title'], strtoupper($verdict), $tail),
        ];
    }

    /**
     * A research note, labelled FACT, ESTIMATE or ASSUMPTION as the spec requires,
     * with a shelf life so it cannot sit there looking true forever.
     *
     * @param array<string,mixed> $input
     */
    public function addResearch(array $input): array
    {
        $question = $this->text($input, 'question', 200);
        $finding  = $this->text($input, 'finding', 4000);

        if ($question === null) {
            throw new InvalidArgumentException('What were you trying to find out?');
        }
        if ($finding === null) {
            throw new InvalidArgumentException('What did you find? A question with no finding is still a question.');
        }

        $kind = strtolower((string) ($input['kind'] ?? 'assumption'));

        if (!array_key_exists($kind, ResearchService::KINDS)) {
            throw new InvalidArgumentException('Label it a fact, an estimate or an assumption.');
        }

        $source = $this->text($input, 'source', 255);

        // The label has to mean something. A claim with no source is not a fact,
        // whatever the person entering it would prefer.
        if ($kind === 'fact' && $source === null) {
            throw new InvalidArgumentException('A fact needs a source. Without one it is an assumption.');
        }

        $shelf = isset($input['shelf_life_days']) && is_numeric((string) $input['shelf_life_days'])
            ? max(1, min(1095, (int) $input['shelf_life_days']))
            : ResearchService::SHELF_LIFE[$kind];

        $id = Database::insert('research_notes', [
            'question'        => $question,
            'finding'         => $finding,
            'kind'            => $kind,
            'source'          => $source,
            'applies_to'      => $this->text($input, 'applies_to', 160),
            'checked_on'      => $this->date($input, 'checked_on'),
            'shelf_life_days' => $shelf,
        ]);

        return [
            'id'      => $id,
            'message' => sprintf('Recorded as %s. Re-check in %d days.', ResearchService::KINDS[$kind], $shelf),
        ];
    }

    /**
     * A goal. The horizon decides which rung of the mission ladder it sits on, so
     * it is not decoration: a "long" goal set while cash is short is exactly what
     * the Goals screen is built to flag.
     *
     * @param array<string,mixed> $input
     */
    public function addGoal(array $input): array
    {
        $title = $this->text($input, 'title', 200);

        if ($title === null) {
            throw new InvalidArgumentException('What is the goal?');
        }

        $horizon = (string) ($input['horizon'] ?? '90d');
        $scope   = (string) ($input['scope'] ?? 'business');
        $metric  = (string) ($input['metric'] ?? 'money');

        if (!in_array($horizon, ['30d', '90d', '1y', 'long'], true)) {
            throw new InvalidArgumentException('The horizon must be 30d, 90d, 1y or long.');
        }
        if (!in_array($scope, ['business', 'household', 'shared'], true)) {
            throw new InvalidArgumentException('The scope must be business, household or shared.');
        }
        if (!in_array($metric, ['money', 'count', 'percent', 'binary'], true)) {
            throw new InvalidArgumentException('Unknown goal metric.');
        }

        $target = 0;

        if (isset($input['target']) && is_numeric(preg_replace('/[^0-9.]/', '', (string) $input['target']))) {
            $target = $metric === 'money'
                ? Money::fromMajor((string) $input['target'], Money::base())->minor
                : (int) round((float) preg_replace('/[^0-9.]/', '', (string) $input['target']));
        }

        $days = match ($horizon) {
            '30d'  => 30,
            '90d'  => 90,
            '1y'   => 365,
            default => 1095,
        };

        $id = Database::insert('goals', [
            'horizon'      => $horizon,
            'title'        => $title,
            'metric'       => $metric,
            'target_value' => $target,
            'currency'     => $metric === 'money' ? Money::base() : null,
            'scope'        => $scope,
            'why'          => $this->text($input, 'why', 255),
            'start_on'     => Clock::todayString(),
            'target_on'    => Clock::today()->modify('+' . $days . ' days')->format('Y-m-d'),
            // The spec's chain ends in Review, so a goal gets a review date the
            // moment it is created rather than when someone remembers to add one.
            'review_on'    => Clock::today()->modify('+14 days')->format('Y-m-d'),
        ]);

        return [
            'id'      => $id,
            'message' => sprintf('%s added on the %s horizon. Give it a milestone next.', $title, $horizon),
        ];
    }

    /**
     * A milestone under a goal. The metric is what "done" means, and without it
     * finishing becomes a matter of opinion.
     *
     * @param array<string,mixed> $input
     */
    public function addGoalMilestone(array $input): array
    {
        $goalId = $this->id($input, 'goal_id');

        if ($goalId === null) {
            throw new InvalidArgumentException('Which goal is this a milestone for?');
        }

        $goal = Database::first("SELECT title FROM goals WHERE id = :id AND status = 'active'", ['id' => $goalId]);

        if ($goal === null) {
            throw new InvalidArgumentException('That goal is not active.');
        }

        $title = $this->text($input, 'title', 200);

        if ($title === null) {
            throw new InvalidArgumentException('What is the milestone?');
        }

        $order = (int) Database::value(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM goal_milestones WHERE goal_id = :id',
            ['id' => $goalId],
            1
        );

        $id = Database::insert('goal_milestones', [
            'goal_id'    => $goalId,
            'title'      => $title,
            'metric'     => $this->text($input, 'metric', 160),
            'target_on'  => isset($input['target_on']) && $input['target_on'] !== ''
                ? $this->date($input, 'target_on')
                : null,
            'sort_order' => $order,
        ]);

        return [
            'id'      => $id,
            'message' => sprintf('Milestone added to %s.', (string) $goal['title']),
        ];
    }

    /**
     * Marking a milestone done. Recorded against a date rather than a flag, so the
     * timeline can show when things actually landed.
     *
     * @param array<string,mixed> $input
     */
    public function completeGoalMilestone(array $input): array
    {
        $id = $this->id($input, 'milestone_id');

        if ($id === null) {
            throw new InvalidArgumentException('Which milestone is done?');
        }

        $row = Database::first(
            'SELECT m.title, m.done_on, g.title AS goal FROM goal_milestones m
             JOIN goals g ON g.id = m.goal_id WHERE m.id = :id',
            ['id' => $id]
        );

        if ($row === null) {
            throw new InvalidArgumentException('That milestone no longer exists.');
        }
        if ($row['done_on'] !== null) {
            throw new InvalidArgumentException('That milestone is already marked done.');
        }

        Database::update('goal_milestones', ['done_on' => $this->date($input, 'done_on')], ['id' => $id]);

        return [
            'id'      => $id,
            'message' => sprintf('%s done. Next milestone on %s is now the step.', (string) $row['title'], (string) $row['goal']),
        ];
    }

    /**
     * A household commitment: what is owed to people rather than to creditors. The
     * cadence is the whole point, because it is what makes "overdue" mean
     * something concrete rather than a feeling.
     *
     * @param array<string,mixed> $input
     */
    public function addCommitment(array $input): array
    {
        $title = $this->text($input, 'title', 160);

        if ($title === null) {
            throw new InvalidArgumentException('What is the commitment?');
        }

        $kind = strtolower((string) ($input['kind'] ?? 'relationship'));

        if (!in_array($kind, ['relationship', 'responsibility', 'rest', 'development'], true)) {
            throw new InvalidArgumentException('The kind must be relationship, responsibility, rest or development.');
        }

        $cadence = isset($input['cadence_days']) && is_numeric((string) $input['cadence_days'])
            ? max(0, min(365, (int) $input['cadence_days']))
            : 7;

        $id = Database::insert('household_commitments', [
            'title'            => $title,
            'kind'             => $kind,
            'cadence_days'     => $cadence,
            'last_honoured_on' => isset($input['last_honoured_on']) && $input['last_honoured_on'] !== ''
                ? $this->date($input, 'last_honoured_on')
                : null,
            'note'             => $this->text($input, 'note', 255),
        ]);

        return [
            'id'      => $id,
            'message' => $cadence === 0
                ? sprintf('%s recorded as a one off.', $title)
                : sprintf('%s recorded, expected every %d days.', $title, $cadence),
        ];
    }

    /**
     * Recording that a commitment was honoured. This is the only thing that resets
     * its clock, which is what stops the screen from being decorative.
     *
     * @param array<string,mixed> $input
     */
    public function honourCommitment(array $input): array
    {
        $id = $this->id($input, 'commitment_id');

        if ($id === null) {
            throw new InvalidArgumentException('Which commitment did you honour?');
        }

        $row = Database::first('SELECT title FROM household_commitments WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            throw new InvalidArgumentException('That commitment no longer exists.');
        }

        Database::update(
            'household_commitments',
            ['last_honoured_on' => $this->date($input, 'honoured_on')],
            ['id' => $id]
        );

        return [
            'id'      => $id,
            'message' => sprintf('%s recorded. The clock on it starts again.', (string) $row['title']),
        ];
    }

    /**
     * Changing a threshold. Each key is validated on its own terms, and two of
     * them are validated against each other: a warning band that does not sit
     * above the critical one is a configuration that can never warn, and letting
     * it through would silently remove the early signal.
     *
     * @param array<string,mixed> $input
     */
    public function updateSetting(array $input): array
    {
        $key = (string) ($input['key'] ?? '');

        if (!array_key_exists($key, self::SETTABLE)) {
            throw new InvalidArgumentException('That setting is not editable from here.');
        }

        $rule  = self::SETTABLE[$key];
        $raw   = trim((string) ($input['value'] ?? ''));
        $label = $rule['label'];

        $stored = match ($rule['kind']) {
            'money' => (string) $this->settingMoney($raw, $label)->minor,
            'int'   => (string) $this->settingInt($raw, $label, $rule['min'], $rule['max']),
            'bool'  => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            default => $this->settingText($raw, $label, (int) ($rule['max'] ?? 120)),
        };

        // Cross-field consistency. Checked against what the other value will be
        // after this write, not before it.
        if ($key === 'runway_critical_days' || $key === 'runway_warning_days') {
            $critical = $key === 'runway_critical_days'
                ? (int) $stored
                : Settings::int('runway_critical_days', 7);
            $warning = $key === 'runway_warning_days'
                ? (int) $stored
                : Settings::int('runway_warning_days', 21);

            if ($warning <= $critical) {
                throw new InvalidArgumentException(sprintf(
                    'The tight threshold (%d days) has to sit above the critical one (%d), or the OS can never warn you before an emergency.',
                    $warning,
                    $critical
                ));
            }
        }

        Settings::set($key, $stored);

        // The rate is an assumption, so confirming it records when. Nothing else
        // in the OS would ever be able to tell you the rate had gone stale.
        if ($key === 'usd_ugx_rate') {
            Settings::set('usd_ugx_rate_set_on', Clock::todayString());
        }

        return [
            'id'      => 0,
            'message' => sprintf('%s updated. Every screen that reads it changes with it.', $label),
        ];
    }

    /** Editable keys, with the bounds that keep them meaningful. */
    private const SETTABLE = [
        'monthly_survival_minor' => ['kind' => 'money', 'label' => 'Monthly household requirement'],
        'runway_critical_days'   => ['kind' => 'int', 'label' => 'Critical runway threshold', 'min' => 1, 'max' => 180],
        'runway_warning_days'    => ['kind' => 'int', 'label' => 'Tight runway threshold', 'min' => 2, 'max' => 365],
        'non_revenue_hours_cap'  => ['kind' => 'int', 'label' => 'Non-revenue hours cap', 'min' => 1, 'max' => 200],
        'auto_emergency_mode'    => ['kind' => 'bool', 'label' => 'Automatic emergency mode'],
        'followup_stale_days'    => ['kind' => 'int', 'label' => 'Follow-up window', 'min' => 1, 'max' => 90],
        'daily_prospect_target'  => ['kind' => 'int', 'label' => 'Daily prospect target', 'min' => 1, 'max' => 200],
        'work_days_per_week'     => ['kind' => 'int', 'label' => 'Working days per week', 'min' => 1, 'max' => 7],
        'usd_ugx_rate'           => ['kind' => 'int', 'label' => 'USD to UGX rate', 'min' => 1, 'max' => 100000],
        'operator_name'          => ['kind' => 'text', 'label' => 'Operator name', 'max' => 80],
        'operator_location'      => ['kind' => 'text', 'label' => 'Operator location', 'max' => 120],
    ];

    private function settingMoney(string $raw, string $label): Money
    {
        $clean = preg_replace('/[^0-9.]/', '', $raw) ?? '';

        if ($clean === '' || !is_numeric($clean)) {
            throw new InvalidArgumentException($label . ' needs an amount.');
        }

        $money = Money::fromMajor($clean, Money::base());

        // Zero is allowed nowhere here: a survival requirement of zero makes the
        // OS report every month as covered.
        if ($money->isZero()) {
            throw new InvalidArgumentException($label . ' cannot be zero. Every runway figure is measured against it.');
        }

        return $money;
    }

    private function settingInt(string $raw, string $label, int $min, int $max): int
    {
        if (!is_numeric($raw)) {
            throw new InvalidArgumentException($label . ' needs a number.');
        }

        $value = (int) round((float) $raw);

        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('%s has to be between %d and %d.', $label, $min, $max));
        }

        return $value;
    }

    private function settingText(string $raw, string $label, int $max): string
    {
        if ($raw === '') {
            throw new InvalidArgumentException($label . ' cannot be empty.');
        }

        return mb_substr($raw, 0, $max);
    }

    public function addDeal(array $input): array
    {
        $title = $this->text($input, 'title', 160);

        if ($title === null) {
            throw new InvalidArgumentException('A deal needs a description.');
        }

        $stage = (string) ($input['stage'] ?? 'lead');

        if (!in_array($stage, ['prospect', 'lead', 'proposal', 'negotiation', 'won', 'lost'], true)) {
            throw new InvalidArgumentException('Unknown pipeline stage.');
        }

        $value = isset($input['value']) && $input['value'] !== ''
            ? Money::fromMajor((string) $input['value'])
            : Money::zero();

        // Probability defaults to the stage's own base rate, so an estimate
        // exists even when none is given: expected value depends on it.
        $probability = isset($input['probability']) && $input['probability'] !== ''
            ? max(0, min(100, (int) $input['probability']))
            : match ($stage) {
                'prospect'    => 5,
                'lead'        => 20,
                'proposal'    => 40,
                'negotiation' => 65,
                'won'         => 100,
                default       => 0,
            };

        return Database::transaction(function () use ($input, $title, $stage, $value, $probability): array {
            $clientId = $this->id($input, 'client_id');

            if ($clientId === null) {
                $clientName = $this->text($input, 'client', 120);

                if ($clientName !== null) {
                    $clientId = Database::insert('clients', ['name' => $clientName, 'org' => $clientName]);
                }
            }

            $dealId = Database::insert('deals', [
                'client_id'         => $clientId,
                'title'             => $title,
                'stage'             => $stage,
                'value_minor'       => $value->minor,
                'currency'          => $value->currency,
                'probability_pct'   => $probability,
                'expected_close_on' => isset($input['expected_close_on']) && $input['expected_close_on'] !== ''
                    ? $this->date($input, 'expected_close_on')
                    : null,
                'last_contact_on'   => $stage === 'prospect' ? null : $this->date($input),
                'next_action'       => $this->text($input, 'next_action', 200),
                'source'            => $this->text($input, 'source', 80) ?? 'Direct outreach',
            ]);

            Database::insert('deal_stage_events', [
                'deal_id'    => $dealId,
                'from_stage' => null,
                'to_stage'   => $stage,
                'changed_at' => Clock::now()->format('Y-m-d H:i:s'),
            ]);

            return ['id' => $dealId, 'message' => $title . ' added at ' . $stage . '.'];
        });
    }

    /**
     * An opportunity for the Research Director's register. It is ranked, not
     * accepted, a rough estimate is enough for the arithmetic to place it.
     *
     * @param array<string,mixed> $input
     */
    public function addOpportunity(array $input): array
    {
        $title = $this->text($input, 'title', 160);

        if ($title === null) {
            throw new InvalidArgumentException('An opportunity needs a name.');
        }

        $probability = ucfirst(mb_strtolower((string) ($input['probability'] ?? 'Medium')));

        if (!in_array($probability, ['Low', 'Medium', 'High'], true)) {
            throw new InvalidArgumentException('Probability must be Low, Medium or High.');
        }

        $value = isset($input['value']) && $input['value'] !== ''
            ? Money::fromMajor((string) $input['value'])
            : Money::zero();

        $id = Database::insert('opportunities', [
            'title'                 => $title,
            'summary'               => $this->text($input, 'summary', 2000),
            'potential_value_minor' => $value->minor,
            'currency'              => $value->currency,
            'effort_days'           => isset($input['effort_days']) && $input['effort_days'] !== ''
                ? max(0.25, (float) $input['effort_days'])
                : null,
            'effort_note'           => $this->text($input, 'effort_note', 60),
            'probability'           => $probability,
            'time_to_cash_days'     => isset($input['time_to_cash']) && $input['time_to_cash'] !== ''
                ? max(1, (int) $input['time_to_cash'])
                : null,
            'status'                => 'open',
            'source'                => 'user',
        ]);

        return ['id' => $id, 'message' => $title . ' added and ranked.'];
    }

    /**
     * A new debt. Recorded in the currency it was actually borrowed in, the
     * known loan is in USD, and converting it for tidiness would invent a
     * precision the exchange rate does not have.
     *
     * @param array<string,mixed> $input
     */
    public function addDebt(array $input): array
    {
        $name = $this->text($input, 'name', 120);

        if ($name === null) {
            throw new InvalidArgumentException('A debt needs a name.');
        }

        $currency = isset($input['currency']) && $input['currency'] !== ''
            ? strtoupper((string) $input['currency'])
            : 'USD';

        $principal = $this->money($input, 'Debt', $currency);
        $id        = Database::insert('debts', [
            'name'             => $name,
            'lender'           => $this->text($input, 'lender', 120),
            'principal_minor'  => $principal->minor,
            'currency'         => $principal->currency,
            'interest_note'    => $this->text($input, 'interest_note', 160),
            'opened_on'        => $this->date($input, 'opened_on'),
            'target_payoff_on' => isset($input['target_payoff_on']) && $input['target_payoff_on'] !== ''
                ? $this->date($input, 'target_payoff_on')
                : null,
            'status'           => 'active',
        ]);

        return ['id' => $id, 'message' => $name . ' (' . $principal->format() . ') added.'];
    }

    /**
     * A savings goal. Emergency-fund targets are derived from the survival
     * cost rather than typed in, so the target follows the household.
     *
     * @param array<string,mixed> $input
     */
    public function addSavingsGoal(array $input): array
    {
        $name = $this->text($input, 'name', 120);

        if ($name === null) {
            throw new InvalidArgumentException('A savings goal needs a name.');
        }

        $kind = (string) ($input['kind'] ?? 'goal');

        if (!in_array($kind, ['emergency', 'goal'], true)) {
            throw new InvalidArgumentException('Unknown savings goal kind.');
        }

        $target = $kind === 'emergency' ? Money::zero() : $this->money($input, 'Target');

        $id = Database::insert('savings_goals', [
            'name'         => $name,
            'kind'         => $kind,
            'target_minor' => $target->minor,
            'currency'     => $target->currency,
            'months_cover' => max(1, (int) ($input['months_cover'] ?? 3)),
            'note'         => $this->text($input, 'note', 255),
            'priority'     => max(1, (int) ($input['priority'] ?? 10)),
            'status'       => 'active',
        ]);

        return ['id' => $id, 'message' => $name . ' added as a savings goal.'];
    }

    /**
     * A deposit into, or withdrawal from, savings.
     *
     * This deliberately does not touch the ledger. Moving money into a pot is
     * not spending it, and available cash is anchored to the balance Gerald can
     * actually reach, which already excludes what he has set aside.
     *
     * @param array<string,mixed> $input
     */
    public function addSavingsEntry(array $input): array
    {
        $direction = (string) ($input['direction'] ?? 'in');

        if (!in_array($direction, ['in', 'out'], true)) {
            throw new InvalidArgumentException('A savings entry is either a deposit or a withdrawal.');
        }

        $amount = $this->money($input, $direction === 'in' ? 'Deposit' : 'Withdrawal');
        $goalId = $this->id($input, 'goal_id');

        if ($goalId !== null) {
            $exists = Database::first("SELECT id FROM savings_goals WHERE id = :id AND status = 'active'", ['id' => $goalId]);
            if ($exists === null) {
                throw new InvalidArgumentException('That savings goal does not exist.');
            }
        }

        $id = Database::insert('savings_entries', [
            'goal_id'      => $goalId,
            'direction'    => $direction,
            'amount_minor' => $amount->minor,
            'currency'     => $amount->currency,
            'occurred_on'  => $this->date($input),
            'note'         => $this->text($input, 'note', 255),
        ]);

        $this->markReachedGoals();

        return [
            'id'      => $id,
            'message' => $direction === 'in'
                ? $amount->format() . ' added to savings.'
                : $amount->format() . ' withdrawn from savings.',
        ];
    }

    /** A goal that has hit its target stops being a goal. */
    private function markReachedGoals(): void
    {
        Database::run(
            "UPDATE savings_goals g
             SET g.status = 'reached'
             WHERE g.status = 'active'
               AND g.target_minor > 0
               AND (
                 SELECT COALESCE(SUM(CASE WHEN e.direction = 'in' THEN e.amount_minor ELSE -e.amount_minor END), 0)
                 FROM savings_entries e WHERE e.goal_id = g.id
               ) >= g.target_minor"
        );
    }

    /**
     * A bill that will fall due. Distinct from an expense: an expense is money
     * already gone, a bill is money committed. The income target and two alert
     * rules read these, so an unrecorded bill makes the whole runway optimistic.
     *
     * @param array<string,mixed> $input
     */
    public function addBill(array $input): array
    {
        $name = $this->text($input, 'name', 120);

        if ($name === null) {
            throw new InvalidArgumentException('A bill needs a name.');
        }

        $amount     = $this->money($input, 'Bill');
        $recurrence = (string) ($input['recurrence'] ?? 'monthly');

        if (!in_array($recurrence, ['none', 'weekly', 'monthly', 'quarterly', 'yearly'], true)) {
            throw new InvalidArgumentException('Unknown recurrence.');
        }

        $id = Database::insert('bills', [
            'name'         => $name,
            'amount_minor' => $amount->minor,
            'currency'     => $amount->currency,
            'due_on'       => $this->date($input, 'due_on'),
            'recurrence'   => $recurrence,
            'is_essential' => !isset($input['is_essential']) || (bool) $input['is_essential'] ? 1 : 0,
            'status'       => 'due',
        ]);

        return ['id' => $id, 'message' => $name . ' (' . $amount->format() . ') added to upcoming bills.'];
    }

    /**
     * Marks a bill paid and records the money leaving. Rolls a recurring bill
     * forward so the next one is already on the books.
     *
     * @param array<string,mixed> $input
     */
    public function payBill(array $input): array
    {
        $billId = $this->id($input, 'bill_id');

        if ($billId === null) {
            throw new InvalidArgumentException('Which bill?');
        }

        $bill = Database::first("SELECT * FROM bills WHERE id = :id AND status = 'due'", ['id' => $billId]);

        if ($bill === null) {
            throw new InvalidArgumentException('That bill is not outstanding.');
        }

        $amount = Money::of((int) $bill['amount_minor'], (string) $bill['currency']);
        $paidOn = $this->date($input, 'paid_on');

        return Database::transaction(function () use ($bill, $billId, $amount, $paidOn): array {
            Database::update('bills', ['status' => 'paid', 'paid_on' => $paidOn], ['id' => $billId]);

            Database::insert('ledger_entries', [
                'direction'    => 'out',
                'amount_minor' => $amount->minor,
                'currency'     => $amount->currency,
                'occurred_on'  => $paidOn,
                'category'     => $this->billCategory((string) $bill['name']),
                'description'  => (string) $bill['name'],
                'is_essential' => (int) $bill['is_essential'],
                'source'       => 'system',
            ]);

            $next = $this->nextDueDate((string) $bill['due_on'], (string) $bill['recurrence']);

            if ($next !== null) {
                Database::insert('bills', [
                    'name'         => (string) $bill['name'],
                    'amount_minor' => $amount->minor,
                    'currency'     => $amount->currency,
                    'due_on'       => $next,
                    'recurrence'   => (string) $bill['recurrence'],
                    'is_essential' => (int) $bill['is_essential'],
                    'status'       => 'due',
                ]);
            }

            return [
                'id'      => $billId,
                'message' => $bill['name'] . ' paid (' . $amount->format() . ')'
                    . ($next === null ? '.' : ', next one due ' . $next . '.'),
            ];
        });
    }

    private function nextDueDate(string $dueOn, string $recurrence): ?string
    {
        $interval = match ($recurrence) {
            'weekly'    => '+1 week',
            'monthly'   => '+1 month',
            'quarterly' => '+3 months',
            'yearly'    => '+1 year',
            default     => null,
        };

        return $interval === null
            ? null
            : (new \DateTimeImmutable($dueOn, Clock::zone()))->modify($interval)->format('Y-m-d');
    }

    /** Best-effort mapping so a paid bill lands in a sensible expense category. */
    private function billCategory(string $name): string
    {
        $lower = mb_strtolower($name);

        return match (true) {
            str_contains($lower, 'rent')      => 'rent',
            str_contains($lower, 'electric'),
            str_contains($lower, 'water'),
            str_contains($lower, 'power')     => 'utilities',
            str_contains($lower, 'internet'),
            str_contains($lower, 'data'),
            str_contains($lower, 'airtime')   => 'data',
            str_contains($lower, 'school'),
            str_contains($lower, 'fees')      => 'school',
            default                           => 'general',
        };
    }

    /** Records an observed cash balance the anchor the runway is measured from. */
    public function recordCashBalance(array $input): array
    {
        $amount = $this->money($input, 'Balance');

        $id = Database::insert('cash_snapshots', [
            'currency'      => $amount->currency,
            'balance_minor' => $amount->minor,
            'observed_on'   => $this->date($input),
            // Everything already in the ledger is baked into this balance.
            'ledger_mark'   => (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM ledger_entries', [], 0),
            'note'          => $this->text($input, 'note', 200),
        ]);

        return ['id' => $id, 'message' => 'Cash balance set to ' . $amount->format() . '.'];
    }

    /** Logs outreach for the day what the sales score is measured against. */
    public function logOutreach(int $prospects, int $followups = 0): array
    {
        Database::run(
            'INSERT INTO day_logs (for_date, prospects_contacted, followups_made)
             VALUES (:d, :p, :f)
             ON DUPLICATE KEY UPDATE
               prospects_contacted = prospects_contacted + VALUES(prospects_contacted),
               followups_made = followups_made + VALUES(followups_made)',
            ['d' => Clock::todayString(), 'p' => max($prospects, 0), 'f' => max($followups, 0)]
        );

        return ['message' => 'Outreach logged.'];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $input */
    private function money(array $input, string $label, ?string $currency = null): Money
    {
        $raw = $input['amount'] ?? null;

        if ($raw === null || $raw === '' || !is_numeric(preg_replace('/[^0-9.\-]/', '', (string) $raw))) {
            throw new InvalidArgumentException($label . ' needs an amount.');
        }

        $currency = $currency ?? (isset($input['currency']) && $input['currency'] !== '' ? strtoupper((string) $input['currency']) : Money::base());
        $money    = Money::fromMajor((string) $raw, $currency);

        if ($money->minor < 0) {
            throw new InvalidArgumentException($label . ' cannot be negative record the opposite direction instead.');
        }
        if ($money->isZero()) {
            throw new InvalidArgumentException($label . ' must be more than zero.');
        }

        return $money;
    }

    /** @param array<string,mixed> $input */
    private function date(array $input, string $key = 'occurred_on'): string
    {
        $value = $input[$key] ?? $input['date'] ?? null;

        if ($value === null || $value === '') {
            return Clock::todayString();
        }

        $stamp = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value, Clock::zone());

        if ($stamp === false) {
            throw new InvalidArgumentException('Dates must be in YYYY-MM-DD form.');
        }

        return $stamp->format('Y-m-d');
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key, int $max): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $max);
    }

    /** @param array<string,mixed> $input */
    private function id(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function inBaseCurrency(Money $amount): ?Money
    {
        if ($amount->currency === Money::base()) {
            return $amount;
        }

        $rate = \Germa\Support\Settings::float('usd_ugx_rate', 0.0);

        // No rate means no honest conversion record the debt payment only.
        return $rate > 0.0 ? $amount->convertTo(Money::base(), $rate) : null;
    }

    private function settleIfCleared(int $debtId): void
    {
        $debt = Database::first('SELECT principal_minor, currency FROM debts WHERE id = :id', ['id' => $debtId]);

        if ($debt === null) {
            return;
        }

        $paid = (int) Database::value(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM debt_payments
             WHERE debt_id = :id AND status = 'paid' AND currency = :c",
            ['id' => $debtId, 'c' => (string) $debt['currency']],
            0
        );

        if ($paid >= (int) $debt['principal_minor']) {
            Database::update('debts', ['status' => 'settled'], ['id' => $debtId]);
        }
    }

    private function bumpProspectsContacted(): void
    {
        Database::run(
            'INSERT INTO day_logs (for_date, prospects_contacted) VALUES (:d, 1)
             ON DUPLICATE KEY UPDATE prospects_contacted = prospects_contacted + 1',
            ['d' => Clock::todayString()]
        );
    }
}
