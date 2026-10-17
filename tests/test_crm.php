<?php
// Phase 4 — CRM, dedupe, assignment, permissions, lead quality

function _crm_setup(): array {
    $owner = fresh();
    $c = client_create(['name' => 'CRM Co', 'currency' => 'EGP']);
    $acc = account_save(null, $c, ['name' => 'A', 'currency' => 'EGP']);
    import_performance($acc, [['Campaign name', 'Ad set name', 'Ad name', 'Day', 'Objective', 'Amount spent (EGP)', 'Leads'],
        ['Good Camp', 'S1', 'Ad1', '2026-10-01', 'OUTCOME_LEADS', '1000', '10'], ['Cheap Camp', 'S2', 'Ad2', '2026-10-01', 'OUTCOME_LEADS', '1000', '20']]);
    return [$owner, $c, $acc];
}

function test_lead_create_validation_and_dedupe_flag() {
    [, $c] = _crm_setup();
    throws(fn() => lead_create($c, []), InvalidArgumentException::class, 'empty lead');
    throws(fn() => lead_create($c, ['full_name' => 'x', 'email' => 'bad']), InvalidArgumentException::class, 'bad email');
    $a = lead_create($c, ['full_name' => 'Ahmed', 'phone' => '0101 234 5678', 'campaign_name' => 'Good Camp']);
    ok($a['id'] > 0 && $a['duplicate_of'] === null);
    eq((int)q_val('SELECT campaign_id FROM leads WHERE id = ?', [$a['id']]), (int)q_val("SELECT id FROM campaigns WHERE name = 'Good Camp'"), 'campaign resolved by name');
    $b = lead_create($c, ['full_name' => 'Ahmed again', 'phone' => '+20 101 234 5678']);
    eq($b['duplicate_of'], $a['id'], 'same phone in another format detected as duplicate');
    eq((int)q_val("SELECT COUNT(*) FROM events WHERE type = 'lead.created'"), 1, 'duplicate does not trigger lead.created');
    $c2 = client_create(['name' => 'Other']);
    eq(lead_create($c2, ['phone' => '01012345678'])['duplicate_of'], null, 'dedupe is per client');
    // same external id from the same source is idempotent
    $x1 = lead_create($c, ['full_name' => 'Ext', 'external_id' => 'L1'], 'meta_form'); $x2 = lead_create($c, ['full_name' => 'Ext', 'external_id' => 'L1'], 'meta_form');
    eq($x2['id'], $x1['id']); ok($x2['existing']);
}

