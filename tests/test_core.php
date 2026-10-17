<?php
// Phase 2 — core platform: migrations, auth, roles, scoping, clients, accounts, crypto

function test_migrations_create_schema_and_defaults() {
    fresh();
    $tables = q_col("SELECT name FROM sqlite_master WHERE type = 'table'");
    foreach (['users', 'roles', 'clients', 'ad_accounts', 'integrations', 'campaigns', 'adsets', 'ads', 'creative_assets',
        'performance_snapshots', 'leads', 'lead_activities', 'lead_assignments', 'budgets', 'alerts', 'alert_rules', 'reports',
        'creative_ideas', 'audience_analyses', 'client_invoices', 'expenses', 'automation_workflows', 'workflow_executions', 'audit_logs'] as $t)
        ok(in_array($t, $tables, true), "missing table $t");
    eq((int)q_val('SELECT COUNT(*) FROM roles'), 5);
    eq((int)q_val('SELECT COUNT(*) FROM pipeline_stages'), 7);
    eq((int)q_val('SELECT COUNT(*) FROM pipeline_stages WHERE is_won = 1'), 1);
    // running migrate again is a no-op
    migrate(db());
    eq((int)q_val('SELECT COUNT(*) FROM schema_migrations'), count(migrations()));
    eq((int)q_val('SELECT COUNT(*) FROM pipeline_stages'), 7, 'seed not duplicated');
}

function test_password_policy_and_user_creation() {
    fresh();
    throws(fn() => create_user('A', 'a@b.co', 'short1', 'buyer'), InvalidArgumentException::class, 'short');
    throws(fn() => create_user('A', 'a@b.co', 'longbutnodigits', 'buyer'), InvalidArgumentException::class, 'no digits');
    throws(fn() => create_user('A', 'not-an-email', 'password123', 'buyer'), InvalidArgumentException::class, 'email');
    throws(fn() => create_user('A', 'a@b.co', 'password123', 'superuser'), InvalidArgumentException::class, 'role');
    $id = create_user('A', 'a@b.co', 'password123', 'buyer');
    ok($id > 0);
    throws(fn() => create_user('B', 'A@B.CO', 'password123', 'buyer'), InvalidArgumentException::class, 'duplicate email (case-insensitive)');
    $hash = q_val('SELECT password_hash FROM users WHERE id = ?', [$id]);
    ok(!str_contains($hash, 'password123') && password_verify('password123', $hash), 'password is hashed');
}

function test_login_success_failure_and_throttle_per_email_ip() {
    fresh();
    create_user('Sam', 'sam@test.local', 'password123', 'sales');
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    [$ok] = attempt_login('sam@test.local', 'wrongpass1'); ok(!$ok);
    [$ok] = attempt_login('nobody@test.local', 'password123'); ok(!$ok, 'unknown email');
    for ($i = 0; $i < 4; $i++) attempt_login('sam@test.local', 'wrongpass1');
    [$ok, $msg] = attempt_login('sam@test.local', 'password123');
    ok(!$ok && str_contains($msg, 'استنى'), 'locked after 5 failures even with right password');
    // a different IP is not locked out (an attacker can't lock the owner out from elsewhere)
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
    [$ok] = attempt_login('sam@test.local', 'password123'); ok($ok, 'other IP can still log in');
    eq(current_user()['email'], 'sam@test.local');
    ok((int)q_val("SELECT COUNT(*) FROM audit_logs WHERE action = 'login.failed'") >= 5, 'failures audited');
    ok(!q_val("SELECT 1 FROM audit_logs WHERE details_json LIKE '%password123%'"), 'password never logged');
}

function test_permissions_by_role() {
    $owner = fresh();
    ok(can('users.manage') && can('finance.view') && can('integrations.manage'));
    $ids = [];
    foreach (['admin', 'buyer', 'sales', 'viewer'] as $r) $ids[$r] = q_one('SELECT * FROM users WHERE id = ?', [create_user($r, "$r@x.co", 'password123', $r)]);
    ok(can('users.manage', $ids['admin']));
    ok(!can('users.delete_owner', $ids['admin']), 'admin denied explicit negative');
    ok(can('analytics.view', $ids['buyer']) && can('budgets.view', $ids['buyer']) && !can('users.manage', $ids['buyer']) && !can('finance.view', $ids['buyer']));
    ok(can('crm.edit', $ids['sales']) && can('crm.view', $ids['sales']) && !can('analytics.view', $ids['sales']) && !can('crm.view_all', $ids['sales']));
    ok(can('reports.view', $ids['viewer']) && !can('reports.manage', $ids['viewer']) && !can('crm.view', $ids['viewer']));
}

function test_client_scoping_and_demo_separation() {
    $owner = fresh();
    $a = client_create(['name' => 'Alpha', 'currency' => 'EGP']);
    $b = client_create(['name' => 'Beta', 'currency' => 'USD']);
    $d = client_create(['name' => 'Demo Co', 'currency' => 'EGP'], true);
    eq(scope_client_ids(), [$a, $b], 'owner sees all live clients, not demo');
    set_mode('demo');
    eq(scope_client_ids(), [$d], 'demo mode only shows demo clients');
    set_mode('live');
    $buyer = q_one('SELECT * FROM users WHERE id = ?', [create_user('Buyer', 'b@x.co', 'password123', 'buyer', [$b])]);
    as_user($buyer);
    eq(scope_client_ids(), [$b], 'buyer only sees assigned client');
    ok(!can_client($a));
    throws(fn() => require_client($a), Forbidden::class, 'unassigned client blocked');
    $_GET['client'] = (string)$a;
    eq(filter_client_ids(), [$b], 'filter cannot escape scope');
    unset($_GET['client']);
    as_user($owner);
    client_set_status($b, 'archived');
    eq(scope_client_ids(), [$a], 'archived clients hidden');
}

