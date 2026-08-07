<?php

declare(strict_types=1);

namespace Germa\Service;

use Germa\Support\Clock;
use Germa\Support\Database;
use Germa\Support\Hours;
use Germa\Support\Money;
use Germa\Support\Provenance;
use Germa\Support\Settings;

/**
 * System → Settings.
 *
 * These are not preferences. Every value here is a threshold some other screen
 * measures against, and changing one changes what the OS concludes. Six days of
 * silence is what makes a lead "gone quiet". Ten hours in a fortnight is what
 * makes a project "at risk". Seven days of runway is what makes the whole system
 * switch into emergency mode and start refusing to talk about growth.
 *
 * A settings page that lists those as bare numbers hides that. So every row here
 * states two things beyond its value: what it decides, and what it is deciding
 * right now. A threshold you can see firing is a threshold you can argue with.
 *
 * The screen is also explicit about what is *not* here. Several judgements in this
 * system are deliberately not configurable, and someone hunting for the knob
 * deserves to be told it does not exist and why, rather than concluding the
 * feature is missing.
 */
final class SettingsService
{
    /** The USD rate is a manually maintained assumption, so it ages. */
    private const RATE_STALE_DAYS = 30;

    /**
     * Every editable setting, in the order the decision hierarchy cares about
     * them: survival first, then risk, then sales, then the operator's own
     * details. Each carries what it decides, so the row is an argument rather
     * than a field.
     */
    private const FIELDS = [
        'monthly_survival_minor' => [
            'group'  => 'Survival',
            'label'  => 'Monthly household requirement',
            'kind'   => 'money',
            'decides' => 'Runway, the daily income target, the mode the OS runs in, and every rung of the goal ladder',
            'help'   => 'The single most consequential number in the system. Set it too low and the OS will tell you the month is covered when it is not.',
        ],
        'runway_critical_days' => [
            'group'  => 'Risk',
            'label'  => 'Runway that counts as critical',
            'kind'   => 'days',
            'decides' => 'Emergency mode, critical alerts, and whether growth work is allowed to be discussed at all',
            'help'   => 'Below this many days of cover the OS stops balancing and starts triaging.',
        ],
        'runway_warning_days' => [
            'group'  => 'Risk',
            'label'  => 'Runway that counts as tight',
            'kind'   => 'days',
            'decides' => 'The TIGHT financial status and the warnings that follow from it',
            'help'   => 'Must be higher than the critical threshold, or the OS can never report a warning before an emergency.',
        ],
        'non_revenue_hours_cap' => [
            'group'  => 'Risk',
            'label'  => 'Hours a non-earning project may consume',
            'kind'   => 'hours',
            'decides' => 'Whether a project is flagged AT RISK, and what the Critic objects to',
            'help'   => 'Measured over 14 days. Raise it and the Critic goes quiet, which is not the same as the problem going away.',
        ],
        'auto_emergency_mode' => [
            'group'  => 'Risk',
            'label'  => 'Let the OS switch modes on its own',
            'kind'   => 'bool',
            'decides' => 'Whether emergency mode can be entered without you agreeing to it',
            'help'   => 'Turn this off and the OS will keep advising as though the position were normal, even when it is not.',
        ],
        'followup_stale_days' => [
            'group'  => 'Sales',
            'label'  => 'Days before a lead counts as neglected',
            'kind'   => 'days',
            'decides' => 'Which deals appear as gone quiet, the follow-up alerts, and the cost of waiting',
            'help'   => 'The number that decides whether a silence is normal or a problem.',
        ],
        'daily_prospect_target' => [
            'group'  => 'Sales',
            'label'  => 'Prospects to contact per working day',
            'kind'   => 'count',
            'decides' => 'The sales score, the daily brief, and whether outreach counts as done',
            'help'   => 'A target you never hit teaches the score to be ignored. Set one you would actually defend.',
        ],
        'work_days_per_week' => [
            'group'  => 'Work',
            'label'  => 'Working days per week',
            'kind'   => 'count',
            'decides' => 'Working days left in the month, which divides into the income target',
            'help'   => 'Six means Monday to Saturday. This is arithmetic, not ambition.',
        ],
        'operator_name' => [
            'group'  => 'Operator',
            'label'  => 'Name',
            'kind'   => 'text',
            'decides' => 'How the OS addresses you',
            'help'   => null,
        ],
        'operator_location' => [
            'group'  => 'Operator',
            'label'  => 'Location',
            'kind'   => 'text',
            'decides' => 'Context the board uses when researching prices and markets',
            'help'   => null,
        ],
    ];

