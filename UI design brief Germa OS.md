# GERALD OS DASHBOARD UI DESIGN BRIEF

## Charm-Based Design System

Design the primary dashboard for:

# GERALD OS

### Personal Executive Intelligence & Household Operating System

GERALD OS is a personal AI operating system that helps Gerald and his household manage money, increase income, manage debt, execute priorities, build businesses, and make better decisions.

The dashboard is the user's **daily command center**.

However, the visual language must NOT feel like a cold corporate finance terminal, traditional banking application, military command center, or overly technical developer dashboard.

Instead, build GERALD OS using the **Charm design philosophy**:

> Warm light surfaces, vivid coral actions, cream accent bands, pill-shaped controls, softly rounded panels, approachable intelligence, and confident simplicity.

The product should feel:

**Human + Intelligent + Calm + Premium + Practical + Trustworthy**

It should feel like an AI system that is working **with** Gerald rather than monitoring him.

---

# 1. DESIGN SYSTEM FOUNDATION

The entire GERALD OS application should be built around the Charm visual language.

Do not treat Charm as inspiration only.

Treat these tokens, shapes, typography, spacing rules, surface treatments, and interaction patterns as the foundational design system for:

- dashboard
- financial screens
- AI Boardroom
- tasks
- sales CRM
- projects
- goals
- reports
- settings
- forms
- modals
- tables
- notifications
- onboarding
- mobile screens

Everything should feel like one coherent product.

---

# 2. COLOR SYSTEM

Use the following design tokens as the base palette.

## Section Surface

`#F7F7F5`

Purpose:

Primary warm matte application canvas.

Use this as the dominant page background.

---

## Panel

`#FBFAF9`

Purpose:

Cards, widgets, raised content areas, inputs, and major content surfaces.

Panels should feel slightly lifted from the main canvas through subtle tone differences and borders rather than heavy shadows.

---

## Accent Band

`#F1F2EA`

Purpose:

Hero areas, important dashboard summaries, highlighted sections, empty states, and special contextual bands.

Use sparingly.

---

## Brand Coral

`#E4544B`

Purpose:

Primary actions, important CTAs, active controls, selected states, AI emphasis, and brand identity.

Coral should be intentional.

Do not turn the entire dashboard coral.

---

## Brand Text

`#C9443A`

Purpose:

Accessible links, textual brand accents, inline emphasis, and supporting coral typography.

---

## Heading

`#1C1917`

Purpose:

Primary headings and high-emphasis text.

---

## Body

`#57534E`

Purpose:

Primary readable content.

---

## Muted Body

`#79716B`

Purpose:

Metadata, secondary descriptions, timestamps, supporting information, and less important labels.

---

## Border

`#E7E6E5`

Purpose:

Subtle component boundaries, card borders, dividers, input borders, and structural separation.

---

# 3. STATUS COLORS

Status colors must remain semantic.

Do NOT use bright colors simply for decoration.

Use appropriate status colors only for:

- success
- warning
- danger
- information

For example:

A financial emergency may use a restrained danger treatment.

A completed goal may use a success treatment.

A warning about an upcoming payment may use warning.

Status colors should remain visually subordinate to the Charm brand coral.

---

# 4. TYPOGRAPHY

Use:

### Body / UI

**Inter**

For:

- navigation
- body text
- controls
- buttons
- cards
- labels
- tables
- forms

### Headings

**Circular**

If Circular is unavailable, use:

**DM Sans**

Headings should be:

- bold
- confident
- friendly
- relatively tight in tracking
- visually clear without feeling aggressive

### Technical / Eyebrow / Metadata

**Fragment Mono**

Use for:

- uppercase eyebrows
- tiny system labels
- ticker-like information
- timestamps
- technical metadata
- AI status indicators
- system-generated labels

Do NOT overuse monospace typography.

---

# 5. SHAPE LANGUAGE

The shape language is one of the defining characteristics of GERALD OS.

Use:

### 24px rounded corners

For:

- cards
- dashboard panels
- widgets
- modals
- tables
- drawers
- major containers

### 12px corners

For:

- dropdown menus
- contextual menus
- small popovers

### Pills

Use pill-shaped controls for:

- primary buttons
- secondary buttons
- filters
- tags
- status badges
- alerts
- segmented controls
- compact actions
- AI modes

### 4px corners

For:

- checkbox controls

Inputs and textareas should retain the soft panel language.

Avoid sharp rectangular cards.

Avoid excessive circles.

Avoid excessive rounding where everything looks like a bubble.

The interface should have a deliberate combination of:

**soft 24px panels + pill controls + restrained smaller controls.**

---

# 6. SPACING

Use a 4px spacing scale.

Base spacing:

4
8
12
16
20
24
32
40
48
64
80

