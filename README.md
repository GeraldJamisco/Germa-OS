# Germa OS backend

## First run and setup

A fresh install is deliberately empty: it ships the OS's own default thresholds
and nothing about you. On first sign-in the app detects this and walks you
through a short setup that collects the essentials it cannot function without,
in the order the decision hierarchy cares about them:

1. **You** — name, and where you operate.
2. **Survival** — what a month costs to run. Everything measures against this.
3. **Cash** — what is in the bank now. Runway is this divided by survival.
4. **Debt** — anything outstanding, or skip.
5. **Projects** — the work you are actually doing. At least one is **required**,
   because allocation, revenue attribution and the at-risk logic all read from
   projects and have nothing to say without them. Add as many as you like.

Everything else (clients, deals, goals, pipeline, and so on) is added later
through the app itself. Setup finishes by setting `onboarding_complete`, after which the OS
opens normally.

Commands:

```
php bin/migrate.php --fresh          # reset to an empty install (setup will run)
php bin/migrate.php --fresh --demo   # a fully populated illustrative dataset (skips setup)
php bin/set-password.php             # set the sign-in password before going live
```



Personal executive intelligence and household operating system: the data model,
the rules that turn recorded facts into decisions, the Claude integration, and
the screens built so far.

Spec: `GERMA OS.MD`. UI: the Germa OS design project.

**Building a new screen? Read `docs/design-system.md` first.** It is the contract
that keeps every screen looking like one product.

---

## Running it

Requirements: PHP 8.1+, MySQL/MariaDB, Composer. Both ship with XAMPP.

```sh
composer install
php bin/migrate.php --fresh --demo    # schema + baseline + demo dataset
```

Then open <http://localhost/Germa%20OS/>.

Drop `--demo` to start from the real baseline only: cash 0, the $535 loan, the
UGX 580,000 survival requirement, the four projects, the four goals. Nothing
else is invented the demo dataset exists so the screen can be seen populated,
and it sets a `demo_data` flag that renders a visible banner.

| Command                       | What it does                                    |
| ----------------------------- | ----------------------------------------------- |
| `php bin/migrate.php`         | Create/patch the schema, load the baseline seed |
| `php bin/migrate.php --demo`  | ...and the demo dataset                         |
| `php bin/migrate.php --fresh` | Drop the database first                         |

### Configuration

`config/config.php` is committed and holds no secrets. Copy
`config/config.local.example.php` to `config/config.local.php` for the database
credentials and the Anthropic API key that file is gitignored.

```php
'ai' => [
    'driver'  => 'claude',   // or 'rules' for no network calls at all
    'api_key' => 'sk-ant-...',
],
```

---

## How it is put together

```
index.php               Front controller: page routes + JSON API
bootstrap.php           Autoloader, config, timezone
config/                 Base config + gitignored local overrides
database/               schema.sql, seed.sql, seed_demo.php
docs/design-system.md   The UI contract every screen must follow
src/Support/            Money, Metric, Provenance, Clock, Settings, Database
src/Service/            The domain: finance, debt, pipeline, projects, goals,
                        tasks, alerts, score, mode, opportunities, board;
                        plus ChromeService (the shared shell) and one
                        service per screen
src/Ai/                 Driver interface + Claude and rules implementations
src/Http/               JSON responses, CSRF, View
resources/views/
  layout/app.php        The shell: sidebar, top bar, banners, dock
  layout/dock.php       The command dock
  screens/              One fragment per screen
  partials/             Shared fragments (quick-add modal)
public/assets/          Design system CSS and the app's JS
```

### Screens

Each screen is a **content fragment** rendered inside one shared shell by
`View::render()`. The sidebar, top bar, banners, command dock and quick-add modal
are written once in `layout/app.php`, so the chrome is identical everywhere by
construction. `ChromeService` owns the navigation and its live badge counts
adding a screen means giving its route a destination there, not rebuilding a menu.