function test_dedupe_merge_mode_and_window() {
    [, $c] = _crm_setup();
    set_setting('dedupe_rules', json_encode(['phone' => true, 'email' => true, 'window_days' => 30, 'action' => 'merge']));
    $a = lead_create($c, ['full_name' => 'Old', 'email' => 'x@y.co', 'received_at' => gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
    $b = lead_create($c, ['full_name' => 'New', 'email' => 'X@Y.co']);
    ok($b['id'] > 0 && $b['duplicate_of'] === null, 'outside 30-day window → new lead');
    $m = lead_create($c, ['full_name' => 'Again', 'email' => 'x@y.co']);
    ok($m['merged'] && $m['id'] === null && $m['duplicate_of'] === $b['id'], 'merge mode: logged on original');
    eq((int)q_val("SELECT COUNT(*) FROM lead_activities WHERE lead_id = ? AND type = 'duplicate'", [$b['id']]), 1);
    set_setting('dedupe_rules', json_encode(['phone' => false, 'email' => false, 'window_days' => 0, 'action' => 'flag']));
    eq(lead_create($c, ['email' => 'x@y.co'])['duplicate_of'], null, 'rules can be switched off');
}

function test_stage_flow_timestamps_and_events() {
    [, $c] = _crm_setup();
    $id = lead_create($c, ['full_name' => 'Flow', 'phone' => '01111111111'])['id'];
    $st = array_column(stages(), 'id', 'name');
    lead_log($id, 'call', 'answered', 'كلمته');
    $l = q_one('SELECT * FROM leads WHERE id = ?', [$id]);
    ok($l['first_contact_at'] !== null, 'first contact recorded'); eq((int)$l['stage_id'], (int)$st['Contacted'], 'new → contacted after a call');
    lead_log($id, 'call', 'no_answer', 'مردش', '2026-10-20 10:00');
    ok(q_val('SELECT next_follow_up_at FROM leads WHERE id = ?', [$id]) !== null, 'follow-up saved');
    lead_set_stage($id, (int)$st['Qualified']);
    ok(q_val('SELECT qualified_at FROM leads WHERE id = ?', [$id]) !== null);
    throws(fn() => lead_set_stage($id, (int)$st['Won'], ['deal_value' => '-10']), InvalidArgumentException::class, 'negative deal');
    lead_set_stage($id, (int)$st['Won'], ['deal_value' => '250,000']);
    $l = q_one('SELECT * FROM leads WHERE id = ?', [$id]);
    eq((float)$l['deal_value'], 250000.0); eq($l['deal_currency'], 'EGP'); eq($l['next_follow_up_at'], null, 'won clears follow-up');
    eq((int)q_val("SELECT COUNT(*) FROM events WHERE type = 'lead.won'"), 1);
    ok((int)q_val("SELECT COUNT(*) FROM lead_activities WHERE lead_id = ? AND type = 'stage_change'", [$id]) >= 3, 'stage history kept');
    throws(fn() => lead_log($id, 'hack', null, 'x'), InvalidArgumentException::class);
}

function test_assignment_round_robin_and_access() {
    [$owner, $c] = _crm_setup();
    $s1 = create_user('S1', 's1@x.co', 'password123', 'sales', [$c]);
    $s2 = create_user('S2', 's2@x.co', 'password123', 'sales', [$c]);
    $out = create_user('S3', 's3@x.co', 'password123', 'sales', []);   // no access to this client
    $ids = []; for ($i = 0; $i < 4; $i++) $ids[] = lead_create($c, ['full_name' => "L$i", 'phone' => "0122222222$i"])['id'];
    foreach ($ids as $id) lead_assign($id, next_assignee($c));
    eq((int)q_val('SELECT COUNT(*) FROM leads WHERE owner_user_id = ?', [$s1]), 2, 'round robin balances');
    eq((int)q_val('SELECT COUNT(*) FROM leads WHERE owner_user_id = ?', [$s2]), 2);
    throws(fn() => lead_assign($ids[0], $out), InvalidArgumentException::class, 'cannot assign to a user without access');
    ok((int)q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$s1]) >= 2, 'assignee notified');
    lead_assign($ids[0], $s2);
    eq((int)q_val('SELECT COUNT(*) FROM lead_assignments WHERE lead_id = ?', [$ids[0]]), 2, 'assignment history');
    eq((int)q_val('SELECT COUNT(*) FROM lead_assignments WHERE lead_id = ? AND unassigned_at IS NULL', [$ids[0]]), 1);
    // sales users only see their own leads
    as_user(q_one('SELECT * FROM users WHERE id = ?', [$s1]));
    $mine = q_one('SELECT * FROM leads WHERE owner_user_id = ?', [$s1]); $theirs = q_one('SELECT * FROM leads WHERE owner_user_id = ?', [$s2]);
    ok(lead_visible($mine)); ok(!lead_visible($theirs), 'sales cannot see colleagues leads');
    [$w, $p] = leads_scope_sql();
    eq((int)q_val("SELECT COUNT(*) FROM leads l WHERE $w", $p), 1);
    as_user(q_one('SELECT * FROM users WHERE id = ?', [$out]));
    ok(!lead_visible($mine), 'user without client access cannot see lead');
}

function test_leads_import_meta_export() {
    [, $c] = _crm_setup();
    $rows = [['id', 'created_time', 'ad_name', 'campaign_name', 'form_name', 'full_name', 'phone_number', 'email', 'city'],
        ['l:1', '2026-10-01T10:00:00+0000', 'Ad1', 'Good Camp', 'Form A', 'منى سعيد', 'p:+201000000001', 'mona@x.co', 'Cairo'],
        ['l:2', '2026-10-01T11:00:00+0000', 'Ad2', 'Cheap Camp', 'Form A', 'Dup', 'p:01000000001', '', ''],
        ['l:3', '2026-10-01T12:00:00+0000', '', '', '', '', '', 'broken-email', ''],
        ['l:4', '2026-10-01T13:00:00+0000', 'Ad1', 'Good Camp', 'Form A', 'Karim', '01000000004', '', '']];
    $s = leads_import($c, $rows);
    eq($s['rows'], 4); eq($s['inserted'], 3); eq($s['duplicates'], 1); eq($s['skipped'], 1);
    $l = q_one("SELECT * FROM leads WHERE full_name = 'منى سعيد'");
    eq($l['phone_norm'], '201000000001', 'p: prefix stripped'); eq($l['received_at'], '2026-10-01 10:00:00');
    ok($l['ad_id'] !== null, 'ad resolved by name'); ok($l['adset_id'] !== null, 'adset inferred from ad');
    $s2 = leads_import($c, $rows);
    eq($s2['inserted'], 0, 're-import is idempotent'); eq($s2['existing'], 3);
}

