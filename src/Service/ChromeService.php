<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Html;
use Germa\Support\Settings;

/**
 * The application shell every screen shares: sidebar navigation with live
 * badges, the operator identity, and the OS status the top bar reports.
 *
 * This lives outside any one screen's service on purpose. The dashboard used to
 * own the navigation, which would have meant each new page inventing its own
 * the fastest route to a product that looks like twenty different apps.
 */
final class ChromeService
{
    /**
     * The sidebar, in one place. Adding a screen means giving its route a real
     * destination here, not rebuilding a menu.
     *
     * Every entry is [label, route, badge-source]. Badge sources are resolved
     * against live counts below, so the nav itself reports where attention is
     * owed on every screen rather than only on the dashboard.
     */
    private const GROUPS = [
        ['Command', [
            ['Dashboard', '', null],
        ]],
        ['Money', [
            ['Financial Overview', 'finance', null],
            ['Income', 'income', null],
            ['Expenses', 'expenses', null],
            ['Debt', 'debt', 'debt_due'],
            ['Savings', 'savings', null],
        ]],
        ['Execution', [
            ['Today', 'today', 'open_tasks'],
            ['Tasks', 'tasks', null],
            ['Calendar', 'calendar', null],
        ]],
        ['Business', [
            ['Sales Pipeline', 'pipeline', null],
            ['Clients', 'clients', null],
            ['Opportunities', 'opportunities', 'opportunities'],
            ['Revenue', 'revenue', null],
        ]],
        ['Projects', [
            ['Active Projects', 'projects', null],
            ['Project Portfolio', 'portfolio', null],
        ]],
        ['Intelligence', [
            ['AI Boardroom', 'boardroom', null],
            ['Decisions', 'decisions', null],
            ['Insights', 'insights', 'alerts'],
            ['Research', 'research', null],
        ]],
        ['Goals', [
            ['Goals', 'goals', null],
            ['Milestones', 'milestones', null],
        ]],
        ['Household', [
            ['Household Overview', 'household', null],
            ['Shared Goals', 'shared-goals', null],
        ]],
        ['System', [
            ['Reports', 'reports', null],
            ['Settings', 'settings', null],
            ['AI Configuration', 'ai-config', null],
        ]],
    ];

    public function __construct(
        private readonly FinanceService $finance,
        private readonly DebtService $debt,
        private readonly TaskService $tasks,
        private readonly OpportunityService $opportunities,
        private readonly AlertService $alerts,
        private readonly ModeService $mode,
        private readonly BoardService $board,
    ) {
    }

    /** Builds the service graph itself, for screens that do not already have one. */
    public static function make(): self
    {
        $finance  = new FinanceService();
        $debt     = new DebtService();
        $pipeline = new PipelineService();
        $projects = new ProjectService();

        return new self(
            $finance,
            $debt,
            new TaskService(),
            new OpportunityService(),
            new AlertService($finance, $debt, $pipeline, $projects),
            new ModeService($finance),
            new BoardService(),
        );
    }

    /**
     * @param string $activeRoute The route slug of the current screen ('' is the dashboard).
     * @return array<string,mixed>
     */
    /**
     * @param list<array<string,mixed>>|null $openAlerts Pass the list when the
     *        screen has already evaluated it, so the bell and the screen agree
     *        without a second query.
     */
    public function build(string $activeRoute, ?array $openAlerts = null): array
    {
        $open       = $openAlerts ?? $this->alerts->open();
        $alertCount = count($open);
        $mode       = $this->mode->current();

        return [
            'nav'      => $this->navigation($activeRoute, $alertCount),
            // The bell shows the alerts themselves, not just a number: a count
            // with no way to see what it counts is an anxiety generator.
            'alerts'   => $this->notifications($open),
            // Quick-add forms let money be attributed to a project, so the list
            // travels with the shell rather than each screen fetching it.
            'projects' => $this->projectOptions(),
            'savings_goals' => $this->savingsGoalOptions(),
            // Reviewing a decision needs to pick one, and the reviewer can be
            // reached from any screen, so the list travels with the shell.
            'decisions'     => $this->decisionOptions(),
            'goals'         => $this->goalOptions(),
            'milestones'    => $this->milestoneOptions(),
            'commitments'   => $this->commitmentOptions(),
            'meta'     => [
                'operator'    => Settings::get('operator_name', 'Gerald'),
                'location'    => Settings::get('operator_location', ''),
                'greeting'    => Clock::greeting(),
                'stamp'       => Clock::headerStamp(),
                'mode'        => $mode,
                'mode_label'  => $this->mode->label($mode),
                'mode_reason' => $this->mode->currentPeriod()['reason'],
                'ai_live'     => $this->board->isLive(),
                'demo_data'   => Settings::bool('demo_data'),
                'alert_count' => $alertCount,
            ],
        ];
    }

