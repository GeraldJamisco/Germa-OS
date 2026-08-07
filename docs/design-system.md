# Germa OS design system

**This file is the contract for every Germa OS screen.** It was extracted from
the built Dashboard and Financial Overview, it is not an aspiration, it
describes what `public/assets/css/germa.css` actually implements today.

Read this before building a screen. The point is that the Boardroom, the CFO
view, the Sales CRM and everything after it look like one product, not twenty
separately generated pages.

---

## The rules

1. **A new screen is a content fragment, not a document.** Put it in
   `resources/views/screens/`, render it with `View::render()`. The sidebar, top
   bar, banners, command dock and quick-add modal come from `layout/app.php`.
   Never hand-roll chrome.
2. **Compose from the components below.** If a screen needs something that isn't
   here, add it to `germa.css` as a named component with a comment saying what it
   is for, then use it. Do not solve it with inline styles.
3. **Inline `style` is for one thing only: a computed value**, a bar width, a
   grid column count, a chart height. Anything static belongs in a class.
4. **No new colours.** Every colour is a token in `:root`. If a screen seems to
   need a new one, it almost certainly needs an existing semantic token instead.
5. **Every figure carries provenance.** Use `chip($metric)` or
   `chip_raw($kind, $label, $title)`. A number without a chip is a bug.
6. **Empty is not zero.** No data means `.empty` with a sentence explaining what
   would fill it, never a `0` that looks like a measurement.
7. **No em dashes.** Not in copy, not in comments, not in docs. A comma for a short
   aside, a colon when the second half explains the first, a full stop when both halves
   stand alone. Verify with `grep -rnP '\x{2014}' src resources docs README.md`, which should
   return nothing. A figure that is unavailable reads `n/a`; one that is genuinely nothing
   reads `None`.

---

## Tokens

All defined in `:root` at the top of `germa.css`. Use the variable, never the hex.

### Surfaces

| Token | Use |
|---|---|
| `--canvas` | Page background |
| `--surface` | Card background, sidebar, top bar |
| `--surface-sunk` | Insets *within* a card (stat tiles, breakdown rows) |
| `--sage` | Secondary surface: quiet buttons, tags, insight bands |
| `--sage-hover` | Hover for sage-backed controls |
| `--border` / `--border-strong` | Card borders / hover and dashed borders |
| `--track` | Unfilled portion of any meter |
| `--dark` / `--dark-hover` / `--dark-inset` | The dark card and its interior |

### Ink