function test_crm_stats_and_overdue_scan() {
    [, $c] = _crm_setup();
    $st = array_column(stages(), 'id', 'name');
    $ids = []; for ($i = 0; $i < 4; $i++) $ids[] = lead_create($c, ['full_name' => "S$i"])['id'];
    lead_set_stage($ids[0], (int)$st['Qualified']); lead_set_stage($ids[1], (int)$st['Won']); lead_set_stage($ids[2], (int)$st['Lost'], ['lost_reason' => 'السعر']);
    $s = crm_stats('l.client_id = ' . $c . ' AND l.duplicate_of IS NULL', []);
    eq($s['total'], 4); eq($s['qualified'], 2, 'won counts as qualified'); eq($s['won'], 1); eq($s['lost'], 1);
    eq($s['close_rate'], 0.5); eq($s['win_rate'], 0.25);
    q('UPDATE leads SET next_follow_up_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 7200), $ids[3]]);
    eq(scan_overdue_leads(), 1); eq(scan_overdue_leads(), 0, 'overdue event emitted once per due time');
}

function test_lead_quality_metrics_and_attribution() {
    [, $c] = _crm_setup();
    $st = array_column(stages(), 'id', 'name');
    $good = (int)q_val("SELECT id FROM campaigns WHERE name = 'Good Camp'"); $cheap = (int)q_val("SELECT id FROM campaigns WHERE name = 'Cheap Camp'");
    for ($i = 0; $i < 10; $i++) {
        $id = lead_create($c, ['full_name' => "G$i", 'campaign_id' => $good, 'received_at' => '2026-10-01 12:00:00'])['id'];
        if ($i < 6) lead_set_stage($id, (int)$st['Qualified']);
        if ($i < 2) lead_set_stage($id, (int)$st['Won'], ['deal_value' => '1500']);
    }
    for ($i = 0; $i < 20; $i++) {
        $id = lead_create($c, ['full_name' => "C$i", 'campaign_id' => $cheap, 'received_at' => '2026-10-01 12:00:00'])['id'];
        if ($i < 1) lead_set_stage($id, (int)$st['Qualified']);
    }
    lead_create($c, ['full_name' => 'Organic', 'received_at' => '2026-10-01 12:00:00']);
    $r = lead_quality([$c], 'campaign', '2026-10-01', '2026-10-01');
    $by = array_column($r['rows'], null, 'label');
    $g = $by['Good Camp']; $ch = $by['Cheap Camp'];
    eq($g['total'], 10); eq($g['qualified'], 6); eq($g['spend'], 1000.0); eq($g['cost_per_qualified'], 1000 / 6); eq($g['cost_per_sale'], 500.0);
    eq($g['roas'], 3.0, 'CRM revenue / spend'); eq($g['platform_leads'], 10.0);
    eq($ch['cpl_crm'], 50.0); ok($ch['low_quality'], 'cheap CPL but low qualified rate flagged');
    ok(!$g['low_quality']);
    ok(isset($by['غير منسوب (مفيش حملة على الليد)']), 'lead without campaign reported as unattributed');
    eq($r['total']['total'], 31);
    // revenue in another currency is never used for ROAS
    $id = lead_create($c, ['full_name' => 'USD deal', 'campaign_id' => $cheap, 'received_at' => '2026-10-01 12:00:00'])['id'];
    lead_set_stage($id, (int)$st['Won'], ['deal_value' => '100', 'deal_currency' => 'USD']);
    $r = array_column(lead_quality([$c], 'campaign', '2026-10-01', '2026-10-01')['rows'], null, 'label');
    eq($r['Cheap Camp']['roas'], null, 'USD revenue vs EGP spend → N/A');
}

function test_demo_crm_data_present() {
    fresh(); demo_seed(30); set_mode('demo');
    ok((int)q_val('SELECT COUNT(*) FROM leads') > 50);
    ok((int)q_val('SELECT COUNT(*) FROM leads WHERE duplicate_of IS NOT NULL') >= 1, 'demo has some duplicates');
    eq((int)q_val('SELECT COUNT(*) FROM events'), 0, 'demo seeding does not leave automation events');
    $r = lead_quality(scope_client_ids(), 'campaign', (new DateTimeImmutable(today()))->modify('-29 days')->format('Y-m-d'), today());
    ok(count(array_filter($r['rows'], fn($x) => $x['low_quality'])) >= 1, 'demo shows a low-quality source');
}
