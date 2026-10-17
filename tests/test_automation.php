<?php
// Phase 6 — automation hub, auto-reply bridge, profit tracker, copy generator

function _auto_setup(): array {
    $owner = fresh();
    $c = client_create(['name' => 'Auto Co', 'currency' => 'EGP']);
    $s1 = create_user('Sales1', 's1@x.co', 'password123', 'sales', [$c]);
    $s2 = create_user('Sales2', 's2@x.co', 'password123', 'sales', [$c]);
    return [$owner, $c, $s1, $s2];
}

function test_workflow_new_lead_assigns_and_notifies_once() {
    [, $c, $s1, $s2] = _auto_setup();
    $t = wf_templates()['new_lead'];
    $wid = wf_save(null, $t + ['client_id' => $c, 'active' => 1]);
    $l1 = lead_create($c, ['full_name' => 'A', 'phone' => '01000000001'])['id'];
    $l2 = lead_create($c, ['full_name' => 'B', 'phone' => '01000000002'])['id'];
    $st = process_automation();
    eq($st['events'], 2); eq($st['runs'], 2);
    $owners = [(int)q_val('SELECT owner_user_id FROM leads WHERE id = ?', [$l1]), (int)q_val('SELECT owner_user_id FROM leads WHERE id = ?', [$l2])];
    sort($owners); eq($owners, [$s1, $s2], 'round robin across the two sales users');
    ok(q_val('SELECT next_follow_up_at FROM leads WHERE id = ?', [$l1]) !== null, 'follow-up scheduled');
    eq((int)q_val("SELECT COUNT(*) FROM workflow_executions WHERE status = 'success'"), 2);
    // processing again does nothing (events consumed, executions unique)
    eq(process_automation()['runs'], 0);
    $ev = q_one("SELECT * FROM events WHERE type = 'lead.created' LIMIT 1");
    wf_execute(q_one('SELECT * FROM automation_workflows WHERE id = ?', [$wid]), wf_context($ev), 'event:' . $ev['id'], (int)$ev['id']);
    eq((int)q_val('SELECT COUNT(*) FROM workflow_executions'), 2, 'same event never runs twice');
    eq((int)q_val("SELECT COUNT(*) FROM lead_assignments"), 2, 'no double assignment');
}

function test_workflow_conditions_skip() {
    [, $c] = _auto_setup();
    wf_save(null, ['name' => 'Only meta', 'client_id' => $c, 'trigger_type' => 'lead.created', 'active' => 1,
        'conditions' => [['field' => 'source', 'op' => 'eq', 'value' => 'meta_form']], 'actions' => [['type' => 'add_lead_note', 'params' => ['text' => 'from meta']]]]);
    lead_create($c, ['full_name' => 'Manual']);
    process_automation();
    eq(q_val('SELECT status FROM workflow_executions'), 'skipped');
    eq((int)q_val("SELECT COUNT(*) FROM lead_activities WHERE type = 'automation'"), 0);
    lead_create($c, ['full_name' => 'Meta', 'external_id' => 'x1'], 'meta_form');
    process_automation();
    eq((int)q_val("SELECT COUNT(*) FROM lead_activities WHERE type = 'automation'"), 1);
}