| Route | Screen |
|---|---|
| `/` | Dashboard: today's command center |
| `/finance` | Financial Overview: period money view (`?period=week\|month\|year`) |
| `/income` | Income: target, six-month trend, sources ranked by contribution |
| `/expenses` | Expenses: budget, categories by share, upcoming bills |
| `/debt` | Debt: balance over time, obligations by lender, payment history |
| `/savings` | Savings: reserve against three months of survival costs |
| `/today` | Today, the spec's Daily Command Brief and end-of-day review |
| `/tasks` | Tasks: everything outstanding (`?filter=overdue\|today\|upcoming\|done`) |
| `/calendar` | Calendar: commitments derived from bills, debts, tasks and milestones |
| `/pipeline` | Sales Pipeline: funnel, conversion, deals ranked by time-to-cash |
| `/clients` | Clients, who has paid, who has gone quiet |
| `/opportunities` | Opportunities, the spec's DO / CONSIDER / IGNORE classification |
| `/revenue` | Revenue: concentration and what actually repeats |
| `/projects` | Active Projects: what is in flight, and what each hour bought |
| `/portfolio` | Project Portfolio: hours by classification, and the keep/hold/pivot/kill verdict |
| `/boardroom` | AI Boardroom: the board's position, who dissented, the minutes, and decisions due review |
| `/decisions` | Decisions, the journal, and which calls cannot be graded |
| `/insights` | Insights, open alerts plus patterns established from your own records |
| `/research` | Research, what you believe, how you know it, and what has gone out of date |
| `/goals` | Goals, against the rung of the mission ladder you are actually on |
| `/milestones` | Milestones, one timeline across goals and projects |
| `/household` | Household Overview, cover, workload and what is owed to people |
| `/shared-goals` | Shared Goals, and which of them money is actually moving toward |
| `/reports` | Reports, the weekly board meeting, the monthly review, and whether the numbers can be trusted |
| `/settings` | Settings, every threshold with what it decides and what it is deciding now |
| `/ai-config` | AI Configuration, whether the model is actually answering and what it is told |

Every other sidebar destination routes and has its data flowing through the
backend, but renders a placeholder.

### Money

Every amount is a `BIGINT` of minor units plus an explicit currency. UGX has no
minor unit (0 decimals), USD has 2. `Money` refuses to add two currencies
without an explicit conversion, and conversions are always labelled as
estimates because the USD rate is a manually maintained setting.

### Provenance

The UI brief is explicit that an entered fact, a derived figure and model output
must never look alike. Services return `Metric` objects carrying a `Provenance`
,  `entered`, `calculated`, `ai`, `estimated`, `unavailable` and the view
renders a differently-styled chip for each. Missing data renders as an empty
state, never as a zero.

### Available cash

The headline number is anchored to the last balance actually observed, plus
every ledger entry recorded after it. The boundary is a **ledger id**, not a
timestamp, so a clock change can never silently drop entries out of the balance.
The database session's timezone is pinned to the app's on connect for the same
reason.

### Configured is not the same as working

The AI Configuration screen is built around the most expensive bug in this project.
For a fortnight the AI layer looked correctly configured: a key was set, the driver
said `claude`, and screens carried chips claiming their text was reasoned. Not one
call had ever succeeded. Every request was rejected because the configured model
does not accept the `fallbacks` parameter, the rejection was caught, and the rules
engine answered instead. Nothing anywhere said so.

So reachability is reported as four separate facts in ascending order of what they
prove: a driver chosen, a key present, an SDK installed, and **a live call has
succeeded**. The first three are intentions. Only the fourth is evidence, and the
screen says exactly that. A **Test the connection** button makes one real call and
logs it like any other, so the panel it exists to satisfy actually moves.

Two refusals. The key is never rendered, not partially, not in error output: the
screen reports its length and which file it came from, and checks that the committed
config holds only a placeholder. And nothing on the screen writes configuration,
because a form that could rewrite `ai.*` could also quietly strip the accountability
rules out of the system prompt. The prompt is shown verbatim and read-only for that
reason, plus a mechanical one: it is byte-stable so it can carry a cache breakpoint.

The screen also lists what the layer **guarantees**, each with the mechanism rather
than the reassurance: rules output is never labelled as reasoned, a page render
never waits on a model, a failed call degrades rather than breaks, the model never
invents a figure. Those are the only reason a provenance chip is worth reading.

### Settings are thresholds, so they show what they are deciding

Nothing on the Settings screen is a preference. Six days of silence is what makes a
lead "gone quiet"; ten hours a fortnight is what makes a project "at risk"; seven
days of runway is what puts the whole system into emergency mode. A page that lists
those as bare numbers hides it, so every row states what it decides and what it is
deciding **right now**: *"Breached: 0 days of runway is below this"*, *"Active on
FlowPesa, which has taken 18h in the last fortnight with nothing back"*.

Values are validated against each other, not only on their own terms. The tight
runway threshold must sit above the critical one, because a warning band below the
emergency band can never warn. The household requirement cannot be zero, because
every runway figure divides by it.

The screen also names what is **deliberately not configurable**, and why. The
emergency reserve target is derived from the survival cost so it cannot go stale.
Recurring revenue is three complete months of repetition because repetition is the
only evidence. The sustainable-week ceiling is not editable, because the point of
the household screen is to notice a pace, not to be reconfigured until it approves
of one.

The exchange rate gets its own card, treated as what it is: a manually maintained
assumption with an age. Confirming it records the date, which is the only reason the
OS can ever tell you it has gone stale, and the card names the foreign-currency debt
that depends on it.

### Reports grade their own inputs first

