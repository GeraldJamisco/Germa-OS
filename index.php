<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Germa\Http\Auth;
use Germa\Http\Csrf;
use Germa\Http\Json;
use Germa\Http\View;
use Germa\Service\ActiveProjectService;
use Germa\Service\AiConfigService;
use Germa\Service\BoardroomService;
use Germa\Service\AlertService;
use Germa\Service\ChromeService;
use Germa\Service\DashboardService;
use Germa\Service\DecisionService;
use Germa\Service\DebtOverviewService;
use Germa\Service\EntryService;
use Germa\Service\ExpenseService;
use Germa\Service\FinanceOverviewService;
use Germa\Service\GoalBoardService;
use Germa\Service\HouseholdService;
use Germa\Service\IncomeService;
use Germa\Service\InsightService;
use Germa\Service\SavingsService;
use Germa\Service\CalendarService;
use Germa\Service\TaskBoardService;
use Germa\Service\TodayService;
use Germa\Service\ClientService;
use Germa\Service\MilestoneService;
use Germa\Service\OnboardingService;
use Germa\Service\OpportunityBoardService;
use Germa\Service\PortfolioService;
use Germa\Service\ResearchService;
use Germa\Service\ReportService;
use Germa\Service\RevenueService;
use Germa\Service\SalesPipelineService;
use Germa\Service\SettingsService;
use Germa\Service\SharedGoalService;
use Germa\Service\TaskService;
use Germa\Support\Config;
use Germa\Support\Html;

Csrf::start();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path   = germa_path();

