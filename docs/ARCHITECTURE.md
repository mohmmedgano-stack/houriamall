# Architecture — Media Buying Command Center

## Stack
- PHP 8.1+ (no framework, no Composer dependencies), PDO SQLite (WAL). Same stack as the existing auto-reply tool, so it deploys to the same Hostinger plan by git.
- Server-rendered pages + vanilla JS. Chart.js from cdnjs for charts.
- Background work: `worker.php` run by cron every minute (Meta sync, watchdog, automations, scheduled reports).

## Layout
```
index.php            front controller: ?r=<route>
worker.php           cron entry (CLI only)
src/                 all logic (denied over HTTP)
  bootstrap.php      config, autoload of modules, error handling
  db.php             PDO, migrations runner, query helpers
  migrations.php     numbered schema migrations
  auth.php           users, sessions, roles/permissions, login throttle, setup key
  util.php           escaping, CSRF, flash, formatting (money/number/date), pagination
  crypto.php         AES-256-GCM (OpenSSL) for integration secrets
  audit.php          audit_logs
  scope.php          per-user client access + demo/live mode scoping
  metrics.php        pure KPI math (null-safe, currency-safe)
  importer.php       CSV/XLSX performance import (Arabic + English Ads Manager headers)
  xlsx.php           minimal XLSX reader/writer (ZipArchive)
  analytics.php      aggregation queries, period comparison, best/worst
  budget.php  auditor.php  audience.php  creative.php
  crm.php  leadquality.php
  watchdog.php  reports.php
  automation.php  events.php  finance.php  copygen.php
  autoreply.php      bridge to the existing Gano Auto Reply DB (read-only)
  meta.php           Meta Marketing API client + sync
  ai.php             provider-agnostic AI layer (Anthropic / OpenAI / none)
views/               one PHP view per page, layout.php shell
assets/              app.css, app.js
data/                sqlite DB, encryption key, setup key (denied over HTTP)
tests/               test runner + suites
bin/                 CLI tools (seed demo, create user, backup)
```

## Data scoping
Everything hangs off `clients`. Every query that returns business data goes through `scope_client_ids()` which returns the clients the current user may see **and** that belong to the current mode (Live or Demo). Demo data lives on clients with `is_demo = 1`, is shown with a DEMO banner, and is never mixed into Live totals.

## Roles
| role | can |
|---|---|
| owner | everything incl. users, integrations, finance |
| admin | everything except deleting the owner |
| buyer | analytics, budgets, alerts, reports, creatives, auditor, copy, automations for assigned clients |
| sales | CRM for assigned clients (only leads assigned to them unless `crm.view_all`) |
| viewer | read-only dashboards/reports for assigned clients |

## Metric rules
- Missing data → `null` → shown as **N/A**, never 0.
- Division by zero → `null`.
- Reach and frequency are not additive across days: shown only when the selected range is covered by a single period row.
- Money is never summed across currencies; totals are grouped per currency.
- Leads and purchases are separate columns; "Results" keeps its `result_type`.

## Events → Automation
Modules emit events (`lead.created`, `lead.stage_changed`, `lead.overdue`, `alert.created`, `budget.threshold`, `schedule.tick`, `autoreply.failed`). The automation hub matches workflows by trigger, checks conditions, runs actions, and records a `workflow_executions` row with a unique `(workflow_id, dedupe_key)` so the same event never runs twice.