    /** @return list<array{id:int,name:string}> */
    private function savingsGoalOptions(): array
    {
        return array_map(
            static fn (array $g): array => ['id' => (int) $g['id'], 'name' => (string) $g['name']],
            \Germa\Support\Database::all(
                "SELECT id, name FROM savings_goals WHERE status = 'active' ORDER BY priority, id"
            )
        );
    }

    /**
     * Decisions that can still be reviewed. A closed one has nothing left to
     * record, so offering it would only invite an accidental overwrite.
     *
     * @return list<array{id:int,name:string}>
     */
    private function decisionOptions(): array
    {
        return array_map(
            static fn (array $d): array => ['id' => (int) $d['id'], 'name' => (string) $d['title']],
            \Germa\Support\Database::all(
                "SELECT id, title FROM decisions WHERE status IN ('open', 'decided')
                 ORDER BY COALESCE(review_on, created_at)"
            )
        );
    }

    /** @return list<array{id:int,name:string}> */
    private function goalOptions(): array
    {
        return array_map(
            static fn (array $g): array => ['id' => (int) $g['id'], 'name' => (string) $g['title']],
            \Germa\Support\Database::all(
                "SELECT id, title FROM goals WHERE status = 'active'
                 ORDER BY FIELD(horizon, '30d', '90d', '1y', 'long'), sort_order"
            )
        );
    }

    /**
     * Only milestones still open. Offering a finished one would invite marking it
     * done twice, and the entry point refuses that anyway.
     *
     * @return list<array{id:int,name:string}>
     */
    private function milestoneOptions(): array
    {
        return array_map(
            static fn (array $m): array => [
                'id'   => (int) $m['id'],
                'name' => (string) $m['title'] . ' (' . (string) $m['goal'] . ')',
            ],
            \Germa\Support\Database::all(
                "SELECT m.id, m.title, g.title AS goal
                 FROM goal_milestones m
                 JOIN goals g ON g.id = m.goal_id
                 WHERE m.done_on IS NULL AND g.status = 'active'
                 ORDER BY m.target_on IS NULL, m.target_on"
            )
        );
    }

    /** @return list<array{id:int,name:string}> */
    private function commitmentOptions(): array
    {
        return array_map(
            static fn (array $c): array => ['id' => (int) $c['id'], 'name' => (string) $c['title']],
            \Germa\Support\Database::all(
                'SELECT id, title FROM household_commitments WHERE archived = 0 ORDER BY title'
            )
        );
    }

    /** @return list<array{id:int,name:string}> */
    private function projectOptions(): array
    {
        return array_map(
            static fn (array $p): array => ['id' => $p['id'], 'name' => $p['name']],
            (new ProjectService())->portfolio()
        );
    }

    /**
     * @return list<array{label:string,items:list<array{name:string,route:string,badge:string,active:bool}>}>
     */
    /**
     * Open alerts, shaped for the notification bell.
     *
     * @param list<array<string,mixed>> $open
     * @return list<array<string,mixed>>
     */
    private function notifications(array $open): array
    {
        $rows = [];

        foreach (array_slice($open, 0, 6) as $alert) {
            $rows[] = [
                'id'       => (int) $alert['id'],
                'title'    => (string) $alert['title'],
                'body'     => (string) $alert['body'],
                'severity' => (string) $alert['severity'],
                'tone'     => match ((string) $alert['severity']) {
                    'CRITICAL' => 'critical',
                    'HIGH'     => 'high',
                    default    => 'quiet',
                },
                'label'    => $alert['action_label'] ?? null,
                'url'      => $alert['action_url'] ?? null,
            ];
        }

        return $rows;
    }

    private function navigation(string $activeRoute, int $alertCount): array
    {
        $openTasks = count(array_filter($this->tasks->topPriorities(), static fn (array $t): bool => !$t['done']))
            + count(array_filter($this->tasks->otherTasks(), static fn (array $t): bool => !$t['done']));

        $badges = [
            'debt_due'      => count($this->debt->upcomingPayments(14)),
            'open_tasks'    => $openTasks,
            'opportunities' => $this->opportunities->count(),
            'alerts'        => $alertCount,
        ];

        $nav = [];

        foreach (self::GROUPS as [$label, $items]) {
            $nav[] = [
                'label' => $label,
                'items' => array_map(
                    static function (array $item) use ($badges, $activeRoute): array {
                        [$name, $route, $badgeKey] = $item;
                        $count = $badgeKey === null ? 0 : ($badges[$badgeKey] ?? 0);

                        return [
                            'name'   => $name,
                            'route'  => Html::url($route),
                            'badge'  => $count > 0 ? (string) $count : '',
                            'active' => $route === $activeRoute,
                        ];
                    },
                    $items
                ),
            ];
        }

        return $nav;
    }
}
