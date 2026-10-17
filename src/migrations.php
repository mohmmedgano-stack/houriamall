<?php
declare(strict_types=1);

/**
 * Numbered schema migrations. Each runs once, inside a transaction, and is recorded in schema_migrations.
 * Never edit a migration that has shipped — add a new one.
 */
function migrations(): array {
    return [
        1 => <<<SQL
CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT);

CREATE TABLE roles (
  key TEXT PRIMARY KEY, name TEXT NOT NULL, permissions TEXT NOT NULL, position INTEGER DEFAULT 0
);

CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL, role TEXT NOT NULL REFERENCES roles(key),
  active INTEGER NOT NULL DEFAULT 1, locale_currency TEXT, timezone TEXT DEFAULT 'Africa/Cairo',
  last_login_at TEXT, created_at TEXT NOT NULL, updated_at TEXT
);

CREATE TABLE login_attempts (k TEXT PRIMARY KEY, fails INTEGER NOT NULL DEFAULT 0, last_at INTEGER NOT NULL);

CREATE TABLE clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, industry TEXT, contact_name TEXT, contact_phone TEXT,
  currency TEXT NOT NULL DEFAULT 'EGP', timezone TEXT NOT NULL DEFAULT 'Africa/Cairo',
  logo_path TEXT, notes TEXT, status TEXT NOT NULL DEFAULT 'active',
  is_demo INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL, updated_at TEXT
);
CREATE INDEX clients_demo ON clients(is_demo, status);

CREATE TABLE user_clients (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  PRIMARY KEY (user_id, client_id)
);

CREATE TABLE integrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL, name TEXT NOT NULL,
  config_enc TEXT, status TEXT NOT NULL DEFAULT 'not_configured',
  last_ok_at TEXT, last_error TEXT, created_at TEXT NOT NULL, updated_at TEXT
);

CREATE TABLE ad_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  platform TEXT NOT NULL DEFAULT 'meta', external_id TEXT, name TEXT NOT NULL,
  currency TEXT NOT NULL DEFAULT 'EGP', timezone TEXT,
  integration_id INTEGER REFERENCES integrations(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'active', sync_enabled INTEGER NOT NULL DEFAULT 0,
  last_sync_at TEXT, last_sync_error TEXT, created_at TEXT NOT NULL, updated_at TEXT
);
CREATE UNIQUE INDEX ad_accounts_ext ON ad_accounts(platform, external_id) WHERE external_id IS NOT NULL AND external_id <> '';
CREATE INDEX ad_accounts_client ON ad_accounts(client_id);

CREATE TABLE campaigns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ad_account_id INTEGER NOT NULL REFERENCES ad_accounts(id) ON DELETE CASCADE,
  external_id TEXT NOT NULL, name TEXT NOT NULL, objective TEXT, status TEXT, effective_status TEXT,
  daily_budget REAL, lifetime_budget REAL, start_time TEXT, stop_time TEXT,
  source TEXT NOT NULL DEFAULT 'manual', created_at TEXT NOT NULL, updated_at TEXT,
  UNIQUE(ad_account_id, external_id)
);

CREATE TABLE adsets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
  external_id TEXT NOT NULL, name TEXT NOT NULL, status TEXT, optimization_goal TEXT,
  daily_budget REAL, lifetime_budget REAL, targeting_json TEXT, audience_type TEXT,
  source TEXT NOT NULL DEFAULT 'manual', created_at TEXT NOT NULL, updated_at TEXT,
  UNIQUE(campaign_id, external_id)
);

CREATE TABLE creative_assets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  ad_account_id INTEGER REFERENCES ad_accounts(id) ON DELETE SET NULL,
  external_id TEXT, name TEXT, type TEXT, thumbnail_url TEXT, body TEXT, title TEXT, cta TEXT, link_url TEXT,
  hook_type TEXT, angle TEXT, audience_label TEXT, product TEXT, tags TEXT, notes TEXT,
  in_library INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT
);
CREATE INDEX creative_client ON creative_assets(client_id);

CREATE TABLE ads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  adset_id INTEGER NOT NULL REFERENCES adsets(id) ON DELETE CASCADE,
  external_id TEXT NOT NULL, name TEXT NOT NULL, status TEXT,
  creative_asset_id INTEGER REFERENCES creative_assets(id) ON DELETE SET NULL,
  source TEXT NOT NULL DEFAULT 'manual', created_at TEXT NOT NULL, updated_at TEXT,
  UNIQUE(adset_id, external_id)
);

