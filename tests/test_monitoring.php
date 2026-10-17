<?php
// Phase 5 — watchdog alerts (min data, cooldown, no automatic changes) and reports (content, exports)

function _mon_setup(): array {
    $owner = fresh();
    $c = client_create(['name' => 'Mon', 'currency' => 'EGP']);
    $acc = account_save(null, $c, ['name' => 'A', 'currency' => 'EGP']);
    $csv = [['Campaign name', 'Day', 'Objective', 'Amount spent (EGP)', 'Impressions', 'Link clicks', 'Leads']];
    for ($d = 1; $d <= 7; $d++) {
        $csv[] = ['Expensive', sprintf('2026-10-%02d', $d), 'OUTCOME_LEADS', '1000', '20000', '200', '2'];   // CPL 500
        $csv[] = ['Cheap', sprintf('2026-10-%02d', $d), 'OUTCOME_LEADS', '1000', '20000', '300', '20'];      // CPL 50
        $csv[] = ['Tiny', sprintf('2026-10-%02d', $d), 'OUTCOME_LEADS', '10', '200', '1', '0'];
    }
    import_performance($acc, $csv);
    return [$owner, $c, $acc];
}

function test_rule_fires_only_above_threshold_with_enough_data() {
    [, $c] = _mon_setup();
    $rid = alert_rule_save(null, ['client_id' => $c, 'metric' => 'cpl', 'operator' => 'gt', 'threshold' => '200', 'window_days' => 3, 'min_spend' => '500', 'min_results' => '3', 'severity' => 'critical', 'active' => 1, 'cooldown_hours' => 24]);
    $rule = q_one('SELECT * FROM alert_rules WHERE id = ?', [$rid]);
    $log = array_column(array_map(fn($l) => [q_val('SELECT name FROM campaigns WHERE id = ?', [$l[0]]), $l[1]], evaluate_rule($rule, '2026-10-07')), 1, 0);
    eq($log['Expensive'], 'fired'); eq($log['Cheap'], 'ok'); eq($log['Tiny'], 'below_min', 'tiny sample never alerts');
    $a = q_one('SELECT * FROM alerts');
    eq((float)$a['metric_value'], 500.0); eq($a['severity'], 'critical');
    $ev = json_decode($a['evidence_json'], true);
    eq($ev['window'], ['2026-10-05', '2026-10-07'], 'evidence keeps the evaluated window'); eq((float)$ev['spend'], 3000.0);
    eq((int)q_val("SELECT COUNT(*) FROM events WHERE type = 'alert.created'"), 1);
    ok((int)q_val('SELECT COUNT(*) FROM notifications') >= 1, 'in-app notification sent');
}

function test_cooldown_and_open_alert_prevent_duplicates() {
    [, $c] = _mon_setup();
    $rid = alert_rule_save(null, ['client_id' => $c, 'metric' => 'cpl', 'operator' => 'gt', 'threshold' => '200', 'window_days' => 3, 'active' => 1, 'cooldown_hours' => 24]);
    $rule = q_one('SELECT * FROM alert_rules WHERE id = ?', [$rid]);
    evaluate_rule($rule, '2026-10-07'); evaluate_rule($rule, '2026-10-07');
    eq((int)q_val('SELECT COUNT(*) FROM alerts'), 1, 'second run inside cooldown');
    q("UPDATE alerts SET status = 'resolved', created_at = ?", [gmdate('Y-m-d H:i:s', time() - 25 * 3600)]);
    evaluate_rule($rule, '2026-10-07');
    eq((int)q_val('SELECT COUNT(*) FROM alerts'), 2, 'fires again after cooldown once resolved');
    q("UPDATE alerts SET created_at = ?", [gmdate('Y-m-d H:i:s', time() - 48 * 3600)]);
    evaluate_rule($rule, '2026-10-07');
    eq((int)q_val('SELECT COUNT(*) FROM alerts'), 2, 'still-open alert blocks a duplicate even after cooldown');
}

function test_ctr_percent_threshold_and_spend_without_results() {
    [, $c] = _mon_setup();
    $rid = alert_rule_save(null, ['client_id' => $c, 'metric' => 'link_ctr', 'operator' => 'lt', 'threshold' => '1.2', 'window_days' => 7, 'active' => 1]);
    eq((float)q_val('SELECT threshold FROM alert_rules WHERE id = ?', [$rid]), 0.012, '1.2 typed as percent');
    $log = evaluate_rule(q_one('SELECT * FROM alert_rules WHERE id = ?', [$rid]), '2026-10-07');
    eq(count(array_filter($log, fn($l) => $l[1] === 'fired')), 2, 'Expensive 1.0% and Tiny 0.5% below 1.2%; Cheap 1.5% fine');
    $rid2 = alert_rule_save(null, ['client_id' => $c, 'metric' => 'spend_no_results', 'threshold' => '50', 'window_days' => 7, 'active' => 1]);
    $log2 = evaluate_rule(q_one('SELECT * FROM alert_rules WHERE id = ?', [$rid2]), '2026-10-07');
    eq(count(array_filter($log2, fn($l) => $l[1] === 'fired')), 1, 'Tiny spent 70 with zero leads');
}

function test_watchdog_never_changes_campaigns_or_budgets() {
    [, $c] = _mon_setup();
    $before = q_all('SELECT id, status, daily_budget, effective_status FROM campaigns ORDER BY id');
    alert_rule_save(null, ['client_id' => $c, 'metric' => 'cpl', 'operator' => 'gt', 'threshold' => '1', 'window_days' => 7, 'active' => 1]);
    run_watchdog('2026-10-07');
    eq(q_all('SELECT id, status, daily_budget, effective_status FROM campaigns ORDER BY id'), $before, 'monitoring only');
    ok((int)q_val('SELECT COUNT(*) FROM alerts') > 0);
    ok(!function_exists('meta_pause_campaign'), 'no pause capability exists in v1');
}

