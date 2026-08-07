-- ---------------------------------------------------------------------------
-- GERMA OS baseline seed  ·  a NEW, EMPTY install
--
-- This holds only the OS's own default judgement calls: the risk, sales and
-- work thresholds, the currency, and the starting mode. It contains NO user
-- information, because that is exactly what the first-run setup collects. A
-- fresh install therefore has no operator, no survival figure, no cash, no
-- debts, no projects and no goals until you enter them.
--
-- On first login the app detects this and walks you through setup. For a fully
-- populated illustrative dataset instead, run with --demo.
--
-- Safe to re-run: every insert is keyed or guarded.
-- ---------------------------------------------------------------------------

-- ── Operating parameters ────────────────────────────────────────────────────
-- Defaults the OS ships with. These are thresholds it measures against, not
-- facts about you: the Settings screen explains and lets you change each one.
-- Deliberately absent: operator_name, operator_location and the monthly
-- survival requirement, which the setup flow asks for because they are yours.
INSERT INTO settings (`key`, `value`, `group`, label) VALUES
  ('base_currency',         'UGX',    'money',  'Base currency'),
  ('usd_ugx_rate',          '3800',   'money',  'USD to UGX rate (manually maintained assumption)'),
  ('usd_ugx_rate_set_on',   '',       'money',  'Date the USD rate was last confirmed'),
  ('work_days_per_week',    '6',      'work',   'Working days per week (Mon-Sat)'),
  ('daily_prospect_target', '20',     'sales',  'Prospects to contact per working day'),
  ('followup_stale_days',   '6',      'sales',  'Days before a lead counts as neglected'),
  ('runway_critical_days',  '7',      'risk',   'Runway below this triggers emergency mode'),
  ('runway_warning_days',   '21',     'risk',   'Runway below this triggers a warning'),
  ('auto_emergency_mode',   '1',      'risk',   'Let the OS switch modes on its own'),
  ('non_revenue_hours_cap', '10',     'risk',   'Hours in 14 days a non-revenue project may consume before the Critic objects')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Start in normal mode. ModeService re-evaluates on every dashboard load and
-- will move to emergency on its own once a runway figure exists to demand it.
INSERT INTO mode_periods (mode, reason, entered_at)
SELECT 'normal', 'Initial state', NOW()
WHERE NOT EXISTS (SELECT 1 FROM mode_periods WHERE exited_at IS NULL);

-- ── Savings ─────────────────────────────────────────────────────────────────
-- The emergency reserve is a built-in tracker rather than user data. Its target
-- is derived from the survival cost by SavingsService, so it starts at zero and
-- follows whatever survival figure you enter during setup.
INSERT INTO savings_goals (name, kind, target_minor, currency, months_cover, priority, note)
SELECT 'Emergency fund', 'emergency', 0, 'UGX', 3, 1, 'Three months of household survival costs'
WHERE NOT EXISTS (SELECT 1 FROM savings_goals WHERE kind = 'emergency');