The spec defines four reporting cycles. Two had homes already, the Daily Command
Brief and the end-of-day review. Reports carries the other two: the **weekly board
meeting** (nine role reports, each derived, plus next week's three priorities) and
the **monthly review** (thirteen measures, then seven verdicts read off those
measures so they cannot contradict the table).

Above both sits the report nothing else in the OS produces: **can you trust these
numbers.** Every figure in this system is derived from what was logged. Stop
logging for ten days and every screen keeps rendering with total confidence and a
runway that is quietly fiction. So each input is graded on when it was last fed,
and the verdict is blunt: *current*, *behind*, or *do not rely on it*. On the demo
data it immediately finds that no day has ever been closed out, which means the
scores elsewhere are computed over a subset and read better than reality.

Two more reports the spec does not ask for. **Said versus did** puts commitments
next to delivery; both halves were already recorded and nothing had ever compared
them. **The cost of waiting** prices inaction, because the expensive decisions are
usually the ones nobody made. And **the OS, audited** turns the Critic on the
system itself: how many answers came from the rules engine rather than a model,
how many alerts were dismissed rather than resolved, how many decisions have ever
been graded.

Every measure states the window it was taken over. Money received this month
sitting beside money that repeats across earlier months reads as a contradiction
unless both say so.

### Goals are sequenced against a ladder, not a wish list

The mission in the spec is an ordered progression: financial pressure, stability,
debt reduction, reliable income, business growth, asset creation, long-term
wealth. Goals derives which rung the numbers put you on by reading the tests from
the bottom up and stopping at the first failure, then marks business goals sitting
above it. Those are not ambitious, they are out of sequence.

Household and shared goals are exempt from that sequencing. The same instruction
that defers an unvalidated startup idea is the one that protects the household, so
the ladder never tells Gerald his family obligations are ahead of his position.

A goal with no milestone is flagged too. The Operations Director is told never to
leave only abstract advice, so a goal without a next step is named as a wish with
a progress bar.

### The household screen asks what nothing else can

Cover is stated in months rather than shillings, because a figure means nothing
without the monthly requirement beside it. Workload and rest come from logged
hours: how many of the last seven days were worked, and how long since a full day
off, which the OS derives from the *absence* of a record. Commitments to people
carry a cadence, so "overdue" means it was meant to happen every N days and it has
been longer than that. A weekly family day that quietly became fortnightly is debt
no financial screen will ever show.

Shared Goals separates goals money is actually moving toward from goals that
reached a healthy-looking percentage a long time ago and have had nothing since.
**Funded** means a deposit in the last six weeks, not that the total looks good.

### A decision is only real if it can be graded

The spec's DECISION FRAMEWORK ends with a next action and a metric. Decisions
therefore counts the ones that have neither and says so: a live decision with no
metric and no review date has no future moment at which it can be shown to have
been wrong. Reversals are shown rather than hidden, because a record with no
reversals in it usually means nobody checked.

### Patterns state their sample, or are not claimed

Insights separates what is wrong now (alerts) from what keeps being wrong
(patterns). Every pattern carries the sample it was drawn from, and anything
below the threshold appears under **Not yet knowable** along with what is still
missing. A pattern claimed from three data points is a coincidence with a
confident voice.

### Research expires on purpose

The spec requires every external claim to be labelled FACT, ESTIMATE or
ASSUMPTION, and says not to assume old information is still accurate. So a note
carries the date it was checked and a shelf life, shorter for an assumption than
for a verified fact, and it moves to the top of the screen to be re-checked when
that runs out. A claim with no source cannot be saved as a fact.

### The boardroom shows who dissented

The spec insists Gerald gets one answer, not ten, so the dashboard shows only the
board's position. The Boardroom is where that position becomes accountable: the
advisors are grouped into **raising a concern** and **no objection**, because the
Risk Director and the Critic have standing permission to say no and a flat list
would average that away. Each advisor is a button, so a question can go straight
to whoever objected.

Every question ever asked is already logged in `commands`. The **Minutes** show
the prompt, the answer, which engine replied and how long it took, so a rules
answer cannot pass for a reasoned one. **Due for Review** reads the `decisions`
journal, because a decision never looked at again is indistinguishable from a
guess.

Nothing on the screen claims a model spoke when it did not. With no key set it
says so; with a key set but the day's text still the rules stand-in, it says that
too and upgrades itself in the background.

### Project revenue is never stored

A project's revenue is summed from ledger receipts attributed to it, and only in
the project's own currency. There is no `revenue` column, one existed, and it
had already drifted 1.6M away from the ledger in the demo data. Attribute a
receipt to a project and it counts; that is the only way it counts.

Hours are the other currency. `project_time_logs` drives the allocation split,
the at-risk rule, and the Critic's standing objection that building feels like
progress while only selling produces money. A project is **at risk** when it has
consumed more than the `non_revenue_hours_cap` in 14 days, returned nothing, and
has no milestone inside 30 days, a distant milestone is not a defence.

Every project carries one of the spec's four verdicts (CONTINUE / PAUSE / PIVOT
/ KILL). A fifth state, **REASSESS**, is never stored: it is what a verdict looks
like once its review date has passed or the evidence has moved under it. A
verdict recorded in the last fortnight is exempt, because that call was made with
the current evidence in view. KILL archives the project, a verdict that changes
nothing is not a decision.