    /**
     * Judgements that are deliberately not settings. Someone looking for the knob
     * should be told it does not exist and why, rather than assuming a gap.
     */
    private const FIXED = [
        [
            'label' => 'Emergency reserve target',
            'value' => 'Three months of the survival requirement',
            'why'   => 'Derived so it cannot go stale. Typing it in would let the reserve target stay at an old number after the household cost changed.',
        ],
        [
            'label' => 'What counts as recurring revenue',
            'value' => 'Money from the same source in each of the last three complete months',
            'why'   => 'Repetition is the only evidence that something recurs. A flag anyone could set would make the figure worthless.',
        ],
        [
            'label' => 'Research shelf lives',
            'value' => '180 days for a fact, 90 for an estimate, 45 for an assumption',
            'why'   => 'A guess should expire sooner than a verified fact. Making these equal, or editable, is how old information starts looking current.',
        ],
        [
            'label' => 'Pattern threshold',
            'value' => 'Four observations',
            'why'   => 'Below this the Insights screen refuses to call something a pattern. Lowering it would let coincidence speak with a confident voice.',
        ],
        [
            'label' => 'A sustainable week',
            'value' => '55 hours',
            'why'   => 'Not yours to raise. The point of the household screen is to notice a pace being kept up, not to be reconfigured until it approves of one.',
        ],
        [
            'label' => 'Decision review interval',
            'value' => '14 days by default, set per decision',
            'why'   => 'Each decision carries its own review date, so a global setting would only override the judgement made when it was logged.',
        ],
    ];

    public function __construct(
        private readonly FinanceService $finance = new FinanceService(),
        private readonly DebtService $debt = new DebtService(),
        private readonly PipelineService $pipeline = new PipelineService(),
        private readonly ProjectService $projects = new ProjectService(),
        private readonly ModeService $mode = new ModeService(new FinanceService()),
    ) {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $groups = $this->groups();
        $rate   = $this->exchangeRate();

        return [
            'insight'     => $this->insight($groups, $rate),
            'groups'      => $groups,
            'rate'        => $rate,
            'fixed'       => self::FIXED,
            'data'        => $this->dataState(),
            'conflicts'   => $this->conflicts(),
            'suggestions' => [
                'Is my survival requirement realistic?',
                'Am I setting these thresholds to feel better?',
                'What happens if I raise my follow-up window?',
                'What should my daily prospect target be?',
                'Call the board',
            ],
        ];
    }

    // ── Editable thresholds, with their live effect ──────────────────────────

    /** @return list<array<string,mixed>> */
    private function groups(): array
    {
        $rows = [];

        foreach (self::FIELDS as $key => $field) {
            $rows[$field['group']][] = [
                'key'     => $key,
                'label'   => $field['label'],
                'kind'    => $field['kind'],
                'decides' => $field['decides'],
                'help'    => $field['help'],
                'value'   => $this->rawValue($key, $field['kind']),
                'display' => $this->displayValue($key, $field['kind']),
                'effect'  => $this->liveEffect($key),
                'tone'    => $this->effectTone($key),
            ];
        }

        $groups = [];

        foreach ($rows as $label => $fields) {
            $groups[] = [
                'label' => $label,
                'note'  => match ($label) {
                    'Survival' => 'The number everything else is measured against',
                    'Risk'     => 'What the OS treats as dangerous',
                    'Sales'    => 'What counts as keeping up',
                    'Work'     => 'The shape of your week',
                    default    => 'Who the OS is talking to',
                },
                'fields' => $fields,
            ];
        }

        return $groups;
    }