try {
    // ── Authentication gate ───────────────────────────────────────────────────
    // The login page and its POST are the only things reachable while signed out.
    // Everything else, page or API, goes through the gate below.
    if ($path === 'login') {
        germa_login($method);
    }
    if ($path === 'logout') {
        germa_logout($method);
    }

    if (!Auth::check()) {
        if (str_starts_with($path, 'api/')) {
            Json::error('Sign in to continue.', 401);
        }
        header('Location: ' . Html::url('login'));
        exit;
    }

    // ── First-run setup gate ──────────────────────────────────────────────────
    // Signed in but the OS has nothing to work from: walk through setup before
    // anything else renders. The setup flow is the only thing reachable until it
    // is finished.
    if ($path === 'welcome') {
        germa_onboarding($method);
    }
    if (!OnboardingService::isComplete()) {
        if (str_starts_with($path, 'api/')) {
            Json::error('Finish setup first.', 409);
        }
        header('Location: ' . Html::url('welcome'));
        exit;
    }

    // ── API ─────────────────────────────────────────────────────────────────
    if (str_starts_with($path, 'api/')) {
        germa_api($method, substr($path, 4));
    }

    // ── Pages ───────────────────────────────────────────────────────────────
    // Every screen renders as a fragment inside the shared shell, so the
    // sidebar, top bar and command dock are identical everywhere by
    // construction rather than by being copied per page.
    $token = Csrf::token();

    if ($path === '' || $path === 'dashboard') {
        $data = (new DashboardService())->build();

        View::render('screens/dashboard', ['data' => $data, 'token' => $token], [
            'title'       => 'Dashboard',
            'route'       => '',
            'chrome'      => ChromeService::make()->build('', $data['alerts']),
            'suggestions' => $data['suggestions'],
            'body_attrs'  => $data['meta']['ai_pending'] ? 'data-ai-pending="1"' : '',
        ]);
        exit;
    }

    if ($path === 'finance') {
        $data = (new FinanceOverviewService())->build($_GET['period'] ?? 'month');

        View::render('screens/finance-overview', ['data' => $data, 'token' => $token], [
            'title'         => 'Financial Overview',
            'route'         => 'finance',
            'topbar_action' => ['label' => '+ Add Transaction', 'quick_add' => 'expense'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'income') {
        $data = (new IncomeService())->build($_GET['period'] ?? 'month');

        View::render('screens/income', ['data' => $data, 'token' => $token], [
            'title'         => 'Income',
            'route'         => 'income',
            'topbar_action' => ['label' => '+ Log Income', 'quick_add' => 'income'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'expenses') {
        $data = (new ExpenseService())->build($_GET['period'] ?? 'month');

        View::render('screens/expenses', ['data' => $data, 'token' => $token], [
            'title'         => 'Expenses',
            'route'         => 'expenses',
            'topbar_action' => ['label' => '+ Log Expense', 'quick_add' => 'expense'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'debt') {
        $data = (new DebtOverviewService())->build($_GET['period'] ?? 'month');

        View::render('screens/debt', ['data' => $data, 'token' => $token], [
            'title'         => 'Debt',
            'route'         => 'debt',
            'topbar_action' => ['label' => '+ Log Payment', 'quick_add' => 'debt-payment'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'savings') {
        $data = (new SavingsService())->build($_GET['period'] ?? 'month');

        View::render('screens/savings', ['data' => $data, 'token' => $token], [
            'title'         => 'Savings',
            'route'         => 'savings',
            'topbar_action' => ['label' => '+ Add to Savings', 'quick_add' => 'savings'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'today') {
        $data = (new TodayService())->build();

        View::render('screens/today', ['data' => $data, 'token' => $token], [
            'title'         => 'Today',
            'route'         => 'today',
            'topbar_action' => ['label' => '+ Log Result', 'quick_add' => 'income'],
            'suggestions'   => $data['suggestions'],
            'body_attrs'    => $data['meta']['ai_pending'] ? 'data-ai-pending="1"' : '',
        ]);
        exit;
    }

    if ($path === 'tasks') {
        $data = (new TaskBoardService())->build($_GET['filter'] ?? 'all');

        View::render('screens/tasks', ['data' => $data, 'token' => $token], [
            'title'         => 'Tasks',
            'route'         => 'tasks',
            'topbar_action' => ['label' => '+ Add Task', 'quick_add' => 'task'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'calendar') {
        $data = (new CalendarService())->build($_GET['view'] ?? 'week');

        View::render('screens/calendar', ['data' => $data, 'token' => $token], [
            'title'         => 'Calendar',
            'route'         => 'calendar',
            'topbar_action' => ['label' => '+ Add Event', 'quick_add' => 'task'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'pipeline') {
        $data = (new SalesPipelineService())->build($_GET['period'] ?? 'week');

        View::render('screens/pipeline', ['data' => $data, 'token' => $token], [
            'title'         => 'Sales Pipeline',
            'route'         => 'pipeline',
            'topbar_action' => ['label' => '+ Add Prospect', 'quick_add' => 'prospect'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'clients') {
        $data = (new ClientService())->build($_GET['filter'] ?? 'all');

        View::render('screens/clients', ['data' => $data, 'token' => $token], [
            'title'         => 'Clients',
            'route'         => 'clients',
            'topbar_action' => ['label' => '+ Add Client', 'quick_add' => 'prospect'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'opportunities') {
        $data = (new OpportunityBoardService())->build();

        View::render('screens/opportunities', ['data' => $data, 'token' => $token], [
            'title'         => 'Opportunities',
            'route'         => 'opportunities',
            'topbar_action' => ['label' => '+ Add Opportunity', 'quick_add' => 'opportunity'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'revenue') {
        $data = (new RevenueService())->build($_GET['period'] ?? 'month');

        View::render('screens/revenue', ['data' => $data, 'token' => $token], [
            'title'         => 'Revenue',
            'route'         => 'revenue',
            'topbar_action' => ['label' => '+ Log Revenue', 'quick_add' => 'income'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'projects') {
        $data = (new ActiveProjectService())->build($_GET['period'] ?? 'week');

        View::render('screens/projects', ['data' => $data, 'token' => $token], [
            'title'         => 'Active Projects',
            'route'         => 'projects',
            'topbar_action' => ['label' => '+ Add Project', 'quick_add' => 'project'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'portfolio') {
        $data = (new PortfolioService())->build($_GET['period'] ?? 'week');

        View::render('screens/portfolio', ['data' => $data, 'token' => $token], [
            'title'         => 'Project Portfolio',
            'route'         => 'portfolio',
            'topbar_action' => ['label' => '+ Add Project', 'quick_add' => 'project'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'boardroom') {
        $data = (new BoardroomService())->build();

        View::render('screens/boardroom', ['data' => $data, 'token' => $token], [
            'title'         => 'AI Boardroom',
            'route'         => 'boardroom',
            'topbar_action' => ['label' => '+ Ask the Board', 'focus' => '[data-board-ask] textarea'],
            'suggestions'   => $data['suggestions'],
            // The boardroom reloads after a reconvene rather than repainting:
            // its advisor grouping and counts have to stay consistent.
            'body_attrs'    => 'data-boardroom="1"'
                . ($data['ai_pending'] ? ' data-ai-pending="1"' : ''),
        ]);
        exit;
    }

    if ($path === 'decisions') {
        $data = (new DecisionService())->build($_GET['filter'] ?? 'live');

        View::render('screens/decisions', ['data' => $data, 'token' => $token], [
            'title'         => 'Decisions',
            'route'         => 'decisions',
            'topbar_action' => ['label' => '+ Log Decision', 'quick_add' => 'decision'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'insights') {
        $data = InsightService::make()->build();

        View::render('screens/insights', ['data' => $data, 'token' => $token], [
            'title'         => 'Insights',
            'route'         => 'insights',
            'topbar_action' => ['label' => '+ Log Decision', 'quick_add' => 'decision'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'research') {
        $data = (new ResearchService())->build();

        View::render('screens/research', ['data' => $data, 'token' => $token], [
            'title'         => 'Research',
            'route'         => 'research',
            'topbar_action' => ['label' => '+ Record Finding', 'quick_add' => 'research'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'goals') {
        $data = (new GoalBoardService())->build($_GET['scope'] ?? 'all');

        View::render('screens/goals', ['data' => $data, 'token' => $token], [
            'title'         => 'Goals',
            'route'         => 'goals',
            'topbar_action' => ['label' => '+ Add Goal', 'quick_add' => 'goal'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'milestones') {
        $data = (new MilestoneService())->build($_GET['window'] ?? 'open');

        View::render('screens/milestones', ['data' => $data, 'token' => $token], [
            'title'         => 'Milestones',
            'route'         => 'milestones',
            'topbar_action' => ['label' => '+ Add Milestone', 'quick_add' => 'goal-milestone'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'household') {
        $data = (new HouseholdService())->build();

        View::render('screens/household', ['data' => $data, 'token' => $token], [
            'title'         => 'Household Overview',
            'route'         => 'household',
            'topbar_action' => ['label' => '+ Add Commitment', 'quick_add' => 'commitment'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'shared-goals') {
        $data = (new SharedGoalService())->build();

        View::render('screens/shared-goals', ['data' => $data, 'token' => $token], [
            'title'         => 'Shared Goals',
            'route'         => 'shared-goals',
            'topbar_action' => ['label' => '+ Add Shared Goal', 'quick_add' => 'goal'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'reports') {
        $data = (new ReportService())->build($_GET['period'] ?? 'week');

        View::render('screens/reports', ['data' => $data, 'token' => $token], [
            'title'         => 'Reports',
            'route'         => 'reports',
            // The end-of-day review is a form on Today, not a modal, so this links
            // there rather than opening a quick-add type that does not exist.
            'topbar_action' => ['label' => 'Close Out Today', 'href' => 'today'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'settings') {
        $data = (new SettingsService())->build();

        View::render('screens/settings', ['data' => $data, 'token' => $token], [
            'title'         => 'Settings',
            'route'         => 'settings',
            'topbar_action' => ['label' => 'Change a Setting', 'quick_add' => 'setting'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    if ($path === 'ai-config') {
        $data = (new AiConfigService())->build();

        View::render('screens/ai-config', ['data' => $data, 'token' => $token], [
            'title'         => 'AI Configuration',
            'route'         => 'ai-config',
            'topbar_action' => ['label' => 'Test the Connection', 'focus' => '[data-ai-test]'],
            'suggestions'   => $data['suggestions'],
        ]);
        exit;
    }

    // Every nav destination is built, so an unmatched path is a genuine 404.
    http_response_code(404);
    require __DIR__ . '/resources/views/not-found.php';
    exit;
} catch (Throwable $e) {
    germa_fail($e, $path);
}

// ── Routing helpers ─────────────────────────────────────────────────────────

/** The request path with the app's base directory and query string stripped. */
function germa_path(): string
{
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $uri  = (string) parse_url($uri, PHP_URL_PATH);
    $uri  = rawurldecode($uri);
    $base = rtrim((string) Config::get('app.base_path', ''), '/');

    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }

    return trim($uri, '/');
}

/**
 * Renders the login page, or processes a submission. Never returns.
 */
function germa_login(string $method): never
{
    // Already signed in: no reason to show the form again.
    if (Auth::check() && Auth::isConfigured()) {
        header('Location: ' . Html::url(''));
        exit;
    }

    $error = null;

    if ($method === 'POST') {
        $body = Json::body();

        if (!Csrf::check($body)) {
            $error = 'The form expired. Try again.';
        } else {
            $throttle = Auth::throttle();

            if ($throttle['locked']) {
                $error = sprintf(
                    'Too many attempts. Wait about %d minute%s and try again.',
                    max(1, (int) ceil($throttle['retry_after'] / 60)),
                    $throttle['retry_after'] > 60 ? 's' : ''
                );
            } elseif (Auth::attempt((string) ($body['password'] ?? ''))) {
                header('Location: ' . Html::url(''), true, 303);
                exit;
            } else {
                // One message for both wrong-password and unknown, so the form
                // never confirms which half was right.
                $error = 'That did not match.';
            }
        }
    }

    http_response_code($error === null ? 200 : 401);

    $token      = Csrf::token();
    $configured = Auth::isConfigured();
    $loopback   = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);

    require __DIR__ . '/resources/views/login.php';
    exit;
}

/**
 * The first-run setup flow: renders a step, or saves one and advances. Reached
 * only while signed in and not yet set up. Never returns.
 */
function germa_onboarding(string $method): never
{
    // Already done: show the confirmation once (reached via ?done after the
    // final step), otherwise there is nothing left to collect.
    if (OnboardingService::isComplete()) {
        if (isset($_GET['done'])) {
            germa_onboarding_done();
        }
        header('Location: ' . Html::url(''));
        exit;
    }

    $service = new OnboardingService();
    $error   = null;
    $done    = false;
    $step    = $service->step($_GET['step'] ?? 1);

    if ($method === 'POST') {
        $body = Json::body();

        if (!Csrf::check($body)) {
            $error = 'The form expired. Try again.';
        } else {
            $step = $service->step($body['step'] ?? 1);

            try {
                $next = $service->save($step, $body);

                if ($next === null) {
                    // Setup finished: show the confirmation screen, then in.
                    header('Location: ' . Html::url('welcome') . '?done=1', true, 303);
                    exit;
                }

                header('Location: ' . Html::url('welcome') . '?step=' . $next, true, 303);
                exit;
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
    }

    http_response_code($error === null ? 200 : 422);

    $steps    = OnboardingService::STEPS;
    $last     = OnboardingService::LAST_STEP;
    $values   = $service->current();
    $projects = $service->projects();
    $token    = Csrf::token();
    $operator = $values['operator_name'];
    $base     = \Germa\Support\Money::base();

    require __DIR__ . '/resources/views/welcome.php';
    exit;
}

/** The "you're set up" confirmation, shown once after the final step. */
function germa_onboarding_done(): never
{
    $done     = true;
    $step     = OnboardingService::LAST_STEP;
    $steps    = OnboardingService::STEPS;
    $last     = OnboardingService::LAST_STEP;
    $values   = (new OnboardingService())->current();
    $projects = [];
    $error    = null;
    $token    = Csrf::token();
    $operator = $values['operator_name'];
    $base     = \Germa\Support\Money::base();

    require __DIR__ . '/resources/views/welcome.php';
    exit;
}

/** Signs out. A POST with a valid token, so it cannot be triggered cross-site. */
function germa_logout(string $method): never
{
    if ($method === 'POST' && Csrf::check(Json::body())) {
        Auth::logout();
    }

    header('Location: ' . Html::url('login'));
    exit;
}

function germa_api(string $method, string $route): never
{
    if ($method !== 'POST' && $route !== 'dashboard') {
        Json::error('This endpoint expects POST.', 405);
    }

    $body = $method === 'POST' ? Json::body() : [];

    if ($method === 'POST' && !Csrf::check($body)) {
        Json::error('Your session expired. Reload the page and try again.', 403);
    }

    $dashboard = new DashboardService();

    // GET /api/dashboard the whole payload, for a client-side refresh.
    if ($route === 'dashboard') {
        Json::send(['ok' => true, 'data' => germa_jsonable($dashboard->build())]);
    }

    // POST /api/tasks/{id}/toggle
    if (preg_match('#^tasks/(\d+)/toggle$#', $route, $m) === 1) {
        $result = (new TaskService())->toggle((int) $m[1]);
        Json::ok(['task' => $result, 'score' => $dashboard->build()['score']]);
    }

    // POST /api/alerts/{id}/dismiss
    if (preg_match('#^alerts/(\d+)/dismiss$#', $route, $m) === 1) {
        $alerts = new AlertService(
            $dashboard->finance,
            $dashboard->debt,
            $dashboard->pipeline,
            $dashboard->projects
        );
        $alerts->dismiss((int) $m[1]);
        Json::ok(['alerts' => $alerts->open()]);
    }

    // POST /api/entries/{type}
    if (preg_match('#^entries/([a-z\-]+)$#', $route, $m) === 1) {
        $entries = new EntryService();

        $result = match ($m[1]) {
            'income'       => $entries->addIncome($body),
            'expense'      => $entries->addExpense($body),
            'debt-payment' => $entries->addDebtPayment($body),
            'task'         => $entries->addTask($body),
            'prospect'     => $entries->addProspect($body),
            'project'      => $entries->addProject($body),
            'project-hours' => $entries->addProjectHours($body),
            'project-decision' => $entries->recordProjectDecision($body),
            'decision'        => $entries->addDecision($body),
            'decision-review' => $entries->reviewDecision($body),
            'research'        => $entries->addResearch($body),
            'goal'            => $entries->addGoal($body),
            'goal-milestone'  => $entries->addGoalMilestone($body),
            'milestone-done'  => $entries->completeGoalMilestone($body),
            'commitment'      => $entries->addCommitment($body),
            'commitment-done' => $entries->honourCommitment($body),
            'setting'         => $entries->updateSetting($body),
            'deal'         => $entries->addDeal($body),
            'opportunity'  => $entries->addOpportunity($body),
            'debt'         => $entries->addDebt($body),
            'savings-goal' => $entries->addSavingsGoal($body),
            'savings'      => $entries->addSavingsEntry($body),
            'bill'         => $entries->addBill($body),
            'pay-bill'     => $entries->payBill($body),
            'cash'         => $entries->recordCashBalance($body),
            'day-review'   => (new TodayService())->submitReview($body),
            'outreach'     => $entries->logOutreach((int) ($body['prospects'] ?? 0), (int) ($body['followups'] ?? 0)),
            default        => Json::error('Unknown entry type: ' . $m[1], 404),
        };

        Json::ok($result);
    }

    // POST /api/command a question for the board.
    if ($route === 'command') {
        $question = trim((string) ($body['question'] ?? ''));

        if ($question === '') {
            Json::error('Ask something first.');
        }

        $result = $dashboard->board->answer($question, $dashboard->context());
        Json::ok($result);
    }

    // POST /api/ai/refresh regenerate today's brief and board recommendation.
    // POST /api/ai/test one live call, reported honestly. This is the check that
    // would have caught the fallbacks rejection on day one instead of letting the
    // rules engine answer silently for a fortnight.
    if ($route === 'ai/test') {
        $driver  = new \Germa\Ai\ClaudeDriver();
        $started = microtime(true);

        // The test has to be logged like any other call, or the evidence panel it
        // exists to satisfy will keep reporting that nothing has ever succeeded.
        $prompt = 'Connection test: reply with exactly OK';

        try {
            $answer  = $driver->answer($prompt, $dashboard->context());
            $latency = (int) round((microtime(true) - $started) * 1000);

            \Germa\Support\Database::insert('commands', [
                'prompt'     => $prompt,
                'response'   => mb_substr(trim($answer), 0, 2000),
                'mode'       => 'normal',
                'driver'     => 'claude',
                'model'      => (string) Config::get('ai.model'),
                'latency_ms' => $latency,
            ]);

            Json::ok([
                'reached' => true,
                'model'   => (string) Config::get('ai.model'),
                'latency' => $latency,
                'answer'  => mb_substr(trim($answer), 0, 200),
                'message' => sprintf(
                    'The model answered in %s. Everything on this screen that said "unproven" can now be trusted.',
                    $latency < 1000 ? $latency . 'ms' : round($latency / 1000, 1) . 's'
                ),
            ]);
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $started) * 1000);
            $reason  = \Germa\Ai\DriverException::readable($e->getMessage(), 400);

            \Germa\Support\Database::insert('commands', [
                'prompt'     => $prompt,
                'response'   => null,
                'mode'       => 'normal',
                // Logged as rules because that is what would have answered in
                // ordinary use: a failed model call is not a model answer.
                'driver'     => 'rules',
                'latency_ms' => $latency,
                'error'      => mb_substr($reason, 0, 255),
            ]);

            Json::ok([
                'reached' => false,
                'model'   => (string) Config::get('ai.model'),
                'latency' => $latency,
                'error'   => $reason,
                'message' => 'The call failed. Until this succeeds, every AI chip in the OS is a rules chip.',
            ]);
        }
    }

    if ($route === 'ai/refresh') {
        $data = $dashboard->build(true);
        Json::ok(['brief' => $data['brief'], 'board' => $data['board']]);
    }

    Json::error('No such endpoint: ' . $route, 404);
}

/** Recursively converts service objects (Money, Metric) into JSON-safe values. */
function germa_jsonable(mixed $value): mixed
{
    if ($value instanceof \Germa\Support\Money) {
        return ['display' => $value->format(), 'minor' => $value->minor, 'currency' => $value->currency];
    }

    if ($value instanceof \JsonSerializable) {
        return $value->jsonSerialize();
    }

    if (is_array($value)) {
        return array_map('germa_jsonable', $value);
    }

    return $value;
}

function germa_fail(Throwable $e, string $path): never
{
    $isApi = str_starts_with($path, 'api/');
    $debug = (bool) Config::get('app.debug', false);

    error_log('[germa] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if ($isApi) {
        Json::error($debug ? $e->getMessage() : 'Something broke on the server.', 500);
    }

    http_response_code(500);
    $message = $debug ? $e->getMessage() : 'Something broke on the server.';
    $trace   = $debug ? $e->getFile() . ':' . $e->getLine() : '';
    require __DIR__ . '/resources/views/error.php';
    exit;
}