### Sales is ranked by time-to-cash

The spec says sales takes priority over product work when money is urgent, and
ranks opportunities by time-to-cash, probability and expected value together.
So the pipeline sorts live deals by how soon they could pay rather than by size,
and an uncontacted prospect sorts below a live conversation because it has no
honest close date to claim.

Recurring revenue is **derived, not flagged**: a source counts as recurring only
if money arrived in each of the last three complete months, and the figure used
is the smallest of the three rather than the average, that is the part you can
actually depend on.

### The daily cycle

`/today` is the spec's DAILY COMMAND BRIEF rendered literally: financial
position, income target, top three priorities, highest-value action, sales
actions, build actions, risks, and the one thing NOT to do. The AI supplies the
narrative and the do-not line (a structured field, not prose); everything else
is derived, because an action list has to be checkable against the record.

Submitting the end-of-day review records the day and returns the four scores.
Money entered there goes through the ledger, so the review and the runway can
never disagree.

### The calendar is derived, not a diary

Every date that matters already exists somewhere: bills, scheduled debt
payments, task deadlines, project milestones and keep-or-kill reviews. The
calendar reads those rather than adding an events table that could drift out of
step with the ledger.

### Savings sit outside the ledger

A deposit moves money Gerald has already counted into a pot he does not spend
from. It is neither income nor expenditure, so `savings_entries` are tracked
separately and do **not** write ledger rows: available cash stays anchored to
the balance he can actually reach, which already excludes what he has set aside.

The emergency fund's target is derived from the survival cost (three months by
default) rather than stored, so it follows the household instead of going stale.

### Modes

`normal` / `emergency` / `opportunity`. The OS moves itself into emergency when
runway drops below the configured threshold and back out only with clear
daylight, so it cannot flap on a single payment. The mode changes the score
weights (selling matters more when cash is short) and the command-dock
suggestions.

### The score

Four components financial discipline, sales, execution, strategic focus
each computed from recorded data, each producing a sentence explaining every
deduction. That is what the "Why this score?" link shows. Weights vary by mode.

### Alerts

Eight rules, each a pure function of recorded data returning either nothing or
one alert with a severity, an explanation and a recommended action. A rule that
stops firing resolves itself, so the card only ever shows live problems.

### AI

Two drivers behind one interface:

- **`claude`** the official Anthropic PHP SDK against `claude-opus-5`, with
  adaptive thinking, structured outputs for the board brief and the daily
  priorities, a cached byte-stable system prompt, and server-side refusal
  fallbacks.
- **`rules`** deterministic, offline, no key. Assembles the same artefacts
  from figures already in the database.

When the Claude driver fails for any reason the rules driver answers instead and
the row records which one did the dashboard chip reports what actually
produced the text, never what is merely configured.

**Model calls never block a page render.** The daily brief, the board
recommendation and the day's three priorities are generated once per day and
stored. On the first load of a day the page paints in under a second with rules
output, then the client upgrades it in the background via `POST /api/ai/refresh`.
A full regeneration is three model calls and takes roughly a minute.

---

## API

All POST endpoints require the CSRF token (`X-CSRF-Token` header or `_token`
field). The page exposes it as `document.body.dataset.token`.

| Method | Route                      | Purpose                                                                                |
| ------ | -------------------------- | -------------------------------------------------------------------------------------- |
| `GET`  | `/api/dashboard`           | The whole payload as JSON                                                              |
| `POST` | `/api/tasks/{id}/toggle`   | Complete/reopen a priority; returns the new score                                      |
| `POST` | `/api/alerts/{id}/dismiss` | Dismiss an alert                                                                       |
| `POST` | `/api/entries/{type}`      | `income`, `expense`, `debt`, `debt-payment`, `bill`, `pay-bill`, `savings`, `savings-goal`, `day-review`, `deal`, `opportunity`, `project`, `project-hours`, `project-decision`, `decision`, `decision-review`, `research`, `goal`, `goal-milestone`, `milestone-done`, `commitment`, `commitment-done`, `setting`, `task`, `prospect`, `project`, `cash`, `outreach` |
| `POST` | `/api/command`             | A natural-language question for the board                                              |
| `POST` | `/api/ai/refresh`          | Regenerate today's brief, board recommendation and priorities                          |

---

## What is not built

The Dashboard and the Financial Overview are the screens that exist. The weekly
board meeting, the monthly review, the end-of-day review and the decision
register have tables and services behind them but no interface yet.