    private function rawValue(string $key, string $kind): string
    {
        if ($kind === 'money') {
            // The form edits major units; the column stores minor.
            return (string) Settings::money($key)->toMajor();
        }
        if ($kind === 'bool') {
            return Settings::bool($key, true) ? '1' : '0';
        }

        return Settings::get($key);
    }

    private function displayValue(string $key, string $kind): string
    {
        return match ($kind) {
            'money' => Settings::money($key)->format(),
            'days'  => Settings::int($key) . ' day' . (Settings::int($key) === 1 ? '' : 's'),
            'hours' => Hours::format((float) Settings::int($key)) . ' per fortnight',
            'count' => (string) Settings::int($key),
            'bool'  => Settings::bool($key, true) ? 'On' : 'Off',
            default => Settings::get($key) === '' ? 'Not set' : Settings::get($key),
        };
    }

    /**
     * What the threshold is doing right now. This is the whole point of the
     * screen: a number that is currently firing should say so on the row.
     */
    private function liveEffect(string $key): string
    {
        $runway   = $this->finance->runwayDays();
        $survival = $this->finance->monthlySurvivalCost();

        return match ($key) {
            'monthly_survival_minor' => $survival->isZero()
                ? 'Not set, so runway cannot be calculated and every screen that needs it says so instead of guessing.'
                : sprintf(
                    'Currently produces a %s daily cost%s.',
                    $this->finance->dailySurvivalCost()->format(),
                    $runway === null ? ' and no runway figure, because no cash balance is recorded' : sprintf(' and %d days of runway', $runway)
                ),

            // States the threshold, not the mode. The stored mode is set when the
            // dashboard evaluates it, so claiming it here would contradict the
            // auto-mode row directly below on the same card.
            'runway_critical_days' => $runway === null
                ? 'No runway figure to compare against, so this threshold is not in play.'
                : ($runway < Settings::int($key, 7)
                    ? sprintf('Breached: %d days of runway is below this, so the position qualifies as an emergency.', $runway)
                    : sprintf('Not breached: %d days of runway is above this.', $runway)),

            'runway_warning_days' => $runway === null
                ? 'No runway figure to compare against, so this threshold is not in play.'
                : ($runway < Settings::int($key, 21)
                    ? sprintf('Breached: %d days is inside the warning band.', $runway)
                    : sprintf('Not breached: %d days is outside the warning band.', $runway)),

            'non_revenue_hours_cap' => $this->atRiskEffect(),

            // Reads the stored mode rather than evaluating: rendering a settings
            // page should not have the side effect of switching the OS's mode.
            'auto_emergency_mode' => Settings::bool($key, true)
                ? sprintf(
                    'On. The mode on record is %s, and it is re-evaluated against the runway whenever the dashboard is opened.',
                    strtolower($this->mode->label($this->mode->current()))
                )
                : sprintf(
                    'Off, so the mode stays at %s until you change it by hand, whatever the runway does.',
                    strtolower($this->mode->label($this->mode->current()))
                ),

            'followup_stale_days' => sprintf(
                '%d live deal%s currently past this window.',
                count($this->pipeline->staleLeads()),
                count($this->pipeline->staleLeads()) === 1 ? ' is' : 's are'
            ),

            'daily_prospect_target' => sprintf(
                'Sets a target of %d contacts a day, which is %d across a %d-day working week.',
                Settings::int($key, 20),
                Settings::int($key, 20) * Settings::int('work_days_per_week', 6),
                Settings::int('work_days_per_week', 6)
            ),

            'work_days_per_week' => sprintf(
                '%d working days left this month, which is what the income target is divided by.',
                Clock::workingDaysLeftInMonth()
            ),

            'operator_name'     => 'Used in the greeting and anywhere the board addresses you directly.',
            'operator_location' => Settings::get('operator_location') === ''
                ? 'Not set, so research on prices and markets has no place to anchor to.'
                : sprintf('The board treats %s as the market you operate in.', Settings::get('operator_location')),

            default => '',
        };
    }