Application content should sit inside a centered container.

Maximum content width:

**1280px**

Use generous page-level spacing.

Within cards, use tighter spacing for related information.

The interface should feel spacious without wasting screen real estate.

---

# 7. DEPTH AND SURFACE

Avoid heavy shadows.

GERALD OS should derive depth from:

1. warm surface changes
2. subtle borders
3. spacing
4. panel layering
5. restrained shadows

Cards should generally use:

Panel background + subtle border.

Buttons may receive a subtle control lift.

Menus and popovers may receive a medium floating shadow.

A subtle coral glow may be used only when a genuinely important AI or brand interaction is emphasized.

Do NOT apply glowing effects everywhere.

---

# 8. OVERALL DASHBOARD FEEL

The dashboard should feel like:

**A warm personal operating system**

rather than:

**A corporate enterprise dashboard.**

Imagine opening the application every morning and feeling:

> “Okay. I understand what's happening. I know what matters. I know what I should do.”

The visual design should reduce anxiety rather than increase it.

Even when the user's financial position is poor, the UI should communicate the truth calmly.

---

# 9. GLOBAL APPLICATION STRUCTURE

Design a persistent application shell.

## LEFT SIDEBAR

Logo:

**GERALD OS**

Under the logo, a small system state:

`● OS ACTIVE`

Navigation:

### COMMAND

Dashboard

### MONEY

Financial Overview
Income
Expenses
Debt
Savings

### EXECUTION

Today
Tasks
Calendar

### BUSINESS

Sales Pipeline
Clients
Opportunities
Revenue

### PROJECTS

Active Projects
Project Portfolio

### INTELLIGENCE

AI Boardroom
Decisions
Insights
Research

### GOALS

Goals
Milestones

### HOUSEHOLD

Household
Shared Goals

### SYSTEM

Reports
Settings
AI Configuration

Use small, tasteful icons.

Do not use large colorful icons for every navigation item.

The active navigation item should use a subtle Charm-style highlight, potentially a warm panel treatment or restrained coral emphasis.

At the bottom:

User profile

**Gerald**

Personal status / account menu.

---

# 10. TOP BAR

The dashboard header should feel personal.

Left:

Small eyebrow:

`WEDNESDAY • AUGUST 5`

Main heading:

# Good morning, Gerald.

Supporting text:

`Here's your command center for today.`

Right side:

- Search
- Notifications
- OS status
- Quick Add
- Profile

Quick Add should use the Charm pill button style.

---

# 11. COMMAND STATUS BAND

Below the header, create a warm cream `#F1F2EA` contextual band.

This is an important visual feature.

It should contain the current AI-generated executive summary.

Example:

### TODAY'S COMMAND

> Financial pressure is currently high. Today's priority is generating income while protecting essential household cash.

On the right:

**Board status**

`CEO ✓`
`CFO ⚠`
`SALES ↑`
`CTO`
`CRITIC ⚠`

The board status should be compact.

This band should visually separate the AI command layer from ordinary dashboard data.

---

# 12. FINANCIAL HEALTH PRIMARY CARD

This is the most important dashboard widget.

Use a large softly rounded `24px` panel.

Title:

**Financial Health**

Small eyebrow:

`CFO REPORT`

Main number:

# UGX 0

Label:

`Available cash`

Secondary information:

**Monthly survival**
UGX 580,000

**Current debt**
$535

**Runway**
0 days

Status:

`CRITICAL`

Do not make the entire card red.

Use restrained semantic danger treatment.

The overall design should remain warm and calm.

Include:

**View finances →**

Use a pill-shaped secondary action.

---

# 13. TODAY'S INCOME TARGET

Panel title:

**Today's Income Target**

Main number:

# UGX 20,000

Supporting:

`Monthly target: UGX 580,000`

Progress:

`UGX 0 / UGX 20,000`

Use a clean progress bar with restrained brand styling.

Include:

**Create Income Plan**

This number should eventually be calculated dynamically by the CFO.

---

# 14. DEBT CARD

Title:

**Debt**

Display:

# $535

Show:

Remaining debt

Amount repaid

Next payment

Target payoff date

Use a simple progress visualization.

Avoid making debt repayment look like a game.

CTA:

**Manage debt →**

---

# 15. TODAY'S PRIORITIES

Create a large card:

**Today's Priorities**

Small eyebrow:

`OPERATIONS`

Display exactly three high-value actions.

Example:

### 01

Contact 20 potential software clients

### 02

Finish client website demo

### 03

Follow up with 5 existing leads

Each item includes:

- checkbox
- priority
- estimated time
- expected outcome

Completed tasks should visually transition into a calmer state.

CTA:

**View today's plan →**

---

# 16. BOARDROOM BRIEF

This is one of the signature components of the product.

