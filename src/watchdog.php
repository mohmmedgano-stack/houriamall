<?php
declare(strict_types=1);
/*
 * Campaign Watchdog — monitoring and alerts ONLY. It never pauses campaigns or changes budgets.
 * A rule fires for a campaign when, over the last N days, the metric crosses the threshold AND the minimum
 * data volume is met; the same rule+campaign won't alert again inside its cool-down.
 */

function watch_metrics(): array {
    return ['cpl' => ['CPL', '>'], 'cpa' => ['CPA', '>'], 'cost_per_result' => ['Cost per Result', '>'], 'link_cpc' => ['Link CPC', '>'], 'cpc' => ['CPC (all)', '>'],
        'cpm' => ['CPM', '>'], 'link_ctr' => ['Link CTR %', '<'], 'ctr' => ['CTR (all) %', '<'], 'roas' => ['ROAS', '<'], 'lead_cvr' => ['Lead CVR %', '<'],
        'purchase_cvr' => ['Purchase CVR %', '<'], 'frequency' => ['Frequency', '>'], 'spend_no_results' => ['صرف من غير نتايج', '>'], 'delivery' => ['مشكلة في التسليم/الحالة', '=']];
}
const PCT_METRICS = ['link_ctr', 'ctr', 'lead_cvr', 'purchase_cvr'];

function alert_rule_save(?int $id, array $in): int {
    $metric = (string)($in['metric'] ?? '');
    if (!isset(watch_metrics()[$metric])) throw new InvalidArgumentException('اختار مؤشر');
    $row = ['name' => trim((string)($in['name'] ?? '')) ?: watch_metrics()[$metric][0], 'metric' => $metric,
        'operator' => in_array($in['operator'] ?? '', ['gt', 'lt'], true) ? $in['operator'] : (watch_metrics()[$metric][1] === '<' ? 'lt' : 'gt'),
        'threshold' => parse_num((string)($in['threshold'] ?? '')),
        'window_days' => max(1, min(90, (int)($in['window_days'] ?? 3))),
        'min_spend' => max(0, (float)(parse_num((string)($in['min_spend'] ?? '0')) ?? 0)), 'min_impressions' => max(0, (int)($in['min_impressions'] ?? 0)),
        'min_results' => max(0, (float)(parse_num((string)($in['min_results'] ?? '0')) ?? 0)),
        'severity' => in_array($in['severity'] ?? '', ['info', 'warning', 'critical'], true) ? $in['severity'] : 'warning',
        'channel' => in_array($in['channel'] ?? '', ['in_app', 'email'], true) ? $in['channel'] : 'in_app',
        'cooldown_hours' => max(1, min(720, (int)($in['cooldown_hours'] ?? 24))), 'active' => !empty($in['active']) ? 1 : 0,
        'client_id' => (int)($in['client_id'] ?? 0) ?: null, 'ad_account_id' => (int)($in['ad_account_id'] ?? 0) ?: null, 'campaign_id' => (int)($in['campaign_id'] ?? 0) ?: null];
    if ($metric === 'delivery') $row['threshold'] = 0.0;
    if ($row['threshold'] === null) throw new InvalidArgumentException('حدد الحد');
    if (in_array($metric, PCT_METRICS, true) && $row['threshold'] > 1) $row['threshold'] /= 100;   // typed "1.2" meaning 1.2%
    if ($row['campaign_id']) { $row['client_id'] = campaign_client_id($row['campaign_id']); $row['ad_account_id'] = (int)q_val('SELECT ad_account_id FROM campaigns WHERE id = ?', [$row['campaign_id']]); }
    elseif ($row['ad_account_id']) $row['client_id'] = account_client_id($row['ad_account_id']);
    if ($row['client_id'] && !can_client($row['client_id'])) throw new Forbidden('client');
    if (!$row['client_id'] && !is_admin()) throw new InvalidArgumentException('اختار عميل');
    if ($id) { db_update('alert_rules', $id, $row + ['updated_at' => now()]); audit('alert_rule.update', 'alert_rule', $id); return $id; }
    $id = db_insert('alert_rules', $row + ['created_at' => now()]);
    audit('alert_rule.create', 'alert_rule', $id, ['metric' => $metric]);
    return $id;
}