    private function atRiskEffect(): string
    {
        $portfolio = $this->projects->portfolio('month');
        $atRisk    = array_values(array_filter($portfolio, static fn (array $p): bool => $p['at_risk']));

        if ($atRisk === []) {
            return 'No project is currently over this cap.';
        }

        return sprintf(
            'Active on %s, which has taken %s in the last fortnight with nothing back.',
            $atRisk[0]['name'],
            Hours::format((float) $atRisk[0]['hours_14d'])
        );
    }

    private function effectTone(string $key): string
    {
        $runway = $this->finance->runwayDays();

        return match ($key) {
            'monthly_survival_minor' => $this->finance->monthlySurvivalCost()->isZero() ? 'high' : 'quiet',
            'runway_critical_days'   => $runway !== null && $runway < Settings::int($key, 7) ? 'high' : 'quiet',
            'runway_warning_days'    => $runway !== null && $runway < Settings::int($key, 21) ? 'warn' : 'quiet',
            'non_revenue_hours_cap'  => str_starts_with($this->atRiskEffect(), 'Active') ? 'warn' : 'quiet',
            'auto_emergency_mode'    => Settings::bool($key, true) ? 'quiet' : 'warn',
            'followup_stale_days'    => $this->pipeline->staleLeads() === [] ? 'quiet' : 'warn',
            'operator_location'      => Settings::get('operator_location') === '' ? 'warn' : 'quiet',
            default                  => 'quiet',
        };
    }

    // ── The exchange rate, which is an assumption ───────────────────────────

    /**
     * The USD rate is not a setting so much as a research note that happens to
     * live in settings. The debt is denominated in USD, so a stale rate quietly
     * misstates every UGX figure derived from it, and nothing else in the OS
     * would ever mention that.
     *
     * @return array<string,mixed>
     */
    private function exchangeRate(): array
    {
        $rate  = Settings::float('usd_ugx_rate', 0.0);
        $setOn = Settings::get('usd_ugx_rate_set_on');
        $days  = $setOn === '' ? null : abs(Clock::daysUntil($setOn));

        $state = match (true) {
            $rate <= 0.0                   => 'missing',
            $days === null                 => 'undated',
            $days > self::RATE_STALE_DAYS   => 'stale',
            default                        => 'current',
        };

        $debt = $this->debt->summary();

        return [
            'rate'    => $rate,
            'display' => $rate <= 0.0 ? 'Not set' : number_format($rate, 0) . ' UGX to 1 USD',
            'set_on'  => $setOn === '' ? null : $setOn,
            'days'    => $days,
            'state'   => $state,
            'when'    => match (true) {
                $days === null => 'Never confirmed',
                $days === 0    => 'Confirmed today',
                $days === 1    => 'Confirmed yesterday',
                default        => sprintf('Confirmed %d days ago', $days),
            },
            'tone'    => match ($state) {
                'current' => 'good',
                'stale'   => 'warn',
                default   => 'high',
            },
            'verdict' => match ($state) {
                'current' => 'Recent enough to convert with.',
                'undated' => 'No date on record, so there is no way to know whether it is current.',
                'missing' => 'No rate set. Foreign-currency debt cannot be converted, so it is reported in its own currency and excluded from base-currency totals.',
                default   => sprintf(
                    'Older than %d days. Every UGX figure derived from a USD amount is being converted at a rate nobody has checked recently.',
                    self::RATE_STALE_DAYS
                ),
            },
            // Naming what depends on it is what makes the staleness matter.
            'affects' => $debt['remaining']->isZero()
                ? 'Nothing currently depends on it: no foreign-currency debt is outstanding.'
                : sprintf('%s of debt is denominated in a foreign currency and converted with it.', $debt['remaining']->format()),
        ];
    }