function test_client_validation() {
    fresh();
    throws(fn() => client_create(['name' => '']), InvalidArgumentException::class);
    throws(fn() => client_create(['name' => 'X', 'currency' => 'egyptian']), InvalidArgumentException::class);
    throws(fn() => client_create(['name' => 'X', 'timezone' => 'Mars/Base']), InvalidArgumentException::class);
    $id = client_create(['name' => '  مول حورية  ', 'currency' => 'egp']);
    $c = q_one('SELECT * FROM clients WHERE id = ?', [$id]);
    eq($c['name'], 'مول حورية'); eq($c['currency'], 'EGP');
    client_update($id, ['name' => 'Hourya Mall', 'currency' => 'EGP']);
    eq(q_val('SELECT name FROM clients WHERE id = ?', [$id]), 'Hourya Mall');
    ok((int)q_val("SELECT COUNT(*) FROM audit_logs WHERE entity = 'client' AND entity_id = ?", [(string)$id]) >= 2, 'client changes audited');
}

function test_ad_accounts_unique_and_currency_lock() {
    fresh();
    $c1 = client_create(['name' => 'A']); $c2 = client_create(['name' => 'B']);
    $acc = account_save(null, $c1, ['name' => 'Main', 'external_id' => 'act_12345', 'currency' => 'EGP']);
    eq(q_val('SELECT external_id FROM ad_accounts WHERE id = ?', [$acc]), '12345', 'act_ prefix stripped');
    throws(fn() => account_save(null, $c2, ['name' => 'Dup', 'external_id' => '12345', 'currency' => 'EGP']), InvalidArgumentException::class, 'same account on two clients');
    account_save($acc, $c1, ['name' => 'Main', 'external_id' => '12345', 'currency' => 'USD']);
    eq(q_val('SELECT currency FROM ad_accounts WHERE id = ?', [$acc]), 'USD', 'currency editable while empty');
    q("INSERT INTO campaigns (ad_account_id, external_id, name, created_at) VALUES (?, 'c1', 'C', ?)", [$acc, now()]);
    q("INSERT INTO performance_snapshots (ad_account_id, level, entity_id, date_start, date_end, currency, spend, imported_at) VALUES (?, 'campaign', 1, '2026-10-01', '2026-10-01', 'USD', 10, ?)", [$acc, now()]);
    throws(fn() => account_save($acc, $c1, ['name' => 'Main', 'external_id' => '12345', 'currency' => 'EGP']), InvalidArgumentException::class, 'currency locked once data exists');
}

function test_client_delete_cascades() {
    fresh();
    $c = client_create(['name' => 'Gone']);
    $acc = account_save(null, $c, ['name' => 'A', 'currency' => 'EGP']);
    q("INSERT INTO campaigns (ad_account_id, external_id, name, created_at) VALUES (?, 'x', 'X', ?)", [$acc, now()]);
    client_delete($c);
    eq((int)q_val('SELECT COUNT(*) FROM ad_accounts'), 0);
    eq((int)q_val('SELECT COUNT(*) FROM campaigns'), 0);
}

function test_encryption_roundtrip_and_redaction() {
    fresh();
    $enc = encrypt_json(['token' => 'EAAB123secret', 'app_secret' => 'abc']);
    ok(!str_contains($enc, 'EAAB123secret'), 'ciphertext does not contain plaintext');
    eq(decrypt_json($enc)['token'], 'EAAB123secret');
    eq(decrypt_str('v1:garbage'), null, 'tampered data rejected');
    $r = redact(['access_token' => 'x', 'nested' => ['client_secret' => 'y', 'ok' => 'fine'], 'url' => 'https://g.com/?access_token=EAAxyz&a=1']);
    eq($r['access_token'], '[redacted]'); eq($r['nested']['client_secret'], '[redacted]'); eq($r['nested']['ok'], 'fine');
    ok(!str_contains($r['url'], 'EAAxyz'), 'token in URL redacted');
    eq(mask_secret('abcdefgh'), '••••efgh');
}

function test_phone_and_email_normalisation() {
    eq(norm_phone('0101 234 5678'), '201012345678');
    eq(norm_phone('+20 101 234 5678'), '201012345678');
    eq(norm_phone('00201012345678'), '201012345678');
    eq(norm_phone('٠١٠١٢٣٤٥٦٧٨'), '201012345678', 'Arabic-Indic digits');
    eq(norm_phone('abc'), null);
    eq(norm_email(' Ahmed@Mail.COM '), 'ahmed@mail.com');
    eq(norm_email('nope'), null);
}

function test_demo_seed_and_clear_isolated_from_live() {
    fresh();
    $live = client_create(['name' => 'Real client']);
    demo_seed(10);
    ok((int)q_val('SELECT COUNT(*) FROM clients WHERE is_demo = 1') >= 3);
    eq(scope_client_ids(), [$live], 'live scope unaffected by demo seed');
    demo_clear();
    eq((int)q_val('SELECT COUNT(*) FROM clients WHERE is_demo = 1'), 0);
    eq((int)q_val('SELECT COUNT(*) FROM clients'), 1, 'live client survives demo clear');
}