`--ink` (headings, figures) → `--ink-body` (prose) → `--ink-muted` (labels) →
`--ink-faint` (eyebrows, disabled). On dark: `--ink-invert` for headings,
`--ink-dim` for body. `--on-accent` is text on a saturated fill (coral, green,
the dark card's buttons): use it instead of `#fff`.

### Coral, the single accent

`--coral-200` … `--coral-800`, plus `--coral-tint` / `--coral-wash` /
`--coral-chip` for backgrounds and `--coral-grad` / `--coral-grad-hover` for
primary buttons.

Coral means **the AI's voice, or urgency**. It is not decoration. A screen that
uses coral for an ordinary bar has said something it did not mean.

### Status

`--signal-good` (+ `-light` / `-wash`), `--signal-warn` (+ `-light` / `-wash` /
`-chip` / `-ink`), `--signal-bad`, `--signal-up`. Neutral data fills:
`--fill-neutral`, `--fill-quiet`, `--border-control`.

### Tone: urgency, applied once

Anything that carries urgency reads four custom properties rather than
restating colours: `--tone-surface`, `--tone-dot`, `--tone-chip-bg`,
`--tone-chip-ink`. A component declares its own neutral defaults, and the
shared `--critical` / `--high` modifiers override them.

```css
.myblock          { --tone-surface: var(--surface-sunk); … ;
                    background: var(--tone-surface); }
.myblock__dot     { background: var(--tone-dot); }
```

Then add `.myblock--critical, .myblock--high` to the shared modifier block
which **must come after every component that uses it**, since the selectors are
equal specificity and source order decides. `.alert` and `.duerow` both work
this way.

> **There are no raw hex values outside `:root`.** Verify with
> `grep -n '#[0-9a-fA-F]' public/assets/css/germa.css`, every hit should be
> inside the token block. A colour that isn't a token is a colour no other
> screen can reuse.

> **Rule 3 is checkable too.** Count static inline styles with
> `grep -ho 'style="[^"]*"' resources/views/screens/*.php | grep -vc '<?='`.
> Every screen from Financial Overview onwards reports **0**.
> `dashboard.php` reports **46** and `partials/quick-add-modal.php` **1**: both
> predate this contract, which was extracted from the dashboard rather than
> applied to it. Most map onto utilities that now exist (`.push-right`,
> `.self-start`, `.stack`, `.spread`), it is recorded debt, not licence. Do not
> copy the pattern into a new screen.

### Type

| Token | Family | Use |
|---|---|---|
| `--font-sans` | Inter | Everything by default |
| `--font-display` | DM Sans | Headings and figures only |
| `--font-mono` | Fragment Mono | Eyebrows, chips, badges: never body text |

Scale: `.t-display` (page/hero titles), `.t-figure` + `--xl/--lg/--md/--sm/--hero`
(numbers), `.t-card-title` (14px/600, every card head), `.t-body`, `.t-label`,
`.t-note`, `.t-eyebrow`.

### Shape and elevation

`--r-card` 24px (cards) · `--r-inner` 18px (rows inside cards) · `--r-tile` 16px
(small tiles) · `--r-pill` (buttons, chips, nav). Shadows: `--shadow-btn`,
`--shadow-dock`. Nothing else casts a shadow.

---

## Components

### Layout

| Class | What it is |
|---|---|
| `.shell` | Sidebar + main grid. Layout only. |
| `.page` | Content column: 24/28px padding, 20px gap |
| `.row` | Auto-fit grid, 280px min, the default card row |
| `.row--wide` | Auto-fit grid, 340px min, for two-up cards |
| `.stack` / `--tight` / `--loose` | Vertical flex, 12 / 7 / 18px gap |
| `.inline`, `.spread`, `.push-right` | Small flex utilities |

### Cards

```html
<div class="card">
  <div class="card__head">
    <span class="t-card-title">Title</span>
    <?= chip($metric) ?>
  </div>
  …
  <button class="btn card__foot">Action</button>
</div>
```

`.card--dark` inverts it. Reserve the dark card for **the one figure the screen
exists to communicate**, one per screen at most.

### Provenance chips

`.chip` + `.chip--entered` / `--calculated` / `--estimated` / `--ai` /
`--unavailable`. Status variants: `.chip--status` + `--critical` / `--tight` /
`--stable`. On dark: `.chip--on-dark`.

### Buttons

`.btn` (default, sage) · `.btn--primary` (coral gradient, one per view) ·
`.btn--quiet` (bordered, for grids) · `.btn--dark` · `.btn--link` · `.btn--sm`.

### Data display

| Class | What it is |
|---|---|
| `.metric` / `.metrics` | Headline figure + provenance + comparison line. The summary row. |
| `.metrics--lead` | Summary row led by one emphasised card, then supporting figures |
| `.bar` / `.bar--thick` + `.bar__fill` / `--coral` / `--quiet` | Any proportion |
| `.meter` | Labelled bar with a value on the right |
| `.stat` / `.stat__grid` | Small figure tiles inside a card |
| `.chart` / `.chart__col` / `.chart__pair` / `.legend` | Column chart |
| `.chart__bar--in` / `--out` | Two-series bars (income vs expenses) |
| `.chart--single` | One wider bar per column; the in-progress period is marked coral |
| `.cardstats` / `.cardstats__item` / `--good` / `--bad` | Ruled figure strip under a chart or list |
| `.breakdown` | Name + amount + share bar + optional class tag |
| `.breakdown--ranked` / `.breakdown__status` | Taller rows with a qualifying line |
| `.txn` | Transaction row: tile, name/meta, amount |
| `.tile` + `--in` / `--debt` / `--rank` / `--lead` | The leading square on any list row |
| `.duerow` | A single-line obligation: dot, title, when, amount |
| `.actionrow` / `.actions` / `--quiet` | A directive with a cost: dot, what to do, how long |
| `.agenda__row` + `.datetile` | Dated commitment: month/day square, body, amount, tag |
| `.strip` / `.strip__day` | Week or month at a glance; a dot marks a day with something on it |
| `.donot` | The one thing not to do: lives inside the dark brief card |
| `.reviewgrid` | Auto-fit field grid for a short form |
| `.deflist` / `.defrow` | Label-value supporting facts |
| `.prio` + `--high` / `--good` / `--warn` / `--quiet` | Small status pill: task priority, deal stage, client status, project verdict |
| `.statgrid` / `.statcard` (+ `--lead`) / `.statcard__well` / `.statcard__col` / `.statcard__delta` | Grid of tinted single-figure cards. Horizontal `.bar` inside = a share; the vertical well = comparing counts |
| `.minutes` / `.minute` / `.minute__q` / `.minute__a` | The record of a question put to the board and what came back |
| `.panel` | A sunk panel inside a card, for one card holding several self-contained items. `.opp` is this plus its ranking |
| `.notif` / `.notif__panel` / `.notif__item` (+ `--critical` / `--high`) | The notification bell's popover, tinted from the shared `--tone-*` properties |
| `.iconbtn__glyph` | An inline SVG icon inside an icon button, drawn in `currentColor` |
| `.actionrow--button` | An action row that is itself the control, so the whole question is the target |
| `.field__textarea` | Multi-line input, for a situation that needs a paragraph |
| `button.signal` | The advisor tile as a control: keeps every `.signal` look, sheds the browser's button chrome |
| `.project__pct` / `.project__note` | The percentage and reason columns of a `.project` row |
| `.tag` / `.tag--class` + `--fixed` / `--variable` / `--flag` | Classification |
| `.empty` | Dashed empty state |

### Page furniture

`.pagehead` (eyebrow + title + one control) · `.segmented` (mutually exclusive
views of the same data: **not** navigation) · `.insight` (a sentence about the
screen you're on) · `.banner` (app-level notice) · `.toast` · `.modal`.

---

## Screen recipe

```php
// index.php
if ($path === 'thing') {
    $data = (new ThingService())->build();

    View::render('screens/thing', ['data' => $data, 'token' => $token], [
        'title'         => 'Thing',
        'route'         => 'thing',                       // lights the sidebar
        'topbar_action' => ['label' => '+ Add', 'quick_add' => 'income'],
        'suggestions'   => $data['suggestions'],           // command dock
    ]);
    exit;
}
```

```php
// resources/views/screens/thing.php : fragment, no <html>
<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Group</span>
    <h1 class="t-display pagehead__h1">Thing</h1>
  </div>
</div>

<section class="metrics">…</section>
<section class="row row--wide">…</section>
```

Add the route to `ChromeService::GROUPS` so the sidebar links to it.

---

## Responsive

Breakpoints are `1080px` (sidebar becomes a drawer) and `720px` (single column).
Grids use `auto-fit` + `minmax`, so most screens need no media query at all.

The dashboard orders its cards for mobile with `.o-1` … `.o-11` on a
`display: contents` row: use that pattern only when a screen's mobile priority
genuinely differs from its desktop order.

---

## What "same product" means in practice

The curve to watch, screen by screen:

| Screen | Components added | Components reused |
|---|---|---|
| Dashboard | the initial set |: |
| Financial Overview | 8: `.pagehead`, `.segmented`, `.insight`, `.metric`, `.chart`, `.breakdown`, `.txn`, `.deflist` | `.card`, `.chip`, `.bar`, `.btn`, `.row`, `.tag`, `.empty`, the type scale |
| Income | 4: `.chart--single`, `.cardstats`, `.metrics--lead`, `.breakdown__status` | everything else |
| Expenses | 1: `.duerow` | everything else |
| Debt | **0** | everything |
| Savings | **0** | everything |
| Today / Tasks / Calendar | 5 across all three: `.donot`, `.actionrow`, `.agenda__row`, `.strip`, `.reviewgrid` | everything else, including `.priority` from the dashboard |
| Pipeline / Clients / Opportunities / Revenue | **0**, three `.prio` variants only | everything, including `.project`, `.funnel` and `.opp` from the dashboard |
| Active Projects / Project Portfolio | **1 family**: `.statcard` + two `.project` columns | `.project`, `.project__meter`, `.bar`, `.prio`, `.cardstats`, `.segmented`, `.insight`, `.card--dark`, `.board__quote`, `.donot` tokens |
| AI Boardroom | **4 small ones**: `.minute`, `.actionrow--button`, `.field__textarea`, `button.signal` | `.card--dark`, `.board__quote`, `.board__signals`, `.signal`, `.insight`, `.duerow`, `.deflist`, `.prio`, `.chip--on-dark` |
| Decisions / Insights / Research | **1**: `.panel`, extracted from the existing `.opp` | `.duerow`, `.alert`, `.metrics`, `.segmented`, `.insight`, `.card--dark`, `.board__quote`, `.signal`, `.chart--single`, `.cardstats`, `.deflist`, `.actionrow`, `.minute`, `.prio` |
| Goals / Milestones / Household / Shared Goals | **0** | `.card--dark`, `.board__quote`, `.board__signals`, `.signal`, `.panel`, `.duerow`, `.meter`, `.bar--thick`, `.breakdown`, `.deflist`, `.actionrow`, `.minute`, `.metrics`, `.segmented`, `.prio` |
| Reports | **0** | `.card--dark`, `.board__quote`, `.board__signals`, `.signal`, `.panel`, `.opp`, `.statgrid`, `.statcard`, `.duerow`, `.meter`, `.deflist`, `.segmented`, `.insight`, `.prio` |
| Settings | **0** | `.panel`, `.donot`, `.duerow`, `.deflist`, `.card--dark`, `.prio`, `.insight` |
| AI Configuration | **1**: `.prompt__text`, a monospace read-only treatment for the system prompt | `.card--dark`, `.board__quote`, `.board__signals`, `.signal`, `.donot`, `.panel`, `.duerow`, `.minute`, `.cardstats`, `.deflist`, `.actionrow`, `.insight`, `.prio` |

Additions should fall as the system matures; reuse should climb. Debt and
Savings are the proof: both were built without adding a single component, rule
or colour: page header, segmented control, insight band, lead metric row, dark
card, single-series chart, ruled stats, ranked breakdown and transaction list
covered every element between them.

That is what the contract is for. A new screen should now be a service and a
fragment, not a design exercise.

Each screen also **removed** duplication, which is part of building a screen
rather than a separate cleanup task:

- **Income** extracted the 34px leading square from `.txn__icon` into a shared
  `.tile`, and turned a leftover raw hex into `--signal-good-wash`.
- **Expenses** extracted severity tinting out of `.alert` into the shared
  `--tone-*` properties, so an overdue bill and a critical alert are tinted by
  one rule instead of two diverging ones.
- **Debt / Savings** generalised the shell's embedded reference lists so any
  quick-add form can offer a picker (`projectOptions()`, `savingsGoalOptions()`)
  without each one fetching its own.
- **Calendar** extended the shared `--tone-*` modifier block to `.agenda__row`
  rather than giving the agenda its own severity colours, three components now
  read urgency from one rule.
- **Active Projects / Project Portfolio** extracted `Support\Period` (the week/month/year window, which
  had begun growing a third copy inside `RevenueService`) and `Support\Hours` (one hour formatter, which
  immediately caught the dashboard rendering `18.0h` where these screens render `18h`). They also
  tokenised the last static inline style in a `.project` row, `width:32px`, into `.project__pct`.
- **AI Boardroom** put the advisor roster *inside* the dark card rather than beside it, so the existing
  `.signal` carried it with no light-surface variant. It extracted `Clock::relative()` for sub-day
  timestamps and `DriverException::readable()`, which is what stopped the minutes printing raw API JSON.
- **Decisions / Insights / Research** extracted `.panel` from `.opp`, so the sunk sub-panel shape is one
  declaration shared by four screens rather than a fresh copy per screen. The notification bell replaced a
  `data-scroll-to="alerts"` button that only worked on the dashboard, and its severity tinting joined the
  shared `--tone-*` block instead of introducing a fourth set of severity colours.
- **Goals / Milestones / Household / Shared Goals** added nothing. The mission ladder reuses the boardroom's
  `.signal` tiles inside a `.card--dark`, and the four screens' meters, rows and panels all already existed.
  They made `RevenueService::recurring()` and `SavingsService::totalSaved()` public rather than growing second
  copies of the three-month recurrence rule and the savings balance.
- **Reports** added nothing either. It also generalised the top-bar action: it now accepts `href` alongside
  `quick_add` and `focus`, because the end-of-day review is a form on Today rather than a modal and pointing a
  `quick_add` at it would have rendered a third silently dead button.
- **Settings** added nothing. It reuses `.donot` for the self-contradiction notices, which is the first use of that
  component outside a daily brief and needed no change to work there.
- **AI Configuration** added one class for the read-only prompt and nothing else. Every nav destination now has a
  screen, which makes `screens/not-built-yet.php` unreachable: it stays in the tree as the 404 fallback for a
  hand-typed path, not as a placeholder for missing work.

A screen that adds more than it reuses, or that introduces a colour, is a
redesign: question it before building it.