    // ── Internal consistency ────────────────────────────────────────────────

    /**
     * Settings that contradict each other. A warning threshold below the critical
     * one is not a preference, it is a configuration that can never produce a
     * warning, and nothing else would ever say so.
     *
     * @return list<array{title:string,detail:string}>
     */
    private function conflicts(): array
    {
        $conflicts = [];

        $critical = Settings::int('runway_critical_days', 7);
        $warning  = Settings::int('runway_warning_days', 21);

        if ($warning <= $critical) {
            $conflicts[] = [
                'title'  => 'The warning threshold is not above the critical one',
                'detail' => sprintf(
                    'Warning is set to %d days and critical to %d. The OS can never report a warning before an emergency, so the early signal is gone.',
                    $warning,
                    $critical
                ),
            ];
        }

        if ($this->finance->monthlySurvivalCost()->isZero()) {
            $conflicts[] = [
                'title'  => 'No monthly household requirement is set',
                'detail' => 'Runway, the daily income target, the mode and the goal ladder all measure against it. Until it is set they report as unavailable rather than guessing.',
            ];
        }

        if (!Settings::bool('auto_emergency_mode', true)) {
            $conflicts[] = [
                'title'  => 'The OS cannot switch to emergency mode on its own',
                'detail' => 'It will keep advising as though the position were normal even when the runway says otherwise. That is a valid choice, but it is a choice.',
            ];
        }

        if (Settings::int('daily_prospect_target', 20) === 0) {
            $conflicts[] = [
                'title'  => 'The daily prospect target is zero',
                'detail' => 'The sales score will read as satisfied every day without any outreach happening.',
            ];
        }

        return $conflicts;
    }

    // ── What is in the database ─────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function dataState(): array
    {
        $counts = [];

        foreach ([
            'ledger_entries'  => 'Money movements',
            'deals'           => 'Deals',
            'clients'         => 'Clients',
            'projects'        => 'Projects',
            'tasks'           => 'Tasks',
            'decisions'       => 'Decisions',
            'research_notes'  => 'Research notes',
            'commands'        => 'Questions asked',
        ] as $table => $label) {
            $counts[] = [
                'label' => $label,
                'value' => (int) Database::value('SELECT COUNT(*) FROM ' . $table, [], 0),
            ];
        }

        return [
            'demo'   => Settings::bool('demo_data'),
            'counts' => $counts,
            // Destructive and irreversible, so it is documented rather than
            // offered as a button that could be clicked by accident.
            'reset'  => 'php bin/migrate.php --fresh --demo',
        ];
    }

    // ── Narrative ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $groups
     * @param array<string,mixed>       $rate
     * @return array{text:string,provenance:string}
     */
    private function insight(array $groups, array $rate): array
    {
        $firing = 0;

        foreach ($groups as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['tone'] !== 'quiet') {
                    $firing++;
                }
            }
        }

        $parts = [];

        $parts[] = 'Nothing here is a preference. Every value is a threshold another screen measures against, '
            . 'so each row says what it decides and what it is deciding right now.';

        $parts[] = $firing === 0
            ? 'None of them are currently active, which means the position sits inside every boundary you set.'
            : sprintf(
                '%d %s currently active, which is why the other screens are saying what they are saying.',
                $firing,
                $firing === 1 ? 'is' : 'are'
            );

        if ($rate['state'] === 'stale' || $rate['state'] === 'undated') {
            $parts[] = 'The exchange rate is an assumption with an age, and it has one. It is treated below the way any '
                . 'other unverified belief would be.';
        }

        return ['text' => implode(' ', $parts), 'provenance' => Provenance::Calculated->value];
    }
}