-- One row = metrics of one entity for one period. Daily rows have date_start = date_end.
-- NULL means "not available", never "zero".
CREATE TABLE performance_snapshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ad_account_id INTEGER NOT NULL REFERENCES ad_accounts(id) ON DELETE CASCADE,
  level TEXT NOT NULL CHECK(level IN ('campaign','adset','ad')),
  entity_id INTEGER NOT NULL,
  date_start TEXT NOT NULL, date_end TEXT NOT NULL,
  breakdown_key TEXT NOT NULL DEFAULT '', breakdown_value TEXT NOT NULL DEFAULT '',
  currency TEXT NOT NULL,
  spend REAL, impressions INTEGER, reach INTEGER, clicks INTEGER, link_clicks INTEGER,
  results REAL, result_type TEXT, leads REAL, purchases REAL, purchase_value REAL,
  landing_page_views INTEGER, video_3s INTEGER, thruplays INTEGER,
  video_p25 INTEGER, video_p50 INTEGER, video_p75 INTEGER, video_p100 INTEGER,
  source TEXT NOT NULL DEFAULT 'csv', imported_at TEXT NOT NULL,
  UNIQUE(level, entity_id, date_start, date_end, breakdown_key, breakdown_value)
);
CREATE INDEX perf_acc_date ON performance_snapshots(ad_account_id, level, date_start);
CREATE INDEX perf_entity ON performance_snapshots(level, entity_id, date_start);

CREATE TABLE import_batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
  kind TEXT NOT NULL, filename TEXT, rows_total INTEGER DEFAULT 0, inserted INTEGER DEFAULT 0,
  updated INTEGER DEFAULT 0, skipped INTEGER DEFAULT 0, errors_json TEXT,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL
);

CREATE TABLE pipeline_stages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0, color TEXT,
  is_contacted INTEGER NOT NULL DEFAULT 0, is_qualified INTEGER NOT NULL DEFAULT 0,
  is_won INTEGER NOT NULL DEFAULT 0, is_lost INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE leads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  campaign_id INTEGER REFERENCES campaigns(id) ON DELETE SET NULL,
  adset_id INTEGER REFERENCES adsets(id) ON DELETE SET NULL,
  ad_id INTEGER REFERENCES ads(id) ON DELETE SET NULL,
  source TEXT NOT NULL DEFAULT 'manual', external_id TEXT,
  full_name TEXT, phone TEXT, phone_norm TEXT, email TEXT, email_norm TEXT, city TEXT, product TEXT,
  form_name TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT,
  extra_json TEXT,
  stage_id INTEGER REFERENCES pipeline_stages(id), owner_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  next_follow_up_at TEXT, first_contact_at TEXT, qualified_at TEXT, appointment_at TEXT,
  won_at TEXT, lost_at TEXT, lost_reason TEXT, deal_value REAL, deal_currency TEXT,
  duplicate_of INTEGER REFERENCES leads(id) ON DELETE SET NULL,
  received_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT
);
CREATE UNIQUE INDEX leads_ext ON leads(client_id, source, external_id) WHERE external_id IS NOT NULL AND external_id <> '';
CREATE INDEX leads_client_stage ON leads(client_id, stage_id);
CREATE INDEX leads_phone ON leads(client_id, phone_norm);
CREATE INDEX leads_email ON leads(client_id, email_norm);
CREATE INDEX leads_owner ON leads(owner_user_id, next_follow_up_at);
CREATE INDEX leads_campaign ON leads(campaign_id);

CREATE TABLE lead_activities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  type TEXT NOT NULL, outcome TEXT, body TEXT, meta_json TEXT, created_at TEXT NOT NULL
);
CREATE INDEX lead_act ON lead_activities(lead_id, created_at);

CREATE TABLE lead_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  assigned_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  assigned_at TEXT NOT NULL, unassigned_at TEXT
);
CREATE INDEX lead_assign ON lead_assignments(lead_id);

CREATE TABLE budgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  ad_account_id INTEGER REFERENCES ad_accounts(id) ON DELETE CASCADE,
  campaign_id INTEGER REFERENCES campaigns(id) ON DELETE CASCADE,
  name TEXT NOT NULL, period_start TEXT NOT NULL, period_end TEXT NOT NULL,
  amount REAL NOT NULL, currency TEXT NOT NULL,
  target_metric TEXT, target_cost REAL, target_results REAL,
  alert_pct REAL DEFAULT 90, notes TEXT, created_at TEXT NOT NULL, updated_at TEXT
);
CREATE INDEX budgets_client ON budgets(client_id, period_start);

CREATE TABLE alert_rules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
  ad_account_id INTEGER REFERENCES ad_accounts(id) ON DELETE CASCADE,
  campaign_id INTEGER REFERENCES campaigns(id) ON DELETE CASCADE,
  name TEXT NOT NULL, metric TEXT NOT NULL, operator TEXT NOT NULL, threshold REAL NOT NULL,
  window_days INTEGER NOT NULL DEFAULT 3, min_spend REAL DEFAULT 0, min_impressions INTEGER DEFAULT 0, min_results REAL DEFAULT 0,
  severity TEXT NOT NULL DEFAULT 'warning', channel TEXT NOT NULL DEFAULT 'in_app',
  cooldown_hours INTEGER NOT NULL DEFAULT 24, active INTEGER NOT NULL DEFAULT 1,
  last_evaluated_at TEXT, created_at TEXT NOT NULL, updated_at TEXT
);

