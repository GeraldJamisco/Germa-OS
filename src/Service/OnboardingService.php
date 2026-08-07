<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Database;
use Germa\Support\Money;
use Germa\Support\Settings;
use InvalidArgumentException;

/**
 * First-run setup.
 *
 * A fresh install has none of the information the OS needs to say anything true:
 * no survival figure to measure runway against, no cash balance to measure it
 * from, no operator to address. Rather than render a dashboard full of "not set"
 * and hope the user finds the Settings screen, the app refuses to pretend and
 * walks them through the essentials once, in the order the decision hierarchy
 * cares about them.
 *
 * The wizard collects only what the OS genuinely cannot function without. Two
 * steps are required (survival cost, cash on hand); the rest have a skip, because
 * a setup that cannot be finished is a setup that traps the user on the doorstep.
 * Everything else, projects, goals, pipeline, is added later through the app
 * itself, which is where that data belongs.
 *
 * Each step reuses the same validated writer the rest of the app uses, so a
 * value entered here is bound by exactly the same rules as one entered later.
 */
final class OnboardingService
{
    /**
     * The steps, in order. Each names what the OS decides with it, so the setup
     * reads like the Settings screen: a reason attached to every field, not a
     * form to be endured.
     */
    public const STEPS = [
        1 => [
            'key'   => 'you',
            'title' => 'Who the board is advising',
            'blurb' => 'So the OS can address you, and anchor any research it does to the market you actually operate in.',
        ],
        2 => [
            'key'   => 'survival',
            'title' => 'What a month costs to survive',
            'blurb' => 'The single most consequential number in the system. Runway, your daily income target, the mode the OS runs in and every rung of the goal ladder are all measured against it.',
        ],
        3 => [
            'key'   => 'cash',
            'title' => 'What is in the bank right now',
            'blurb' => 'Runway is this figure divided by what a month costs. Enter it honestly, even if it is zero: a real zero is more useful than a comfortable guess.',
        ],
        4 => [
            'key'   => 'debt',
            'title' => 'Anything you owe',
            'blurb' => 'A debt changes what survival really requires, so the OS would rather know about it now. Add one, or skip if there is nothing outstanding.',
        ],
        5 => [
            'key'   => 'projects',
            'title' => 'The work that pays for it',
            'blurb' => 'Projects are what the OS watches hardest: where your hours go, which ones return money, and which are quietly costing you time. Add the ones you are actually working on. At least one is required, because most of the OS stays dark without them.',
        ],
    ];

    public const LAST_STEP = 5;

    public function __construct(
        private readonly EntryService $entries = new EntryService(),
    ) {
    }

    /** Whether setup has been finished. */
    public static function isComplete(): bool
    {
        return Settings::bool('onboarding_complete');
    }

    /** Clamp any requested step into range. */
    public function step(mixed $requested): int
    {
        $n = is_numeric($requested) ? (int) $requested : 1;

        return max(1, min(self::LAST_STEP, $n));
    }

    /**
     * What the setup already knows, so a revisited step shows what was entered
     * rather than a blank field.
     *
     * @return array<string,string>
     */
    public function current(): array
    {
        $survival = Settings::money('monthly_survival_minor');
        $cash     = Database::first('SELECT balance_minor, currency FROM cash_snapshots ORDER BY id DESC LIMIT 1');
        $debt     = Database::first("SELECT name, principal_minor, currency FROM debts WHERE status = 'active' ORDER BY id LIMIT 1");

        return [
            'operator_name'     => Settings::get('operator_name'),
            'operator_location' => Settings::get('operator_location'),
            'survival'          => $survival->isZero() ? '' : (string) $survival->toMajor(),
            'cash'              => $cash === null ? '' : (string) Money::of((int) $cash['balance_minor'], (string) $cash['currency'])->toMajor(),
            'debt_name'         => $debt === null ? '' : (string) $debt['name'],
            'debt_amount'       => $debt === null ? '' : (string) Money::of((int) $debt['principal_minor'], (string) $debt['currency'])->toMajor(),
            'debt_currency'     => $debt === null ? 'USD' : (string) $debt['currency'],
        ];
    }

    /**
     * Projects added so far, for the running list on the projects step.
     *
     * @return list<array{name:string,status:string,priority:string}>
     */
    public function projects(): array
    {
        $rows = Database::all(
            "SELECT name, status, priority FROM projects WHERE archived = 0 ORDER BY id"
        );

        return array_map(static fn (array $r): array => [
            'name'     => (string) $r['name'],
            'status'   => (string) $r['status'],
            'priority' => (string) $r['priority'],
        ], $rows);
    }

    /**
     * Persist one step. Returns the next step to show, or null when setup is
     * finished. Throws on invalid input, which the handler renders in place.
     *
     * @param array<string,mixed> $input
     */
    public function save(int $step, array $input): ?int
    {
        switch ($step) {
            case 1:
                $this->saveOperator($input);
                return 2;

            case 2:
                $this->saveSurvival($input);
                return 3;

            case 3:
                // The OS's own writer, so this cash balance obeys the same rules
                // as one recorded from the dashboard later.
                $this->entries->recordCashBalance($input);
                return 4;

            case 4:
                // Debt is optional. "skip" arrives when the user chose to move on.
                if (($input['skip'] ?? '') !== '1') {
                    $this->entries->addDebt($input);
                }
                return 5;

            case 5:
                return $this->saveProjects($input);

            default:
                throw new InvalidArgumentException('Unknown setup step.');
        }
    }

    // ── Steps that write settings directly ──────────────────────────────────

    /** @param array<string,mixed> $input */
    private function saveOperator(array $input): void
    {
        $name = trim((string) ($input['operator_name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('The OS needs a name to address you by.');
        }

        Settings::set('operator_name', mb_substr($name, 0, 80));
        Settings::set('operator_location', mb_substr(trim((string) ($input['operator_location'] ?? '')), 0, 120));
    }

    /** @param array<string,mixed> $input */
    private function saveSurvival(array $input): void
    {
        $raw = preg_replace('/[^0-9.]/', '', (string) ($input['survival'] ?? '')) ?? '';

        if ($raw === '' || !is_numeric($raw)) {
            throw new InvalidArgumentException('Enter what a month costs to run.');
        }

        $money = Money::fromMajor($raw, Money::base());

        if ($money->isZero()) {
            throw new InvalidArgumentException('This cannot be zero. Every runway figure is measured against it.');
        }

        Settings::set('monthly_survival_minor', (string) $money->minor);
    }

    /**
     * The projects step. Adds the project currently typed in, then either stays
     * here for another or finishes. Finishing requires at least one project to
     * exist, because the OS's allocation, revenue-attribution and at-risk logic
     * all read from projects and have nothing to say without them.
     *
     * @param array<string,mixed> $input
     */
    private function saveProjects(array $input): ?int
    {
        // A typed-in project is added whichever button was pressed, so nothing is
        // lost by pressing Finish with a name still in the field.
        if (trim((string) ($input['name'] ?? '')) !== '') {
            $this->entries->addProject($input);
        }

        $finishing = ($input['action'] ?? 'add') === 'finish';

        if (!$finishing) {
            return 5;
        }

        if ($this->projectCount() === 0) {
            throw new InvalidArgumentException(
                'Add at least one project to finish. It is what the rest of the OS reads from.'
            );
        }

        $this->finish();
        return null;
    }

    private function projectCount(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM projects WHERE archived = 0", [], 0);
    }

    /** Marks setup finished. Idempotent. */
    private function finish(): void
    {
        Settings::set('onboarding_complete', '1');
    }
}