function test_workflow_failure_retry_then_fail_without_repeating_done_steps() {
    [, $c] = _auto_setup();
    $wid = wf_save(null, ['name' => 'Hook', 'client_id' => $c, 'trigger_type' => 'lead.created', 'active' => 1, 'max_retries' => 2,
        'actions' => [['type' => 'add_lead_note', 'params' => ['text' => 'step1']], ['type' => 'webhook', 'params' => ['url' => 'https://example.test/hook']]]]);
    lead_create($c, ['full_name' => 'W']);
    process_automation();
    $x = q_one('SELECT * FROM workflow_executions');
    eq($x['status'], 'retrying'); ok(str_contains($x['error'], 'موافقة'), 'webhook blocked until an admin approves');
    // admin approves; endpoint fails → retries with backoff, step1 is not repeated
    wf_save($wid, ['name' => 'Hook', 'client_id' => $c, 'trigger_type' => 'lead.created', 'active' => 1, 'max_retries' => 2, 'approve_external' => 1, 'approve_external_set' => 1,
        'actions' => [['type' => 'add_lead_note', 'params' => ['text' => 'step1']], ['type' => 'webhook', 'params' => ['url' => 'https://example.test/hook']]]]);
    $GLOBALS['WF_HTTP'] = fn($u, $b) => 500;
    q("UPDATE workflow_executions SET next_retry_at = '2000-01-01 00:00:00'");
    process_automation();
    eq(q_val('SELECT status FROM workflow_executions'), 'retrying'); eq((int)q_val('SELECT attempts FROM workflow_executions'), 2);
    q("UPDATE workflow_executions SET next_retry_at = '2000-01-01 00:00:00'");
    process_automation();
    eq(q_val('SELECT status FROM workflow_executions'), 'failed', 'gives up after max retries');
    eq((int)q_val("SELECT COUNT(*) FROM lead_activities WHERE type = 'automation'"), 1, 'successful step not repeated on retry');
    ok((int)q_val("SELECT COUNT(*) FROM notifications WHERE title LIKE 'Workflow فشل%'") >= 1, 'admins told about the failure');
    // recovery path: endpoint OK → success
    lead_create($c, ['full_name' => 'W2']);
    $GLOBALS['WF_HTTP'] = fn($u, $b) => 204;
    process_automation();
    eq((int)q_val("SELECT COUNT(*) FROM workflow_executions WHERE status = 'success'"), 1);
    unset($GLOBALS['WF_HTTP']);
    throws(fn() => wf_save(null, ['name' => 'bad', 'client_id' => $c, 'trigger_type' => 'lead.created', 'actions' => [['type' => 'webhook', 'params' => ['url' => 'http://insecure']]]]), InvalidArgumentException::class, 'https only');
}