function test_delivery_rule_needs_status_data() {
    [, $c] = _mon_setup();
    $rid = alert_rule_save(null, ['client_id' => $c, 'metric' => 'delivery', 'window_days' => 1, 'active' => 1]);
    $rule = q_one('SELECT * FROM alert_rules WHERE id = ?', [$rid]);
    ok(!array_filter(evaluate_rule($rule), fn($l) => $l[1] === 'fired'), 'no status data → nothing claimed');
    q("UPDATE campaigns SET effective_status = 'DISAPPROVED', status = 'ACTIVE' WHERE name = 'Cheap'");
    eq(count(array_filter(evaluate_rule($rule), fn($l) => $l[1] === 'fired')), 1);
}

function test_budget_alert_once_per_day_and_event() {
    [, $c] = _mon_setup();
    budget_save(null, ['client_id' => $c, 'name' => 'Oct', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => '10000', 'currency' => 'EGP', 'alert_pct' => '80']);
    eq(check_budget_alerts('2026-10-07'), 1); eq(check_budget_alerts('2026-10-07'), 0, 'once per budget per day');
    eq((int)q_val("SELECT COUNT(*) FROM events WHERE type = 'budget.threshold'"), 1);
}

function test_alert_rule_scope_permissions() {
    [$owner, $c] = _mon_setup();
    $other = client_create(['name' => 'Other']);
    $buyer = q_one('SELECT * FROM users WHERE id = ?', [create_user('B', 'b@x.co', 'password123', 'buyer', [$c])]);
    as_user($buyer);
    throws(fn() => alert_rule_save(null, ['client_id' => $other, 'metric' => 'cpl', 'threshold' => '1']), Forbidden::class, 'not my client');
    throws(fn() => alert_rule_save(null, ['metric' => 'cpl', 'threshold' => '1']), InvalidArgumentException::class, 'non-admin needs a client');
    ok(alert_rule_save(null, ['client_id' => $c, 'metric' => 'cpl', 'threshold' => '1']) > 0);
}

function test_report_build_content_and_honest_summary() {
    [, $c] = _mon_setup();
    $camp = (int)q_val("SELECT id FROM campaigns WHERE name = 'Cheap'");
    for ($i = 0; $i < 6; $i++) { $id = lead_create($c, ['full_name' => "L$i", 'campaign_id' => $camp, 'received_at' => '2026-10-05 10:00:00'])['id']; if ($i < 2) lead_set_stage($id, (int)stage_by_flag('is_won')['id'], ['deal_value' => '3000']); }
    $d = report_build('weekly', $c, '2026-10-01', '2026-10-07');
    eq($d['totals']['EGP']['m']['spend'], 14070.0);
    eq($d['prev_from'], '2026-09-24'); eq($d['totals']['EGP']['prev'], null, 'no previous data → null, not zeros');
    eq(count($d['campaigns']), 3);
    $g = $d['goals'][0]; eq($g['goal'], 'leads'); eq($g['kpi'], 'cpl'); eq($g['best'][0]['name'], 'Cheap');
    ok($d['lead_quality']['total'] === 6 && $d['lead_quality']['won'] === 2);
    ok(str_contains($d['summary'], '14,070'), 'summary quotes real spend');
    ok(!preg_match('/(بسبب|due to|because)/u', $d['summary']), 'summary does not invent causes');
    ok(array_filter($d['issues'], fn($i) => str_contains($i, 'Tiny')), 'spend without leads flagged as issue');
    ok(array_filter($d['recommendations'], fn($r) => str_contains($r, 'Cheap') && str_contains($r, 'Expensive')), 'budget shift suggestion cites both campaigns');
    throws(fn() => report_build('campaign', $c, '2026-10-01', '2026-10-07'), InvalidArgumentException::class, 'campaign report needs a campaign');
    throws(fn() => report_build('weekly', $c, '2026-10-07', '2026-10-01'), InvalidArgumentException::class, 'reversed period');
    $cd = report_build('campaign', $c, '2026-10-01', '2026-10-07', $camp);
    eq(count($cd['campaigns']), 1);
}

function test_report_exports_pdf_xlsx_csv_with_arabic() {
    [, $c] = _mon_setup();
    $id = report_save(report_build('weekly', $c, '2026-10-01', '2026-10-07'));
    $r = q_one('SELECT * FROM reports WHERE id = ?', [$id]);
    $pdf = report_pdf($r);
    ok(str_starts_with($pdf, '%PDF') && strlen($pdf) > 5000, 'valid PDF');
    $tmp = $GLOBALS['__tmp'] . '/r.pdf'; file_put_contents($tmp, $pdf);
    $txt = (string)shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    if ($txt !== '') ok(str_contains($txt, '14,070') && preg_match('/\p{Arabic}/u', $txt), 'PDF text has numbers and Arabic');
    $rows = report_rows($r);
    $x = $GLOBALS['__tmp'] . '/r.xlsx'; xlsx_write($x, $rows);
    $back = xlsx_read($x);
    eq($back[1][1], 'Mon'); ok(count($back) > 15);
    ok(in_array('Summary', array_column($rows, 0), true));
    eq(report_default_period('weekly', '2026-10-18'), ['2026-10-11', '2026-10-17']);
    eq(report_default_period('monthly', '2026-10-18'), ['2026-09-01', '2026-09-30']);
}