/** Campaigns a rule applies to. Rules without a client apply to all live (non-demo) clients plus demo when run in demo context. */
function rule_campaigns(array $rule): array {
    if ($rule['campaign_id']) return q_all('SELECT cp.*, a.client_id, a.currency FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE cp.id = ?', [$rule['campaign_id']]);
    if ($rule['ad_account_id']) return q_all("SELECT cp.*, a.client_id, a.currency FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE a.id = ? AND a.status = 'active'", [$rule['ad_account_id']]);
    if ($rule['client_id']) return q_all("SELECT cp.*, a.client_id, a.currency FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE a.client_id = ? AND a.status = 'active'", [$rule['client_id']]);
    return q_all("SELECT cp.*, a.client_id, a.currency FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id JOIN clients c ON c.id = a.client_id WHERE a.status = 'active' AND c.status = 'active'");
}

/**
 * Evaluate one rule. Returns a log of what happened per campaign: fired / below-min-data / ok / cooldown.
 * $asOf pins "today" for tests.
 */
function evaluate_rule(array $rule, ?string $asOf = null): array {
    $asOf ??= today();
    $from = (new DateTimeImmutable($asOf))->modify('-' . ((int)$rule['window_days'] - 1) . ' days')->format('Y-m-d');
    $log = [];
    foreach (rule_campaigns($rule) as $cp) {
        $key = 'rule:' . $rule['id'] . ':c' . $cp['id'];
        if ($rule['metric'] === 'delivery') {
            $st = strtoupper((string)($cp['effective_status'] ?: ''));
            if ($st === '') { $log[] = [$cp['id'], 'no_data', 'حالة التسليم مش متاحة (محتاجة ربط Meta)']; continue; }
            if (!in_array($st, ['DISAPPROVED', 'WITH_ISSUES', 'PENDING_BILLING_INFO', 'ADSET_PAUSED', 'CAMPAIGN_PAUSED', 'IN_PROCESS', 'PENDING_REVIEW'], true) && !str_contains($st, 'ERROR')) { $log[] = [$cp['id'], 'ok', $st]; continue; }
            if (in_array($st, ['CAMPAIGN_PAUSED', 'ADSET_PAUSED'], true) && strtoupper((string)$cp['status']) === 'PAUSED') { $log[] = [$cp['id'], 'ok', 'متوقفة يدوي']; continue; }
            $fired = fire_alert($rule, $cp, $key, null, "حالة الحملة «{$cp['name']}»: $st", ['effective_status' => $st]);
            $log[] = [$cp['id'], $fired ? 'fired' : 'cooldown', $st];
            continue;
        }
        $rows = perf_rows([(int)$cp['client_id']], $from, $asOf, ['campaign' => (int)$cp['id']]);
        $m = derive(sum_metrics(pick_level_rows($rows, 'coarsest')));
        if (!$rows) { $log[] = [$cp['id'], 'no_data', 'مفيش بيانات في آخر ' . $rule['window_days'] . ' يوم']; continue; }
        $goal = goal_of($cp['objective'], $m['result_type']);
        $resKey = $goal === 'sales' ? 'purchases' : ($goal === 'leads' ? 'leads' : 'results');
        // minimum data before judging — avoids hasty decisions on tiny samples
        if (($m['spend'] ?? 0) < (float)$rule['min_spend'] || ($m['impressions'] ?? 0) < (int)$rule['min_impressions']
            || ((float)$rule['min_results'] > 0 && ($m[$resKey] ?? 0) < (float)$rule['min_results'])) {
            $log[] = [$cp['id'], 'below_min', 'البيانات أقل من الحد الأدنى (صرف ' . round((float)($m['spend'] ?? 0)) . ')']; continue;
        }
        if ($rule['metric'] === 'spend_no_results') {
            $v = ($m[$resKey] ?? 0) == 0 ? (float)$m['spend'] : 0.0;
            $hit = $v > (float)$rule['threshold'];
            $msg = "«{$cp['name']}» صرفت " . number_format($v) . " {$cp['currency']} في آخر {$rule['window_days']} يوم من غير أي " . metric_label($resKey);
        } else {
            $v = $m[$rule['metric']] ?? null;
            if ($v === null) { $log[] = [$cp['id'], 'no_data', metric_label($rule['metric']) . ' غير متاح للحملة دي']; continue; }
            $hit = $rule['operator'] === 'lt' ? $v < (float)$rule['threshold'] : $v > (float)$rule['threshold'];
            $fmt = fn($x) => in_array($rule['metric'], PCT_METRICS, true) ? number_format($x * 100, 2) . '%' : number_format((float)$x, 2);
            $msg = metric_label($rule['metric']) . " لـ «{$cp['name']}» = " . $fmt($v) . ($rule['operator'] === 'lt' ? ' أقل من ' : ' أعلى من ') . 'الحد ' . $fmt((float)$rule['threshold']) . " (آخر {$rule['window_days']} يوم)";
        }
        if (!$hit) { $log[] = [$cp['id'], 'ok', '']; continue; }
        $evidence = ['window' => [$from, $asOf], 'currency' => $cp['currency'], 'value' => $v, 'threshold' => (float)$rule['threshold']]
            + array_intersect_key($m, array_flip(['spend', 'impressions', 'link_clicks', 'leads', 'purchases', 'results', 'cpl', 'cpa', 'link_ctr', 'cpm', 'link_cpc', 'roas']));
        $fired = fire_alert($rule, $cp, $key, (float)$v, $msg, $evidence);
        $log[] = [$cp['id'], $fired ? 'fired' : 'cooldown', $msg];
    }
    q('UPDATE alert_rules SET last_evaluated_at = ? WHERE id = ?', [now(), $rule['id']]);
    return $log;
}