Use a soft panel with subtle coral emphasis.

Title:

**Boardroom Brief**

Eyebrow:

`AI EXECUTIVE BOARD`

Display:

> **The board's recommendation**

Then:

> Do not spend today building new product features. Your highest-value action is client acquisition. Contact 20 qualified prospects before continuing development.

Below:

Executive contributors:

`CEO`
`CFO`
`SALES`
`CTO`
`CRITIC`

Use small pill badges.

The active executive badges can use subtle semantic states.

CTA:

**Enter Boardroom →**

This should feel like an intelligent executive recommendation, not a chatbot response.

---

# 17. REVENUE PIPELINE

Title:

**Revenue Pipeline**

Show a simple progression:

Prospects → Leads → Proposals → Negotiations → Won

Example:

20 → 8 → 3 → 2 → 1

Then:

**Pipeline value**
UGX 1,200,000

**Expected revenue**
UGX 450,000

Use simple visual connectors and compact pill-shaped stage indicators.

Avoid dense CRM tables on the main dashboard.

CTA:

**Open sales pipeline →**

---

# 18. ACTIVE PROJECTS

Title:

**Project Portfolio**

Show current projects.

Example:

### Client Websites

`REVENUE-GENERATING`

Priority:
HIGH

### FlowPesa

`STRATEGIC`

Priority:
MEDIUM

### Castalore

`VALIDATION`

Priority:
LOW

### Ride Sharing

`DEVELOPMENT`

Priority:
LOW

Each project should show:

- project name
- current status
- priority
- progress
- revenue
- next milestone

Use Charm-style tags/pills.

A project producing revenue should be visually distinguishable from a project still in experimentation.

---

# 19. GOALS

Title:

**Goals**

Organize into:

### 30 DAYS

Generate first UGX X

### 90 DAYS

Reach monthly income of UGX X

### 1 YEAR

Eliminate debt + build emergency reserve

### LONG TERM

Build substantial business assets

Use simple progress bars.

Do not make the dashboard feel like a video game.

---

# 20. NEEDS ATTENTION

Title:

**Needs Attention**

AI-generated alerts.

Examples:

`PAYMENT`
Debt payment approaching

`FINANCE`
Cash runway critically low

`SALES`
Follow-ups overdue

`PROJECT`
Project consuming time without revenue

`TARGET`
Monthly income below target

Each alert should use:

- semantic status
- concise explanation
- action

Do not make the section visually stressful.

---

# 21. AI OPPORTUNITIES

Title:

**AI Opportunities**

Display only the highest-value opportunities.

Example:

### School Website Opportunity

Potential:
UGX 800K

Effort:
3 days

Probability:
Medium

Time to cash:
7 days

CTA:

**Analyze opportunity →**

The Research Director should rank opportunities.

Do not show an endless feed.

---

# 22. DAILY EXECUTION SCORE

Create a compact widget:

# 72 / 100

Label:

**Today's Execution**

Breakdown:

Financial Discipline 80
Sales 60
Execution 85
Strategic Focus 70

CTA:

**Why this score?**

The explanation should be available through a drawer or modal.

The score should never become childish gamification.

It is an accountability metric.

---

# 23. QUICK ACTIONS

Create a compact action section.

Actions:

**+ Add Income**

**+ Add Expense**

**+ Add Debt Payment**

**+ Add Task**

**+ Add Prospect**

**+ Add Project**

And a special primary action:

# Ask the Board

Use Charm's coral primary button treatment.

---

# 24. AI COMMAND INPUT

Include a persistent AI command field.

Placeholder:

> Ask Gerald OS anything...

Supporting example prompts:

`Can we afford this?`

`Find me a way to make UGX 100K this week.`

`Review my finances.`

`Should I work on FlowPesa today?`

`Call the board.`

The input should look like part of the application rather than a generic ChatGPT clone.

Use the Charm panel and pill language.

---

# 25. PRIMARY ACTION STYLE

Primary actions:

- Coral `#E4544B`
- White text
- Pill-shaped
- subtle layered shadow
- subtle hover lift
- visible focus state

Example:

**Ask the Board →**

Secondary actions:

- warm panel
- subtle border
- dark text
- pill shape

Tertiary actions:

- text links
- brand text `#C9443A`

Do not use coral for every button.

Coral means:

**This matters.**

---

# 26. CARDS

All primary dashboard cards:

- Panel `#FBFAF9`
- Border `#E7E6E5`
- 24px radius
- minimal shadow
- generous internal spacing

Cards should feel related without looking like a grid of identical boxes.

Vary card size based on information importance.

Do not make every card exactly the same dimensions.

---

# 27. AI VISUAL LANGUAGE

AI should NOT be represented by:

- robot heads
- glowing brains
- neon gradients
- futuristic circuit graphics
- excessive stars/sparkles
- generic AI imagery

Instead, AI intelligence should be represented through:

- subtle coral accents
- clear labels
- concise recommendations
- executive role badges
- contextual panels
- intelligent explanations
- status indicators

GERALD OS should communicate:

**intelligence through behavior, not decoration.**

---

# 28. INFORMATION HIERARCHY

The dashboard hierarchy is:

1. Financial survival
2. Today's income target
3. Today's priorities
4. Boardroom recommendation
5. Revenue pipeline
6. Risks
7. Projects
8. Goals
9. Opportunities
10. Historical performance

The design should reflect this hierarchy visually.

---

# 29. EMPTY STATES

Design thoughtful empty states.

Examples:

No income recorded:

> **Your revenue story starts here.**
> Add your first income entry to begin tracking financial progress.

No prospects:

> **No prospects yet.**
> Let's find your first opportunity.

No projects:

> **Nothing active yet.**
> Add a project or ask the Board what deserves your attention.

No debt:

> **No outstanding debt recorded.**
> Excellent. Keep it that way.

Empty states should use the Charm warm surfaces and subtle accent bands.

---

# 30. LOADING STATES

Use calm skeleton loaders.

Avoid aggressive spinners.

AI-generated sections should communicate:

`BOARD ANALYZING...`

using Fragment Mono for the small system label.

Example:

`BOARD ANALYZING • CFO + CEO + CRITIC`

Then reveal the result.

---

# 31. ERROR STATES

Errors should be clear but calm.

Example:

**We couldn't update your financial data.**

`Your last saved information is still available.`

Button:

**Retry**

Do not use alarming red screens.

---

# 32. RESPONSIVE DESIGN

The dashboard must translate naturally to mobile.

Desktop:

Sidebar + multi-column dashboard.

Tablet:

Collapsed sidebar + reduced grid.

Mobile:

Bottom navigation or compact navigation system.

Priority order on mobile:

1. Financial Health
2. Today's Income Target
3. Today's Priorities
4. Boardroom Brief
5. Needs Attention
6. Revenue Pipeline
7. Goals
8. Projects
9. Opportunities

All cards become full-width where appropriate.

Avoid horizontal scrolling.

Buttons must remain touch-friendly.

Pills should not become tiny.

Typography should remain readable.

---

# 33. RESPONSIVE BEHAVIOR

Do not simply shrink the desktop design.

Recompose it.

For example:

Desktop:

Financial Health + Income Target + Debt

Mobile:

Financial Health
↓
Income Target
↓
Debt

Desktop:

Projects + Revenue Pipeline

Mobile:

Revenue Pipeline
↓
Projects

The information hierarchy must remain intact.

---

# 34. ACCESSIBILITY

Every component should include:

- visible focus states
- sufficient contrast
- readable typography
- semantic status indicators
- keyboard navigation
- accessible buttons
- accessible forms

Never communicate meaning through color alone.

---

# 35. DESIGN SYSTEM DELIVERABLES

Create reusable components for:

- application shell
- sidebar
- top bar
- cards
- panel headers
- pill buttons
- status badges
- progress bars
- alerts
- AI executive badges
- charts
- tables
- inputs
- search
- dropdowns
- modals
- drawers
- tabs
- empty states
- loading states
- error states

Use the Charm design tokens consistently across every component.

---

# 36. WHAT NOT TO DO

Do NOT make this look like:

- a traditional banking app
- a stock trading terminal
- an enterprise ERP
- a dark hacker dashboard
- a generic SaaS admin template
- a futuristic AI interface
- a neon AI dashboard
- a gamified productivity app

Do NOT use excessive:

- gradients
- glassmorphism
- shadows
- decorative illustrations
- chart spam
- colors
- rounded shapes without purpose

Do NOT sacrifice usability for visual novelty.

---

# 37. THE EMOTIONAL GOAL

The dashboard should make Gerald feel:

> **“I know where I stand.”**

Then:

> **“I know what matters.”**

Then:

> **“I know what I need to do next.”**

And finally:

> **“The system is helping me think.”**

The visual identity should feel warm enough to make Gerald want to open the application every morning, but serious enough that he trusts it with real financial decisions.

---

# 38. FINAL DESIGN PRINCIPLE

GERALD OS is fundamentally about turning:

**CHAOS → CLARITY**

**IDEAS → PRIORITIES**

**PRIORITIES → ACTION**

**ACTION → REVENUE**

**REVENUE → STABILITY**

**STABILITY → LEVERAGE**

**LEVERAGE → WEALTH**

The UI should visually reinforce that journey without becoming overly literal or gamified.

Build the dashboard as the foundation for the entire GERALD OS design system.

Every future screen must feel like it belongs to the same product.