CREATE TABLE alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  rule_id INTEGER REFERENCES alert_rules(id) ON DELETE SET NULL,
  client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
  campaign_id INTEGER REFERENCES campaigns(id) ON DELETE SET NULL,
  kind TEXT NOT NULL DEFAULT 'rule', severity TEXT NOT NULL, title TEXT NOT NULL, message TEXT,
  metric_value REAL, threshold REAL, evidence_json TEXT, dedupe_key TEXT,
  status TEXT NOT NULL DEFAULT 'open', acked_by INTEGER REFERENCES users(id) ON DELETE SET NULL, acked_at TEXT,
  created_at TEXT NOT NULL
);
CREATE INDEX alerts_status ON alerts(status, created_at);
CREATE INDEX alerts_dedupe ON alerts(dedupe_key, created_at);

CREATE TABLE reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
  type TEXT NOT NULL, title TEXT NOT NULL, period_start TEXT NOT NULL, period_end TEXT NOT NULL,
  campaign_id INTEGER REFERENCES campaigns(id) ON DELETE SET NULL,
  data_json TEXT NOT NULL, summary_text TEXT, ai_used INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL
);

CREATE TABLE audit_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, items_json TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE TABLE campaign_audits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  campaign_id INTEGER REFERENCES campaigns(id) ON DELETE SET NULL,
  template_id INTEGER REFERENCES audit_templates(id) ON DELETE SET NULL,
  name TEXT NOT NULL, results_json TEXT NOT NULL, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL, updated_at TEXT
);

CREATE TABLE creative_ideas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  title TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'copy', inputs_json TEXT, output_json TEXT,
  status TEXT NOT NULL DEFAULT 'idea', creative_asset_id INTEGER REFERENCES creative_assets(id) ON DELETE SET NULL,
  test_notes TEXT, source TEXT NOT NULL DEFAULT 'template',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL, updated_at TEXT
);

CREATE TABLE audience_analyses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  name TEXT NOT NULL, params_json TEXT NOT NULL, result_json TEXT NOT NULL,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL
);

CREATE TABLE client_services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  name TEXT NOT NULL, fee_type TEXT NOT NULL DEFAULT 'monthly', amount REAL NOT NULL, currency TEXT NOT NULL,
  start_date TEXT NOT NULL, end_date TEXT, billing_day INTEGER DEFAULT 1, active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);
CREATE TABLE client_invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  service_id INTEGER REFERENCES client_services(id) ON DELETE SET NULL,
  number TEXT, description TEXT, amount REAL NOT NULL, currency TEXT NOT NULL,
  issue_date TEXT NOT NULL, due_date TEXT NOT NULL, paid_amount REAL NOT NULL DEFAULT 0, paid_at TEXT,
  status TEXT NOT NULL DEFAULT 'unpaid', notes TEXT, created_at TEXT NOT NULL, updated_at TEXT
);
CREATE INDEX invoices_due ON client_invoices(status, due_date);
CREATE TABLE expenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
  category TEXT NOT NULL, vendor TEXT, amount REAL NOT NULL, currency TEXT NOT NULL,
  date TEXT NOT NULL, notes TEXT, is_demo INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
);

CREATE TABLE events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL, client_id INTEGER, ref TEXT, payload_json TEXT,
  created_at TEXT NOT NULL, processed_at TEXT
);
CREATE INDEX events_pending ON events(processed_at, id);

CREATE TABLE automation_workflows (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
  trigger_type TEXT NOT NULL, trigger_config_json TEXT, conditions_json TEXT, actions_json TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1, max_retries INTEGER NOT NULL DEFAULT 3,
  last_run_at TEXT, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL, updated_at TEXT
);
CREATE TABLE workflow_executions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  workflow_id INTEGER NOT NULL REFERENCES automation_workflows(id) ON DELETE CASCADE,
  event_id INTEGER, dedupe_key TEXT NOT NULL, status TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0, log_json TEXT, error TEXT,
  started_at TEXT NOT NULL, finished_at TEXT, next_retry_at TEXT,
  UNIQUE(workflow_id, dedupe_key)
);
CREATE INDEX wf_exec_retry ON workflow_executions(status, next_retry_at);

CREATE TABLE notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title TEXT NOT NULL, body TEXT, link TEXT, severity TEXT DEFAULT 'info', read_at TEXT, created_at TEXT NOT NULL
);
CREATE INDEX notif_user ON notifications(user_id, read_at);

CREATE TABLE autoreply_pages (
  page_id TEXT PRIMARY KEY, page_name TEXT, client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL
);

CREATE TABLE ai_calls (
  id INTEGER PRIMARY KEY AUTOINCREMENT, task TEXT NOT NULL, provider TEXT, model TEXT,
  input_tokens INTEGER, output_tokens INTEGER, status TEXT NOT NULL, error TEXT,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL
);

CREATE TABLE audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  action TEXT NOT NULL, entity TEXT, entity_id TEXT, details_json TEXT, ip TEXT, created_at TEXT NOT NULL
);
CREATE INDEX audit_created ON audit_logs(created_at);
SQL,
    ];
}