function test_overdue_escalation_and_schedule_report() {
    [, $c, $s1] = _auto_setup();
    wf_save(null, wf_templates()['overdue'] + ['client_id' => $c, 'active' => 1]);
    $l = lead_create($c, ['full_name' => 'Late'])['id']; lead_assign($l, $s1);
    q('UPDATE leads SET next_follow_up_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 30 * 3600), $l]);
    q("UPDATE events SET processed_at = ?", [now()]);   // ignore the lead.created event here
    scan_overdue_leads(); process_automation();
    ok((int)q_val("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'عندك متابعة متأخرة'", [$s1]) === 1, 'owner reminded');
    ok((int)q_val("SELECT COUNT(*) FROM notifications WHERE title LIKE 'تصعيد%'") >= 1, 'escalated after 24h');
    // schedule: weekly on Thursday 18:00 Cairo
    $wid = wf_save(null, wf_templates()['weekly_report'] + ['client_id' => $c, 'active' => 1]);
    $thu = (new DateTimeImmutable('2026-10-15 19:00', new DateTimeZone('Africa/Cairo')))->getTimestamp();
    $wed = (new DateTimeImmutable('2026-10-14 19:00', new DateTimeZone('Africa/Cairo')))->getTimestamp();
    eq(wf_schedule_slot(['freq' => 'weekly', 'weekday' => 4, 'hour' => 18], $wed), null);
    ok(wf_schedule_slot(['freq' => 'weekly', 'weekday' => 4, 'hour' => 18], $thu) !== null);
    process_automation(200, $thu); process_automation(200, $thu + 600);
    eq((int)q_val('SELECT COUNT(*) FROM reports'), 1, 'one report per weekly slot');
    eq(q_val('SELECT status FROM workflow_executions WHERE workflow_id = ?', [$wid]), 'success');
    ok((int)q_val("SELECT COUNT(*) FROM notifications WHERE title = 'التقرير الأسبوعي جاهز' AND link LIKE '?r=report&id=%'") >= 1, 'notification links to the new report');
}

function test_won_and_budget_workflows() {
    [, $c] = _auto_setup();
    wf_save(null, wf_templates()['won'] + ['client_id' => $c, 'active' => 1]);
    wf_save(null, wf_templates()['budget'] + ['client_id' => $c, 'active' => 1]);
    $l = lead_create($c, ['full_name' => 'Deal'])['id'];
    lead_set_stage($l, (int)stage_by_flag('is_won')['id'], ['deal_value' => '5000']);
    emit_event('budget.threshold', $c, 'b1', ['budget_id' => 1, 'pct_used' => 0.95]);
    process_automation();
    eq((int)q_val("SELECT COUNT(*) FROM lead_activities WHERE lead_id = ? AND type = 'automation'", [$l]), 1);
    ok((int)q_val("SELECT COUNT(*) FROM notifications WHERE title LIKE 'صفقة جديدة%'") >= 1);
    ok((int)q_val("SELECT COUNT(*) FROM notifications WHERE title LIKE 'ميزانية قربت%'") >= 1);
}

function _fake_autoreply_db(): string {
    $p = $GLOBALS['__tmp'] . '/ar_' . mt_rand() . '.sqlite';
    $db = new PDO('sqlite:' . $p);
    // same schema as Gano Auto Reply's lib.php
    $db->exec("CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT);
      CREATE TABLE pages (id TEXT PRIMARY KEY, name TEXT, token TEXT, active INTEGER DEFAULT 0, picture TEXT, updated_at TEXT);
      CREATE TABLE rules (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id TEXT, post_id TEXT, post_label TEXT, name TEXT, active INTEGER DEFAULT 1, match_mode TEXT DEFAULT 'all', keywords TEXT, send_comment INTEGER DEFAULT 1, comment_replies TEXT, send_dm INTEGER DEFAULT 1, dm_messages TEXT, created_at TEXT);
      CREATE TABLE log (comment_id TEXT PRIMARY KEY, page_id TEXT, post_id TEXT, rule_id INTEGER, from_name TEXT, message TEXT, comment_status TEXT, dm_status TEXT, error TEXT, created_at TEXT, from_id TEXT, comment_ts INTEGER, attempts INTEGER DEFAULT 0, next_retry TEXT, psid TEXT, images_status TEXT);");
    $db->exec("INSERT INTO settings VALUES ('worker_seen', '" . gmdate('Y-m-d H:i:s') . "'), ('last_webhook', '" . gmdate('Y-m-d H:i:s') . "')");
    $db->exec("INSERT INTO pages VALUES ('111', 'Hourya Mall', 'SECRET_PAGE_TOKEN', 1, '', '2026-10-01'), ('222', 'Other Page', 'SECRET2', 0, '', '2026-10-01')");
    $db->exec("INSERT INTO rules (page_id, name, active) VALUES ('111', 'سعر', 1)");
    $now = gmdate('Y-m-d H:i:s');
    $db->exec("INSERT INTO log (comment_id, page_id, rule_id, from_name, message, comment_status, dm_status, error, created_at) VALUES
      ('c1', '111', 1, 'Ali', 'بكام؟', 'sent', 'sent', '', '$now'), ('c2', '111', 1, 'Mona', 'السعر؟', 'failed', 'closed', 'كومنت: error', '$now'), ('c3', '111', 0, 'Spam', 'wa.me/1', 'hidden', 'skipped', 'محظور', '$now')");
    return $p;
}

function test_autoreply_bridge_read_only_mapping_and_failure_events() {
    [, $c] = _auto_setup();
    $path = _fake_autoreply_db(); $before = md5_file($path);
    set_setting('autoreply_db', $path); ar_reset();
    $st = ar_status();
    ok($st['connected'] && $st['worker_ok']); eq($st['pages'], 2); eq($st['active_pages'], 1); eq($st['rules'], 1);
    $pages = array_column(ar_pages(), null, 'id');
    eq((int)$pages['111']['stats']['total'], 3); eq((int)$pages['111']['stats']['failed'], 1); eq((int)$pages['111']['stats']['blocked'], 1);
    ok(!str_contains(json_encode(ar_pages()), 'SECRET'), 'page tokens are never read');
    ar_map_page('111', $c);
    eq(count(autoreply_pages_for_client($c)), 1);
    [$rows, $total] = ar_log(['111'], ['status' => 'failed']); eq($total, 1); eq($rows[0]['from_name'], 'Mona');
    set_setting('ar_last_scan', gmdate('Y-m-d H:i:s', time() - 60));
    eq(ar_scan_failures(), 1); eq(ar_scan_failures(), 0, 'no event flood');
    eq((int)q_val("SELECT client_id FROM events WHERE type = 'autoreply.failed'"), $c, 'failure routed to the mapped client');
    throws(fn() => ar_db()->exec("UPDATE pages SET name = 'x'"), PDOException::class, 'connection is read-only');
    clearstatcache(); eq(md5_file($path), $before, 'auto-reply database untouched');
    set_setting('autoreply_db', '/nope/missing.sqlite'); ar_reset();
    eq(ar_status()['connected'], false, 'missing tool handled gracefully');
}

function test_finance_profit_excludes_ad_spend() {
    [, $c] = _auto_setup();
    $acc = account_save(null, $c, ['name' => 'A', 'currency' => 'EGP']);
    import_performance($acc, [['Campaign name', 'Day', 'Amount spent (EGP)'], ['X', '2026-10-05', '99999']]);
    $sid = service_save(null, ['client_id' => $c, 'name' => 'Retainer', 'fee_type' => 'monthly', 'amount' => '10000', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'billing_day' => 1, 'active' => 1]);
    eq(invoices_generate_monthly('2026-10-02'), 1); eq(invoices_generate_monthly('2026-10-20'), 0, 'one invoice per service per month');
    $inv = (int)q_val('SELECT id FROM client_invoices');
    throws(fn() => invoice_pay($inv, 20000), InvalidArgumentException::class, 'overpayment');
    invoice_pay($inv, 4000, '2026-10-05');
    eq(q_val('SELECT status FROM client_invoices WHERE id = ?', [$inv]), 'partial');
    expense_save(['category' => 'tools', 'amount' => '1500', 'currency' => 'EGP', 'date' => '2026-10-03']);
    expense_save(['category' => 'content', 'amount' => '500', 'currency' => 'EGP', 'date' => '2026-10-04', 'client_id' => $c]);
    $s = finance_summary([$c], '2026-10-01', '2026-10-31')['EGP'];
    eq($s['invoiced'], 10000.0); eq($s['collected'], 4000.0); eq($s['expenses'], 2000.0);
    eq($s['profit'], 8000.0, 'ad spend (99,999) never counted as revenue'); eq($s['cash_profit'], 2000.0); eq($s['outstanding'], 6000.0);
    $bc = finance_by_client([$c], '2026-10-01', '2026-10-31')[0];
    eq($bc['expenses'], 500.0, 'general expenses not allocated to the client'); eq($bc['profit'], 9500.0);
    invoice_pay($inv, 6000);
    eq(q_val('SELECT status FROM client_invoices WHERE id = ?', [$inv]), 'paid');
    eq(invoice_status(['status' => 'unpaid', 'due_date' => '2026-10-01'], '2026-10-10'), 'overdue');
    $id2 = invoice_create(['client_id' => $c, 'amount' => '300', 'currency' => 'EGP', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-02']);
    eq(scan_due_invoices('2026-10-10'), 1); eq(scan_due_invoices('2026-10-10'), 0);
    throws(fn() => service_save(null, ['client_id' => $c, 'name' => 'x', 'amount' => '0', 'start_date' => '2026-10-01']), InvalidArgumentException::class);
}

function test_copy_generator_uses_only_given_facts() {
    [, $c] = _auto_setup();
    $d = copy_inputs(['product' => 'شقق الساحل', 'usps' => "تقسيط 8 سنين\nاستلام فوري", 'audience' => 'موظفين في القاهرة', 'objective' => 'leads', 'lang' => 'eg']);
    $o = copy_generate_templates($d);
    foreach (['hooks', 'primary_text', 'headlines', 'descriptions', 'ctas', 'reels', 'scripts', 'ugc', 'angles', 'ab_tests'] as $k) ok(!empty($o[$k]), "section $k");
    $all = json_encode($o, JSON_UNESCAPED_UNICODE);
    ok(str_contains($all, 'تقسيط 8 سنين'), 'uses the given USP');
    ok(!str_contains($all, 'عرض لفترة محدودة') && !str_contains($all, 'محدود'), 'no offer / scarcity invented when none was given');
    ok(!preg_match('/\d+\s*%/', implode(' ', $o['hooks'])), 'no invented percentages');
    foreach ($o['headlines'] as $hl) ok(mb_strlen($hl) <= 40, 'headline length');
    $o2 = copy_generate_templates(copy_inputs(['product' => 'Perfume X', 'offer' => '20% off this week', 'lang' => 'en', 'objective' => 'sales']));
    ok(str_contains(json_encode($o2), '20% off this week'), 'offer used when provided');
    throws(fn() => copy_inputs(['product' => '']), InvalidArgumentException::class);
    $id = idea_save($c, $d, $o, 'template');
    idea_update($id, ['status' => 'winner', 'test_notes' => 'CTR 1.9%']);
    eq(q_val('SELECT status FROM creative_ideas WHERE id = ?', [$id]), 'winner');
    $other = client_create(['name' => 'Other']); $acc = account_save(null, $other, ['name' => 'o', 'currency' => 'EGP']);
    $cr = db_insert('creative_assets', ['client_id' => $other, 'name' => 'x', 'created_at' => now()]);
    throws(fn() => idea_update($id, ['creative_asset_id' => $cr]), InvalidArgumentException::class, 'cannot link another client creative');
}
