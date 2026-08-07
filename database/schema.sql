-- ---------------------------------------------------------------------------
-- GERMA OS schema
--
-- Money is stored as BIGINT minor units + an explicit currency column. UGX has
-- 0 decimals, USD has 2 (see config/config.php money.decimals). Never store a
-- money value as DECIMAL/FLOAT here.
--
-- Import:  mysql -u root germa_os < database/schema.sql
--    or:   php bin/migrate.php
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Operating parameters ────────────────────────────────────────────────────
-- The spec is explicit that the survival number, debt and cash "may change and
-- must never be treated as permanently fixed". They live here, not in code.
CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(64)  NOT NULL,
  `value`     TEXT         NOT NULL,
  `group`     VARCHAR(32)  NOT NULL DEFAULT 'general',
  label       VARCHAR(160) NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which operating mode the OS is in, and why. History is kept so the weekly
-- review can say "we were in emergency mode for 9 days".
CREATE TABLE IF NOT EXISTS mode_periods (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  mode        ENUM('normal','emergency','opportunity') NOT NULL DEFAULT 'normal',
  reason      VARCHAR(255) NULL,
  entered_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  exited_at   DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_mode_open (exited_at, entered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Money ───────────────────────────────────────────────────────────────────

-- An observed cash balance, entered by Gerald. This is the anchor: available
-- cash = latest snapshot + ledger movement since that snapshot. That keeps the
-- headline number honest ("entered by you") instead of a derived guess.
CREATE TABLE IF NOT EXISTS cash_snapshots (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  currency      CHAR(3)      NOT NULL DEFAULT 'UGX',
  balance_minor BIGINT       NOT NULL DEFAULT 0,
  observed_on   DATE         NOT NULL,
  -- The highest ledger_entries.id that existed when this balance was observed.
  -- Movement since the snapshot is everything above this mark. Using the id
  -- rather than a timestamp makes the boundary monotonic, so a clock change
  -- can never silently drop entries out of the cash figure.
  ledger_mark   INT UNSIGNED NOT NULL DEFAULT 0,
  note          VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_snapshot_latest (currency, observed_on, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  direction    ENUM('in','out') NOT NULL,
  amount_minor BIGINT       NOT NULL,
  currency     CHAR(3)      NOT NULL DEFAULT 'UGX',
  occurred_on  DATE         NOT NULL,
  category     VARCHAR(64)  NOT NULL DEFAULT 'general',
  description  VARCHAR(255) NULL,
  -- Essential spending survives emergency mode; non-essential does not.
  is_essential TINYINT(1)   NOT NULL DEFAULT 1,
  project_id   INT UNSIGNED NULL,
  deal_id      INT UNSIGNED NULL,
  source       ENUM('entered','recurring','system') NOT NULL DEFAULT 'entered',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ledger_date (occurred_on, direction),
  KEY idx_ledger_project (project_id),
  KEY idx_ledger_deal (deal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bills (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(120) NOT NULL,
  amount_minor BIGINT       NOT NULL DEFAULT 0,
  currency     CHAR(3)      NOT NULL DEFAULT 'UGX',
  due_on       DATE         NOT NULL,
  recurrence   ENUM('none','weekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  is_essential TINYINT(1)   NOT NULL DEFAULT 1,
  status       ENUM('due','paid','skipped') NOT NULL DEFAULT 'due',
  paid_on      DATE         NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bills_due (status, due_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS debts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(120) NOT NULL,
  lender           VARCHAR(120) NULL,
  principal_minor  BIGINT       NOT NULL DEFAULT 0,
  currency         CHAR(3)      NOT NULL DEFAULT 'USD',
  interest_note    VARCHAR(160) NULL,
  opened_on        DATE         NULL,
  target_payoff_on DATE         NULL,
  status           ENUM('active','settled') NOT NULL DEFAULT 'active',
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_debts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS debt_payments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  debt_id      INT UNSIGNED NOT NULL,
  amount_minor BIGINT       NOT NULL,
  currency     CHAR(3)      NOT NULL DEFAULT 'USD',
  due_on       DATE         NULL,
  paid_on      DATE         NULL,
  status       ENUM('scheduled','paid','missed') NOT NULL DEFAULT 'scheduled',
  note         VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_debt_payments (debt_id, status, due_on),
  CONSTRAINT fk_debt_payments_debt FOREIGN KEY (debt_id) REFERENCES debts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Revenue generation ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS clients (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  org        VARCHAR(120) NULL,
  email      VARCHAR(160) NULL,
  phone      VARCHAR(60)  NULL,
  notes      TEXT         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deals (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id         INT UNSIGNED NULL,
  title             VARCHAR(160) NOT NULL,
  stage             ENUM('prospect','lead','proposal','negotiation','won','lost') NOT NULL DEFAULT 'prospect',
  value_minor       BIGINT       NOT NULL DEFAULT 0,
  currency          CHAR(3)      NOT NULL DEFAULT 'UGX',
  probability_pct   TINYINT UNSIGNED NOT NULL DEFAULT 20,
  expected_close_on DATE         NULL,
  last_contact_on   DATE         NULL,
  next_action       VARCHAR(200) NULL,
  next_action_on    DATE         NULL,
  source            VARCHAR(80)  NULL,
  notes             TEXT         NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_deals_stage (stage),
  KEY idx_deals_contact (last_contact_on),
  CONSTRAINT fk_deals_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stage history is what makes "+6 this week" a fact instead of a decoration.
CREATE TABLE IF NOT EXISTS deal_stage_events (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  deal_id    INT UNSIGNED NOT NULL,
  from_stage ENUM('prospect','lead','proposal','negotiation','won','lost') NULL,
  to_stage   ENUM('prospect','lead','proposal','negotiation','won','lost') NOT NULL,
  changed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stage_events (to_stage, changed_at),
  CONSTRAINT fk_stage_events_deal FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunities (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title                 VARCHAR(160) NOT NULL,
  summary               TEXT         NULL,
  potential_value_minor BIGINT       NOT NULL DEFAULT 0,
  currency              CHAR(3)      NOT NULL DEFAULT 'UGX',
  effort_days           DECIMAL(5,1) NULL,
  effort_note           VARCHAR(60)  NULL,
  probability           ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
  time_to_cash_days     SMALLINT UNSIGNED NULL,
  -- Opportunity mode classification from the spec.
  verdict               ENUM('unreviewed','do','consider','ignore') NOT NULL DEFAULT 'unreviewed',
  rank_score            DECIMAL(10,2) NOT NULL DEFAULT 0,
  status                ENUM('open','pursued','archived') NOT NULL DEFAULT 'open',
  source                ENUM('user','ai','research') NOT NULL DEFAULT 'user',
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_opps_rank (status, rank_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Work ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS projects (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                 VARCHAR(120) NOT NULL,
  purpose              TEXT         NULL,
  problem              TEXT         NULL,
  customer             VARCHAR(160) NULL,
  validation           VARCHAR(255) NULL,
  status               ENUM('revenue','strategic','validation','development','paused') NOT NULL DEFAULT 'development',
  priority             ENUM('HIGH','MEDIUM','LOW') NOT NULL DEFAULT 'LOW',
  progress_pct         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  money_invested_minor BIGINT       NOT NULL DEFAULT 0,
  -- No revenue column: project revenue is summed from ledger_entries
  -- attributed to the project. A stored total drifts the moment a
  -- payment is logged without anyone updating the project.
  currency             CHAR(3)      NOT NULL DEFAULT 'UGX',
  next_milestone       VARCHAR(160) NULL,
  next_milestone_on    DATE         NULL,
  strategic_value      VARCHAR(255) NULL,
  risk_note            VARCHAR(255) NULL,
  -- Every project periodically gets one of these four verdicts.
  decision             ENUM('continue','pause','pivot','kill') NOT NULL DEFAULT 'continue',
  decided_on           DATE         NULL,
  next_review_on       DATE         NULL,
  archived             TINYINT(1)   NOT NULL DEFAULT 0,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_projects_active (archived, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hours per project per day. Without this, "18 hours logged in 14 days with no
-- revenue path" cannot be asserted, and the Critic has nothing to stand on.
CREATE TABLE IF NOT EXISTS project_time_logs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  logged_on  DATE         NOT NULL,
  note       VARCHAR(255) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_time_logs (project_id, logged_on),
  CONSTRAINT fk_time_logs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title             VARCHAR(200) NOT NULL,
  detail            TEXT         NULL,
  project_id        INT UNSIGNED NULL,
  deal_id           INT UNSIGNED NULL,
  -- 1..3 populate the "Top 3 Priorities" card; NULL is an ordinary task.
  priority_rank     TINYINT UNSIGNED NULL,
  -- Importance, independent of whether it made today's top three.
  priority          ENUM('HIGH','MEDIUM','LOW') NOT NULL DEFAULT 'MEDIUM',
  estimated_minutes SMALLINT UNSIGNED NULL,
  expected_outcome  VARCHAR(160) NULL,
  for_date          DATE         NOT NULL,
  due_on            DATE         NULL,
  status            ENUM('open','done','dropped') NOT NULL DEFAULT 'open',
  completed_at      DATETIME     NULL,
  origin            ENUM('user','ai') NOT NULL DEFAULT 'user',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tasks_day (for_date, priority_rank),
  KEY idx_tasks_status (status, for_date),
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_deal FOREIGN KEY (deal_id) REFERENCES deals (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goals (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  horizon       ENUM('30d','90d','1y','long') NOT NULL,
  title         VARCHAR(200) NOT NULL,
  metric        ENUM('money','count','percent','binary') NOT NULL DEFAULT 'money',
  target_value  BIGINT       NOT NULL DEFAULT 0,
  current_value BIGINT       NOT NULL DEFAULT 0,
  currency      CHAR(3)      NULL,
  start_on      DATE         NULL,
  target_on     DATE         NULL,
  status        ENUM('active','achieved','abandoned') NOT NULL DEFAULT 'active',
  -- Whose goal this is. The spec treats the household as part of the operating
  -- system, not as a cost centre, so a household goal is a first-class goal
  -- rather than a note attached to a business one.
  scope         ENUM('business','household','shared') NOT NULL DEFAULT 'business',
  why           VARCHAR(255) NULL,
  review_on     DATE         NULL,
  sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_goals_active (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Savings ─────────────────────────────────────────────────────────────────
-- Reserves are step 5 of the spec's decision hierarchy, and they are tracked
-- apart from the ledger on purpose: a deposit moves money Gerald has already
-- counted into a pot he does not spend from, so it must not read as either
-- income or expenditure. Available cash stays anchored to the balance he can
-- actually reach.
CREATE TABLE IF NOT EXISTS savings_goals (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(120) NOT NULL,
  -- 'emergency' targets are derived from the survival cost, not typed in, so
  -- the reserve target follows the household rather than going stale.
  kind         ENUM('emergency','goal') NOT NULL DEFAULT 'goal',
  target_minor BIGINT       NOT NULL DEFAULT 0,
  currency     CHAR(3)      NOT NULL DEFAULT 'UGX',
  months_cover TINYINT UNSIGNED NOT NULL DEFAULT 3,
  note         VARCHAR(255) NULL,
  priority     TINYINT UNSIGNED NOT NULL DEFAULT 10,
  status       ENUM('active','reached','abandoned') NOT NULL DEFAULT 'active',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_savings_goals (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS savings_entries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  goal_id      INT UNSIGNED NULL,
  direction    ENUM('in','out') NOT NULL DEFAULT 'in',
  amount_minor BIGINT       NOT NULL,
  currency     CHAR(3)      NOT NULL DEFAULT 'UGX',
  occurred_on  DATE         NOT NULL,
  note         VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_savings_entries (occurred_on, direction),
  KEY idx_savings_goal (goal_id),
  CONSTRAINT fk_savings_entries_goal FOREIGN KEY (goal_id) REFERENCES savings_goals (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Intelligence output ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS alerts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(64)  NOT NULL,
  title        VARCHAR(160) NOT NULL,
  body         TEXT         NOT NULL,
  severity     ENUM('CRITICAL','HIGH','MEDIUM','LOW') NOT NULL DEFAULT 'MEDIUM',
  action_label VARCHAR(80)  NULL,
  action_url   VARCHAR(200) NULL,
  status       ENUM('open','dismissed','resolved') NOT NULL DEFAULT 'open',
  source       ENUM('rules','ai') NOT NULL DEFAULT 'rules',
  first_seen_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at   DATETIME    NULL,
  PRIMARY KEY (id),
  -- One live row per rule. Re-evaluating refreshes it instead of piling up.
  UNIQUE KEY uniq_alert_code_status (code, status),
  KEY idx_alerts_open (status, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_briefs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  for_date     DATE         NOT NULL,
  headline     VARCHAR(200) NULL,
  body         TEXT         NOT NULL,
  -- "One thing Gerald should NOT do": item 8 of the spec's Daily Command
  -- Brief, kept as its own field so the screen can give it its own weight.
  do_not       VARCHAR(255) NULL,
  mode         ENUM('normal','emergency','opportunity') NOT NULL DEFAULT 'normal',
  generator    ENUM('rules','claude') NOT NULL DEFAULT 'rules',
  model        VARCHAR(60)  NULL,
  generated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_brief_date (for_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_briefs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  for_date       DATE         NOT NULL,
  recommendation TEXT         NOT NULL,
  rationale      TEXT         NULL,
  generator      ENUM('rules','claude') NOT NULL DEFAULT 'rules',
  model          VARCHAR(60)  NULL,
  generated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_board_date (for_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which executives weighed in and how they landed.
CREATE TABLE IF NOT EXISTS board_signals (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  board_brief_id INT UNSIGNED NOT NULL,
  role           VARCHAR(32)  NOT NULL,
  -- Backticked: SIGNAL is a reserved word in MariaDB.
  `signal`       ENUM('ok','warn','up','down','neutral') NOT NULL DEFAULT 'neutral',
  note           VARCHAR(255) NULL,
  sort_order     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_signals_brief (board_brief_id, sort_order),
  CONSTRAINT fk_signals_brief FOREIGN KEY (board_brief_id) REFERENCES board_briefs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_scores (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  for_date             DATE         NOT NULL,
  total                TINYINT UNSIGNED NOT NULL DEFAULT 0,
  financial_discipline TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sales                TINYINT UNSIGNED NOT NULL DEFAULT 0,
  execution            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  strategic_focus      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  explanation          TEXT         NULL,
  computed_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_score_date (for_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- End-of-day review inputs (the numbers the spec says to collect every night).
CREATE TABLE IF NOT EXISTS day_logs (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  for_date            DATE         NOT NULL,
  prospects_contacted SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  followups_made      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  hours_worked        DECIMAL(4,1) NOT NULL DEFAULT 0,
  wins                TEXT         NULL,
  problems            TEXT         NULL,
  notes               TEXT         NULL,
  closed_at           DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_daylog_date (for_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS decisions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title          VARCHAR(200) NOT NULL,
  situation      TEXT         NULL,
  options_json   LONGTEXT     NULL,
  recommendation TEXT         NULL,
  next_action    VARCHAR(255) NULL,
  metric         VARCHAR(160) NULL,
  status         ENUM('open','decided','reviewed','reversed') NOT NULL DEFAULT 'open',
  decided_on     DATE         NULL,
  review_on      DATE         NULL,
  outcome        TEXT         NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_decisions_review (status, review_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every natural-language question put to the OS, and what came back.
CREATE TABLE IF NOT EXISTS commands (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  prompt      TEXT         NOT NULL,
  response    LONGTEXT     NULL,
  roles_json  VARCHAR(255) NULL,
  mode        ENUM('normal','emergency','opportunity') NOT NULL DEFAULT 'normal',
  driver      ENUM('rules','claude') NOT NULL DEFAULT 'rules',
  model       VARCHAR(60)  NULL,
  latency_ms  INT UNSIGNED NULL,
  error       VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_commands_recent (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What Gerald believes about the world outside his own records, and how he
-- knows it. The spec requires research to distinguish FACT from ESTIMATE from
-- ASSUMPTION, and insists old information is not assumed still accurate: hence
-- checked_on and a shelf life rather than a note that sits there forever
-- looking equally true.
CREATE TABLE IF NOT EXISTS research_notes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question         VARCHAR(200) NOT NULL,
  finding          TEXT         NOT NULL,
  kind             ENUM('fact','estimate','assumption') NOT NULL DEFAULT 'assumption',
  source           VARCHAR(255) NULL,
  applies_to       VARCHAR(160) NULL,
  checked_on       DATE         NOT NULL,
  -- How long this stays trustworthy. A price checked today is not a price in a
  -- year, and an assumption should expire sooner than a verified fact.
  shelf_life_days  SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  archived         TINYINT(1)   NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_research_freshness (archived, checked_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The middle of the spec's chain: Goal -> Milestones -> Tasks -> Deadline ->
-- Metric -> Review. A goal with no milestone is the abstract advice the
-- Operations Director is told never to leave Gerald with, so these are what
-- turn a goal into a next step.
CREATE TABLE IF NOT EXISTS goal_milestones (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  goal_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(200) NOT NULL,
  metric     VARCHAR(160) NULL,
  target_on  DATE         NULL,
  done_on    DATE         NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_milestones_goal (goal_id, done_on, target_on),
  CONSTRAINT fk_milestones_goal FOREIGN KEY (goal_id) REFERENCES goals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the household is owed that is not money: time, rest, responsibilities
-- kept. The Life & Household Director exists because a life optimised purely for
-- income stops being worth living, and the only way an OS can notice that is if
-- these carry a cadence and a date they were last honoured.
CREATE TABLE IF NOT EXISTS household_commitments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title            VARCHAR(160) NOT NULL,
  kind             ENUM('relationship','responsibility','rest','development') NOT NULL DEFAULT 'relationship',
  -- How often this is meant to happen. Zero means one-off, no cadence.
  cadence_days     SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  last_honoured_on DATE         NULL,
  note             VARCHAR(255) NULL,
  archived         TINYINT(1)   NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_commitments (archived, last_honoured_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
