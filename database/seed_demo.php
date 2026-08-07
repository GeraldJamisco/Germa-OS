<?php

declare(strict_types=1);

/**
 * Demo dataset the numbers from the UI mockup, so the dashboard can be seen
 * fully populated. Sets `demo_data = 1`, which makes the dashboard render a
 * visible "demo data" banner. Never load this on top of real records.
 *
 * Run via: php bin/migrate.php --fresh --demo
 */

use Germa\Support\Database;
use Germa\Support\Settings;

if (!class_exists(Database::class)) {
    exit("seed_demo.php must be run through bin/migrate.php\n");
}

$today = new DateTimeImmutable('today');
$d     = static fn(int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
$dt    = static fn(int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d H:i:s');

Database::transaction(static function () use ($d, $dt, $today): void {

    // ── Baseline the demo owns ──────────────────────────────────────────────
    // seed.sql now ships an empty install, so the operator, the survival
    // figure, the projects, the goals and the loan live here rather than there.
    // The demo is also marked as already set up, so it skips the first-run flow.
    Settings::set('operator_name', 'Gerald');
    Settings::set('operator_location', 'Kampala');
    Settings::set('monthly_survival_minor', '580000');
    Settings::set('onboarding_complete', '1');

    // Known baseline loan of $535.
    if (Database::first("SELECT id FROM debts WHERE name = 'Personal loan'") === null) {
        Database::insert('debts', [
            'name'            => 'Personal loan',
            'lender'          => null,
            'principal_minor' => 53500,
            'currency'        => 'USD',
            'status'          => 'active',
            'opened_on'       => $d(-30),
        ]);
    }

    // The four projects named in the UI brief.
    foreach ([
        ['Client Websites', 'revenue',     'HIGH'],
        ['FlowPesa',        'strategic',   'MEDIUM'],
        ['Castalore',       'validation',  'LOW'],
        ['Ride Sharing',    'development',  'LOW'],
    ] as [$name, $status, $priority]) {
        if (Database::first('SELECT id FROM projects WHERE name = :n', ['n' => $name]) === null) {
            Database::insert('projects', [
                'name'           => $name,
                'status'         => $status,
                'priority'       => $priority,
                'decision'       => 'continue',
                'progress_pct'   => 0,
                'currency'       => 'UGX',
                'next_review_on' => $d(7),
            ]);
        }
    }

    // The four goal horizons.
    foreach ([
        ['30d',  'Generate first UGX 580,000 of monthly income', 'money',   580000,  'UGX', 30,  1],
        ['90d',  'Reach steady monthly income of UGX 1,800,000',  'money',   1800000, 'UGX', 90,  2],
        ['1y',   'Eliminate debt and build emergency reserve',    'money',   53500,   'USD', 365, 3],
        ['long', 'Build substantial business assets',             'percent', 100,     null,  null, 4],
    ] as [$horizon, $title, $metric, $target, $currency, $days, $order]) {
        if (Database::first('SELECT id FROM goals WHERE title = :t', ['t' => $title]) === null) {
            Database::insert('goals', [
                'horizon'      => $horizon,
                'title'        => $title,
                'metric'       => $metric,
                'target_value' => $target,
                'current_value' => 0,
                'currency'     => $currency,
                'start_on'     => $d(0),
                'target_on'    => $days === null ? null : $d($days),
                'sort_order'   => $order,
            ]);
        }
    }

    // ── Clients and pipeline ────────────────────────────────────────────────
    // Stage counts match the mockup funnel: 20 / 8 / 3 / 2 / 1.
    $stages = [
        'prospect'    => 20,
        'lead'        => 8,
        'proposal'    => 3,
        'negotiation' => 2,
        'won'         => 1,
    ];

    // How many entered each stage inside the last 7 days this is what the
    // "+6 this week" deltas are read from.
    $newThisWeek = ['prospect' => 6, 'lead' => 2, 'proposal' => 0, 'negotiation' => 1, 'won' => 1];

    $orgs = [
        'St. Mary Villa School',
        'Kabalagala Pharmacy',
        'Nakawa Hardware',
        'Bugolobi Dental',
        'Ntinda Fitness',
        'Kireka Auto Parts',
        'Muyenga Boutique',
        'Kansanga Bakery',
        'Kololo Legal',
        'Wandegeya Optics',
        'Najjera Daycare',
        'Bukoto Coffee',
        'Kyanja Poultry',
        'Nsambya Clinic',
        'Lubowa Estates',
        'Gayaza Agro',
        'Entebbe Tours',
        'Kisaasi Salon',
        'Mukono Printers',
        'Seeta Logistics',
        'Kawempe Grocers',
        'Nateete Motors',
        'Mbuya Interiors',
        'Kabowa Tailors',
        'Namugongo Hostel',
        'Kyaliwajjala Mart',
        'Bweyogerere Steel',
        'Kitintale Farm',
        'Luzira Foods',
        'Munyonyo Lodge',
        'Kikoni Books',
        'Kanyanya Water',
        'Busabala Marina',
        'Zana Electrics',
    ];

    $values = [1_200_000, 800_000, 650_000, 450_000, 300_000, 250_000, 180_000];
    $orgIdx = 0;
    $ageDay = -1;

    foreach ($stages as $stage => $count) {
        $fresh = $newThisWeek[$stage];

        for ($i = 0; $i < $count; $i++) {
            $org = $orgs[$orgIdx % count($orgs)];
            $orgIdx++;

            $clientId = Database::insert('clients', [
                'name'  => $org,
                'org'   => $org,
                'notes' => 'Demo record',
            ]);

            // The first $fresh deals of each stage entered it inside 7 days.
            $enteredDaysAgo = $i < $fresh ? - (1 + ($i % 6)) : - (9 + ($i % 40));

            $probability = match ($stage) {
                'prospect'    => 5,
                'lead'        => 20,
                'proposal'    => 40,
                'negotiation' => 65,
                'won'         => 100,
                default       => 10,
            };

            $lastContact = match ($stage) {
                // Five leads deliberately left stale so the follow-up rule fires.
                'lead'  => $i < 5 ? $d(- (7 + $i)) : $d(- (1 + $i)),
                default => $d(max($enteredDaysAgo, -20)),
            };

            $dealId = Database::insert('deals', [
                'client_id'         => $clientId,
                'title'             => $org . ' website & systems',
                'stage'             => $stage,
                'value_minor'       => $values[$orgIdx % count($values)],
                'currency'          => 'UGX',
                'probability_pct'   => $probability,
                'expected_close_on' => $d(7 + ($i % 30)),
                'last_contact_on'   => $lastContact,
                'next_action'       => $stage === 'prospect' ? 'Send intro message' : 'Follow up',
                'next_action_on'    => $d(($i % 5) - 1),
                'source'            => 'Direct outreach',
                'created_at'        => $dt($enteredDaysAgo),
            ]);

            Database::insert('deal_stage_events', [
                'deal_id'    => $dealId,
                'from_stage' => null,
                'to_stage'   => $stage,
                'changed_at' => $dt($enteredDaysAgo),
            ]);

            $ageDay--;
        }
    }

    // ── Projects ────────────────────────────────────────────────────────────
    $projects = [
        'Client Websites' => ['progress' => 65, 'milestone' => 'Demo sign-off',          'milestone_days' => 3,  'hours' => 22],
        'FlowPesa'        => ['progress' => 30, 'milestone' => 'Payments spike',         'milestone_days' => 56, 'hours' => 18],
        'Castalore'       => ['progress' => 15, 'milestone' => '10 customer interviews', 'milestone_days' => 15, 'hours' => 4],
        'Ride Sharing'    => ['progress' => 22, 'milestone' => 'Driver alpha',           'milestone_days' => 45, 'hours' => 6],
    ];

    foreach ($projects as $name => $p) {
        $row = Database::first('SELECT id FROM projects WHERE name = :n', ['n' => $name]);
        if ($row === null) {
            continue;
        }
        $projectId = (int) $row['id'];

        Database::update('projects', [
            'progress_pct'      => $p['progress'],
            'next_milestone'    => $p['milestone'],
            'next_milestone_on' => $d($p['milestone_days']),
        ], ['id' => $projectId]);

        // Spread the hours across the last 14 days so the Critic's
        // "hours logged with no revenue" rule has something to read.
        $remaining = (int) ($p['hours'] * 60);
        for ($day = 1; $day <= 14 && $remaining > 0; $day++) {
            $slice = (int) min($remaining, random_int(45, 150));
            Database::insert('project_time_logs', [
                'project_id' => $projectId,
                'minutes'    => $slice,
                'logged_on'  => $d(-$day),
                'note'       => 'Demo time log',
            ]);
            $remaining -= $slice;
        }
    }

    // ── Goals: milestones, and the household's own goals ────────────────────
    // The spec's chain is Goal -> Milestones -> Tasks -> Deadline -> Metric ->
    // Review. One goal is deliberately left without milestones so the Goals
    // screen has an abstract goal to object to.
    $goalId = static function (string $like): ?int {
        $row = Database::first('SELECT id FROM goals WHERE title LIKE :t LIMIT 1', ['t' => '%' . $like . '%']);

        return $row === null ? null : (int) $row['id'];
    };

    $milestones = [
        ['Generate first UGX', [
            ['Contact the 12 leads that have gone quiet', 'At least 3 replies', 2],
            ['Get one proposal accepted with a deposit', 'Deposit received', 9],
            ['Invoice the Client Websites demo sign-off', 'Invoice sent', 4],
        ]],
        ['Reach steady monthly income', [
            ['Convert two clients to monthly retainers', 'Two retainers signed', 45],
            ['Publish a fixed-price package page', 'Page live with prices', 21],
            ['Raise the standard website price to UGX 1.2M', 'Three quotes at the new price', null],
        ]],
        ['Eliminate debt', [
            ['Clear the $75 instalment on schedule', 'Paid on 12 August', 7],
            ['Reach one month of survival cost in reserve', 'UGX 580,000 banked', 120],
        ]],
    ];

    foreach ($milestones as [$like, $rows]) {
        $id = $goalId($like);
        if ($id === null) {
            continue;
        }
        foreach ($rows as $i => [$title, $metric, $days]) {
            Database::insert('goal_milestones', [
                'goal_id'    => $id,
                'title'      => $title,
                'metric'     => $metric,
                'target_on'  => $days === null ? null : $d($days),
                'sort_order' => $i + 1,
            ]);
        }
    }

    // The review clock the spec asks for, on the goals that have milestones.
    Database::run(
        "UPDATE goals SET review_on = :r WHERE horizon IN ('30d', '90d')",
        ['r' => $d(14)]
    );

    // Two goals that belong to the household rather than the business. These are
    // what the Shared Goals screen exists for.
    $householdGoals = [
        [
            'horizon'      => '90d',
            'title'        => 'Pay the school fees term in full and on time',
            'metric'       => 'money',
            'target_value' => 900_000,
            'current_value' => 0,
            'currency'     => 'UGX',
            'scope'        => 'shared',
            'why'          => 'Agreed with the household. Paying late has a cost that is not only money.',
            'start_on'     => $d(0),
            'target_on'    => $d(75),
            'sort_order'   => 5,
        ],
        [
            'horizon'      => '1y',
            'title'        => 'Move to a place with reliable power and water',
            'metric'       => 'money',
            'target_value' => 2_400_000,
            'current_value' => 0,
            'currency'     => 'UGX',
            'scope'        => 'household',
            'why'          => 'Outages cost working days as well as comfort.',
            'start_on'     => $d(0),
            'target_on'    => $d(300),
            'sort_order'   => 6,
        ],
    ];

    foreach ($householdGoals as $goal) {
        Database::insert('goals', $goal);
    }

    $feesId = $goalId('school fees');
    if ($feesId !== null) {
        foreach ([
            ['Agree the payment split with the household', 'Both agreed in writing', 5],
            ['Set aside the first UGX 300,000', 'UGX 300,000 banked', 30],
        ] as $i => [$title, $metric, $days]) {
            Database::insert('goal_milestones', [
                'goal_id'    => $feesId,
                'title'      => $title,
                'metric'     => $metric,
                'target_on'  => $d($days),
                'sort_order' => $i + 1,
            ]);
        }
    }

    // ── Household commitments ───────────────────────────────────────────────
    // What is owed to people rather than to creditors. One is deliberately past
    // its cadence, because that is the thing no financial screen would raise.
    $commitments = [
        [
            'title'            => 'A full day with the family, no work',
            'kind'             => 'rest',
            'cadence_days'     => 7,
            'last_honoured_on' => $d(-18),
            'note'             => 'Was weekly. Has not happened in over two weeks.',
        ],
        [
            'title'            => 'Sit down with the household on the money position',
            'kind'             => 'relationship',
            'cadence_days'     => 30,
            'last_honoured_on' => $d(-11),
            'note'             => 'Monthly. Easier to do when the numbers are bad, not harder.',
        ],
        [
            'title'            => 'School run and homework',
            'kind'             => 'responsibility',
            'cadence_days'     => 1,
            'last_honoured_on' => $d(-1),
            'note'             => null,
        ],
        [
            'title'            => 'An hour on something that is not work',
            'kind'             => 'development',
            'cadence_days'     => 14,
            'last_honoured_on' => null,
            'note'             => 'Never recorded. The first thing to go, and the last to be missed.',
        ],
    ];

    foreach ($commitments as $commitment) {
        Database::insert('household_commitments', $commitment);
    }

    // ── Decisions ───────────────────────────────────────────────────────────
    // The spec gives every decision a review date, and a decision never looked
    // at again is indistinguishable from a guess. Two are seeded past their
    // date so the boardroom's review loop has something to hold Gerald to.
    $decisions = [
        [
            'title'          => 'Take the retainer over the one-off build',
            'situation'      => 'Two offers arrived the same week and only one could be staffed.',
            'recommendation' => 'Take the retainer. Recurring revenue at a lower rate beats a larger one-off while runway is thin.',
            'next_action'    => 'Confirm scope for month two',
            'metric'         => 'Retainer invoice paid by the 5th',
            'status'         => 'decided',
            'decided_on'     => $d(-21),
            'review_on'      => $d(-4),
        ],
        [
            'title'          => 'Keep FlowPesa alive at reduced hours',
            'situation'      => 'FlowPesa has consumed hours with no revenue path inside 30 days.',
            'recommendation' => 'Cap it at four hours a week until a payment signal appears.',
            'next_action'    => 'Decide again once the cap has run a fortnight',
            'metric'         => 'One paying pilot user',
            'status'         => 'decided',
            'decided_on'     => $d(-14),
            'review_on'      => $d(0),
        ],
    ];

    // One deliberately missing its metric, so the Decisions screen has an
    // ungradeable decision to object to rather than a uniformly tidy list.
    $decisions[] = [
        'title'          => 'Buy the second monitor now rather than after the next invoice',
        'situation'      => 'Two screens would speed up build work, but cash is at zero.',
        'recommendation' => 'Wait. It buys speed on work that is not the constraint.',
        'next_action'    => 'Revisit once a deposit clears',
        'status'         => 'decided',
        'decided_on'     => $d(-6),
        'review_on'      => $d(9),
    ];

    $decisions[] = [
        'title'          => 'Take the church booking system at half rate to get the reference',
        'situation'      => 'They will pay UGX 400,000 against a normal 800,000, and would give a written reference.',
        'options_json'   => json_encode([
            'Take it at half rate for the reference',
            'Hold the price and risk losing it',
            'Offer a smaller scope at full rate',
        ], JSON_UNESCAPED_UNICODE),
        'recommendation' => 'Offer a smaller scope at full rate. Discounting sets the price for everyone they refer.',
        'metric'         => 'Signed at full rate, or walked away inside 7 days',
        'status'         => 'open',
    ];

    $decisions[] = [
        'title'          => 'Stop taking one-off website builds',
        'situation'      => 'One-off builds pay once and have to be won again every month.',
        'recommendation' => 'Stop, and convert two existing clients to retainers instead.',
        'next_action'    => 'Pitch a retainer to Zana Electrics',
        'metric'         => 'Two retainers signed inside 60 days',
        'status'         => 'reversed',
        'decided_on'     => $d(-45),
        'outcome'        => 'Reversed after 3 weeks. Refusing one-off work with no retainer pipeline in place '
            . 'removed the only income that was actually arriving. The right sequence is to land a retainer first, '
            . 'then stop taking builds.',
    ];

    foreach ($decisions as $decision) {
        Database::insert('decisions', $decision);
    }

    // ── Research ────────────────────────────────────────────────────────────
    // Deliberately mixed freshness: the spec's point is that old information
    // does not announce itself, so the screen needs something expired to show.
    $research = [
        [
            'question'        => 'What do Kampala agencies charge for a small business website?',
            'finding'         => 'Between UGX 1.2M and UGX 3M for five to eight pages, quoted by two agencies. '
                . 'At UGX 800,000 the current pricing is well under the market.',
            'kind'            => 'fact',
            'source'          => 'Direct quotes from two agencies, collected by phone',
            'applies_to'      => 'Pricing on new website deals',
            'checked_on'      => $d(-20),
            'shelf_life_days' => 180,
        ],
        [
            'question'        => 'How long does a school take to approve a purchase?',
            'finding'         => 'Roughly six to ten weeks, because it goes through a committee that meets monthly. '
                . 'Any school deal should be treated as a quarter away, not a month.',
            'kind'            => 'estimate',
            'source'          => 'Inferred from the St. Mary Villa conversation and one prior enquiry',
            'applies_to'      => 'Time-to-cash on school deals',
            'checked_on'      => $d(-70),
            'shelf_life_days' => 90,
        ],
        [
            'question'        => 'Will small businesses pay monthly for website upkeep?',
            'finding'         => 'Assumed yes at around UGX 150,000 a month. Nobody has been asked directly.',
            'kind'            => 'assumption',
            'source'          => null,
            'applies_to'      => 'The whole retainer strategy',
            'checked_on'      => $d(-60),
            'shelf_life_days' => 45,
        ],
        [
            'question'        => 'What does mobile money charge to receive UGX 500,000?',
            'finding'         => 'Around 1.5% on the receiving side, so roughly UGX 7,500 on a 500,000 payment.',
            'kind'            => 'estimate',
            'source'          => 'Provider tariff sheet',
            'applies_to'      => 'Quoting and invoicing',
            'checked_on'      => $d(-8),
            'shelf_life_days' => 90,
        ],
    ];

    foreach ($research as $note) {
        Database::insert('research_notes', $note);
    }

    // ── Money movement ──────────────────────────────────────────────────────
    // Income is attributed to the project that produced it, so the Income
    // screen can rank sources rather than showing one unattributed lump.
    $clientWebsites = Database::first("SELECT id FROM projects WHERE name = 'Client Websites'");
    $websitesId     = $clientWebsites === null ? null : (int) $clientWebsites['id'];

    // One paid client invoice, plus ordinary household spending, so the ledger
    // and the discipline score have real rows to work with.
    Database::insert('ledger_entries', [
        'direction'    => 'in',
        'amount_minor' => 450_000,
        'currency'     => 'UGX',
        'occurred_on'  => $d(-12),
        'category'     => 'client_payment',
        'description'  => 'Client Websites first milestone',
        'is_essential' => 1,
        'project_id'   => $websitesId,
        'source'       => 'entered',
    ]);

    // Distinct descriptions, because the Financial Overview lists transactions
    // by name: eight rows all reading "Household spending" would demonstrate
    // nothing.
    $spend = [
        ['food',      45_000, 1, -1,  'Market shopping'],
        ['transport', 12_000, 1, -1,  'Boda to client meeting'],
        ['food',      38_000, 1, -2,  'Groceries'],
        ['data',      20_000, 1, -3,  'Airtime & data bundle'],
        ['utilities', 60_000, 1, -4,  'Electricity token'],
        ['food',      41_000, 1, -5,  'Market shopping'],
        ['other',     35_000, 0, -6,  'Streaming subscription'],
        ['food',      52_000, 1, -7,  'Groceries'],
        ['business',  38_000, 1, -9,  'Hosting & domain renewal'],
        ['transport', 18_000, 1, -11, 'Transport'],
    ];
    foreach ($spend as [$category, $amount, $essential, $offset, $description]) {
        Database::insert('ledger_entries', [
            'direction'    => 'out',
            'amount_minor' => $amount,
            'currency'     => 'UGX',
            'occurred_on'  => $d($offset),
            'category'     => $category,
            'description'  => $description,
            'is_essential' => $essential,
            'source'       => 'entered',
        ]);
    }

    // A few months of history so the six-month cash-flow chart has a shape.
    $history = [
        [-5, 620_000, 540_000],
        [-4, 480_000, 505_000],
        [-3, 350_000, 495_000],
        [-2, 210_000, 470_000],
    ];
    foreach ($history as [$monthsAgo, $earned, $spent]) {
        $month = $today->modify($monthsAgo . ' months')->format('Y-m-15');

        Database::insert('ledger_entries', [
            'direction'    => 'in',
            'amount_minor' => $earned,
            'currency'     => 'UGX',
            'occurred_on'  => $month,
            'category'     => 'client_payment',
            'description'  => 'Client work',
            'is_essential' => 1,
            'project_id'   => $websitesId,
            'source'       => 'entered',
        ]);

        Database::insert('ledger_entries', [
            'direction'    => 'out',
            'amount_minor' => $spent,
            'currency'     => 'UGX',
            'occurred_on'  => $month,
            'category'     => 'rent',
            'description'  => 'Household costs',
            'is_essential' => 1,
            'source'       => 'entered',
        ]);
    }

    // Cash snapshot after the above the anchor the headline number uses.
    // The mark absorbs every entry written so far, so the balance reads as
    // observed rather than double-counting the seeded movement.
    Database::insert('cash_snapshots', [
        'currency'      => 'UGX',
        'balance_minor' => 0,
        'observed_on'   => $d(0),
        'ledger_mark'   => (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM ledger_entries', [], 0),
        'note'          => 'Demo balance',
    ]);

    // ── Debt schedule ───────────────────────────────────────────────────────
    $debt = Database::first('SELECT id FROM debts ORDER BY id LIMIT 1');
    if ($debt !== null) {
        Database::update('debts', [
            'lender'           => 'Private lender',
            'target_payoff_on' => $d(238),
        ], ['id' => (int) $debt['id']]);

        Database::insert('debt_payments', [
            'debt_id'      => (int) $debt['id'],
            'amount_minor' => 7_500,
            'currency'     => 'USD',
            'due_on'       => $d(7),
            'status'       => 'scheduled',
            'note'         => 'Demo schedule',
        ]);
    }

    // ── Debt ────────────────────────────────────────────────────────────────
    // The spec's $535 split across two lenders, matching the design. The total
    // is unchanged, this only shows what a multi-lender position looks like.
    $loan = Database::first("SELECT id FROM debts WHERE name = 'Personal loan'");

    if ($loan !== null) {
        $loanId = (int) $loan['id'];
        Database::update('debts', ['principal_minor' => 40000, 'lender' => 'Friend'], ['id' => $loanId]);

        // Repayments already made, so the payoff trend has a slope to show.
        foreach ([[-60, 5000], [-30, 5000]] as [$offset, $amount]) {
            Database::insert('debt_payments', [
                'debt_id'      => $loanId,
                'amount_minor' => $amount,
                'currency'     => 'USD',
                'due_on'       => $d($offset),
                'paid_on'      => $d($offset),
                'status'       => 'paid',
                'note'         => 'Demo repayment',
            ]);
        }

        $creditId = Database::insert('debts', [
            'name'            => 'Equipment credit',
            'lender'          => 'Supplier',
            'principal_minor' => 13500,
            'currency'        => 'USD',
            'interest_note'   => 'No interest, informal',
            'opened_on'       => $d(-120),
            'status'          => 'active',
        ]);

        foreach ([[-75, 2500], [-45, 2500]] as [$offset, $amount]) {
            Database::insert('debt_payments', [
                'debt_id'      => $creditId,
                'amount_minor' => $amount,
                'currency'     => 'USD',
                'due_on'       => $d($offset),
                'paid_on'      => $d($offset),
                'status'       => 'paid',
                'note'         => 'Demo repayment',
            ]);
        }
    }

    // ── Savings ─────────────────────────────────────────────────────────────
    // A modest reserve started and then stalled, the shape this screen exists
    // to make visible.
    $emergency = Database::first("SELECT id FROM savings_goals WHERE kind = 'emergency'");

    $equipmentGoalId = Database::insert('savings_goals', [
        'name'         => 'Equipment upgrade',
        'kind'         => 'goal',
        'target_minor' => 400_000,
        'currency'     => 'UGX',
        'priority'     => 10,
        'note'         => 'After the emergency fund',
    ]);

    if ($emergency !== null) {
        foreach ([[-4, 60_000], [-3, 50_000], [-2, 40_000]] as [$monthsAgo, $amount]) {
            Database::insert('savings_entries', [
                'goal_id'      => (int) $emergency['id'],
                'direction'    => 'in',
                'amount_minor' => $amount,
                'currency'     => 'UGX',
                'occurred_on'  => $today->modify($monthsAgo . ' months')->format('Y-m-20'),
                'note'         => 'Set aside from client payment',
            ]);
        }
    }

    // ── Bills ───────────────────────────────────────────────────────────────
    foreach ([['Rent', 250_000, 10], ['Electricity', 45_000, 4], ['Internet', 90_000, 12]] as [$name, $amount, $due]) {
        Database::insert('bills', [
            'name'         => $name,
            'amount_minor' => $amount,
            'currency'     => 'UGX',
            'due_on'       => $d($due),
            'recurrence'   => 'monthly',
            'is_essential' => 1,
            'status'       => 'due',
        ]);
    }

    // ── Today's priorities ──────────────────────────────────────────────────
    $tasks = [
        ['Contact 20 potential software clients', 1, 180, '5+ replies'],
        ['Finish client website demo',            2, 120, 'Invoice ready'],
        ['Follow up with 5 existing leads',       3, 45,  '1 proposal'],
    ];
    foreach ($tasks as [$title, $rank, $minutes, $outcome]) {
        Database::insert('tasks', [
            'title'             => $title,
            'priority_rank'     => $rank,
            'estimated_minutes' => $minutes,
            'expected_outcome'  => $outcome,
            'for_date'          => $d(0),
            'status'            => 'open',
            'origin'            => 'ai',
        ]);
    }

    // ── Wider task board ────────────────────────────────────────────────────
    // Beyond today's ranked three: overdue work, scheduled work, and a couple
    // already finished, so the board has every state to show.
    $projectId = static fn (string $name): ?int => (($r = Database::first(
        'SELECT id FROM projects WHERE name = :n',
        ['n' => $name]
    )) === null ? null : (int) $r['id']);

    $extraTasks = [
        ['Message 20 potential software clients', 'HIGH',   null,               -2,  'open', null],
        ['Send school website proposal',          'MEDIUM', null,               0,   'open', '45'],
        ['Review FlowPesa payments spike prep',   'LOW',    'FlowPesa',         56,  'open', '90'],
        ['Set up 10 Castalore interviews',        'LOW',    'Castalore',        15,  'open', '120'],
        ['Draft invoice template',                'MEDIUM', 'Client Websites',  -1,  'done', '30'],
        ['Back up project files',                 'LOW',    null,               -3,  'done', '20'],
    ];

    foreach ($extraTasks as [$title, $priority, $project, $offset, $status, $minutes]) {
        $id = Database::insert('tasks', [
            'title'             => $title,
            'project_id'        => $project === null ? null : $projectId($project),
            'priority'          => $priority,
            'estimated_minutes' => $minutes === null ? null : (int) $minutes,
            'for_date'          => $d($offset),
            'due_on'            => $d($offset),
            'status'            => $status,
            'completed_at'      => $status === 'done' ? $dt($offset) : null,
            'origin'            => 'user',
        ]);
    }

    // ── Opportunities ───────────────────────────────────────────────────────
    $opportunities = [
        ['School Website Opportunity', 800_000, 3.0, '3 days',   'Medium', 7,  'Contact from an existing school client asking about a site rebuild.'],
        ['Retail POS Retainer',        300_000, 0.5, '4 h/week', 'Low',    21, 'Monthly support retainer for an existing point-of-sale install.'],
    ];
    foreach ($opportunities as [$title, $value, $effortDays, $effortNote, $probability, $ttc, $summary]) {
        Database::insert('opportunities', [
            'title'                 => $title,
            'summary'               => $summary,
            'potential_value_minor' => $value,
            'currency'              => 'UGX',
            'effort_days'           => $effortDays,
            'effort_note'           => $effortNote,
            'probability'           => $probability,
            'time_to_cash_days'     => $ttc,
            'status'                => 'open',
            'source'                => 'research',
        ]);
    }

    // ── Today's activity log ────────────────────────────────────────────────
    Database::insert('day_logs', [
        'for_date'            => $d(0),
        'prospects_contacted' => 12,
        'followups_made'      => 2,
        'hours_worked'        => 4.5,
    ]);
});

Settings::set('demo_data', '1');
Settings::set('usd_ugx_rate_set_on', $today->format('Y-m-d'));