/** Create the alert unless the same key alerted within the cool-down. Returns true when a new alert was created. */
function fire_alert(array $rule, array $cp, string $key, ?float $value, string $msg, array $evidence): bool {
    $since = gmdate('Y-m-d H:i:s', time() - (int)$rule['cooldown_hours'] * 3600);
    if (q_val('SELECT 1 FROM alerts WHERE dedupe_key = ? AND (created_at >= ? OR status = \'open\')', [$key, $since])) return false;
    $id = db_insert('alerts', ['rule_id' => $rule['id'], 'client_id' => $cp['client_id'], 'campaign_id' => $cp['id'], 'kind' => 'rule', 'severity' => $rule['severity'],
        'title' => $rule['name'] . ' — ' . $cp['name'], 'message' => $msg, 'metric_value' => $value, 'threshold' => (float)$rule['threshold'],
        'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE), 'dedupe_key' => $key, 'status' => 'open', 'created_at' => now()]);
    alert_dispatch($id, $rule['channel']);
    emit_event('alert.created', (int)$cp['client_id'], (string)$id, ['alert_id' => $id, 'severity' => $rule['severity'], 'campaign_id' => (int)$cp['id'], 'metric' => $rule['metric']]);
    return true;
}

/** Deliver an alert: in-app notification always; e-mail when chosen and the server can send mail. */
function alert_dispatch(int $alertId, string $channel): void {
    $a = q_one('SELECT * FROM alerts WHERE id = ?', [$alertId]);
    foreach (client_audience((int)$a['client_id'], 'alerts.view') as $u) {
        notify((int)$u['id'], $a['title'], (string)$a['message'], '?r=alerts&view=' . $alertId, $a['severity']);
        if ($channel === 'email' && ($from = setting('mail_from'))) {
            $ok = @mail($u['email'], '=?UTF-8?B?' . base64_encode('[تنبيه] ' . $a['title']) . '?=', (string)$a['message'] . "\n\n— Media Buying Command Center",
                "From: $from\r\nContent-Type: text/plain; charset=UTF-8");
            if (!$ok) log_error('alert.email', 'mail() failed for alert ' . $alertId);
        }
    }
}

/** Budget pacing alerts (kind = budget), one per budget per day at most. Emits budget.threshold for automations. */
function check_budget_alerts(?string $asOf = null): int {
    $asOf ??= today(); $n = 0;
    foreach (q_all('SELECT b.* FROM budgets b WHERE b.period_start <= ? AND b.period_end >= ?', [$asOf, $asOf]) as $b) {
        $s = budget_status($b, $asOf);
        if (!$s['alerts']) continue;
        $key = 'budget:' . $b['id'] . ':' . $asOf;
        if (q_val('SELECT 1 FROM alerts WHERE dedupe_key = ?', [$key])) continue;
        [$sev, $msg] = $s['alerts'][0];
        $id = db_insert('alerts', ['client_id' => $b['client_id'], 'campaign_id' => $b['campaign_id'], 'kind' => 'budget', 'severity' => $sev, 'title' => 'ميزانية: ' . $b['name'],
            'message' => implode(' · ', array_column($s['alerts'], 1)), 'metric_value' => $s['pct_used'], 'threshold' => (float)$b['alert_pct'] / 100,
            'evidence_json' => json_encode(['spent' => $s['spent'], 'amount' => (float)$b['amount'], 'currency' => $b['currency'], 'projected' => $s['projected_spend']], JSON_UNESCAPED_UNICODE),
            'dedupe_key' => $key, 'status' => 'open', 'created_at' => now()]);
        alert_dispatch($id, 'in_app');
        emit_event('budget.threshold', (int)$b['client_id'], (string)$b['id'], ['budget_id' => (int)$b['id'], 'pct_used' => $s['pct_used'], 'alert_id' => $id]);
        $n++;
    }
    return $n;
}

function run_watchdog(?string $asOf = null): array {
    $out = ['rules' => 0, 'fired' => 0, 'budget' => 0];
    foreach (q_all('SELECT * FROM alert_rules WHERE active = 1') as $r) {
        $out['rules']++;
        foreach (evaluate_rule($r, $asOf) as $l) if ($l[1] === 'fired') $out['fired']++;
    }
    $out['budget'] = check_budget_alerts($asOf);
    return $out;
}

/** Ready-made rules so a new client is monitored from day one. */
function default_alert_rules(int $clientId): void {
    $c = q_one('SELECT * FROM clients WHERE id = ?', [$clientId]);
    $scale = $c['currency'] === 'EGP' ? 1 : 0.05;
    foreach ([
        ['name' => 'CTR منخفض', 'metric' => 'link_ctr', 'operator' => 'lt', 'threshold' => 0.006, 'window_days' => 3, 'min_impressions' => 5000, 'severity' => 'warning'],
        ['name' => 'صرف من غير نتايج', 'metric' => 'spend_no_results', 'operator' => 'gt', 'threshold' => round(500 * $scale), 'window_days' => 2, 'min_spend' => round(500 * $scale), 'severity' => 'critical'],
        ['name' => 'Frequency عالي', 'metric' => 'frequency', 'operator' => 'gt', 'threshold' => 4, 'window_days' => 7, 'min_impressions' => 10000, 'severity' => 'info'],
        ['name' => 'مشكلة في التسليم', 'metric' => 'delivery', 'operator' => 'gt', 'threshold' => 0, 'window_days' => 1, 'severity' => 'critical', 'cooldown_hours' => 12],
    ] as $r) alert_rule_save(null, $r + ['client_id' => $clientId, 'active' => 1, 'cooldown_hours' => $r['cooldown_hours'] ?? 24, 'channel' => 'in_app']);
}
