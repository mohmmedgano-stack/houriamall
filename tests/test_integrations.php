<?php
// Phase 7 — Meta Marketing API + AI layer, tested against a mocked HTTP layer (no real network in tests)

/** Fake Graph API: routes by path, records every call. */
function _meta_mock(array $routes): void {
    $GLOBALS['__meta_calls'] = [];
    $GLOBALS['META_HTTP'] = function ($method, $url, $params) use ($routes) {
        $path = preg_replace('#^https://graph\.facebook\.com/v[\d.]+/#', '', strtok($url, '?'));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q); $params += $q;
        $GLOBALS['__meta_calls'][] = [$method, $path, $params];
        foreach ($routes as $pattern => $resp) if (preg_match('#^' . $pattern . '$#', $path)) { $r = is_callable($resp) ? $resp($params) : $resp; return [$r[0], json_encode($r[1]), $r[2] ?? []]; }
        return [404, json_encode(['error' => ['message' => 'no route ' . $path, 'code' => 100]]), []];
    };
}

function _meta_connected(): array {
    $u = fresh();
    meta_save_config(['app_id' => '123', 'app_secret' => 'shh-secret', 'access_token' => 'EAAtoken123456789012345']);
    return [$u, client_create(['name' => 'Meta Client', 'currency' => 'EGP'])];
}

function test_meta_secrets_encrypted_and_proof_sent() {
    _meta_connected();
    $row = q_val("SELECT config_enc FROM integrations WHERE type = 'meta'");
    ok(!str_contains($row, 'EAAtoken') && !str_contains($row, 'shh-secret'), 'secrets encrypted at rest');
    _meta_mock(['me' => [200, ['id' => '9', 'name' => 'JANO']], 'me/permissions' => [200, ['data' => [['permission' => 'ads_read', 'status' => 'granted'], ['permission' => 'leads_retrieval', 'status' => 'granted']]]]]);
    $t = meta_test_connection();
    ok($t['ok']); eq($t['name'], 'JANO');
    eq(meta_integration()['status'], 'connected');
    $p = $GLOBALS['__meta_calls'][0][2];
    eq($p['appsecret_proof'], hash_hmac('sha256', 'EAAtoken123456789012345', 'shh-secret'), 'appsecret_proof on every call');
    ok(!q_val("SELECT 1 FROM audit_logs WHERE details_json LIKE '%EAAtoken%'"), 'token never in audit log');
}

function test_meta_missing_permission_and_expired_token() {
    _meta_connected();
    _meta_mock(['me' => [200, ['id' => '9', 'name' => 'X']], 'me/permissions' => [200, ['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]]]]);
    $t = meta_test_connection();
    ok(!$t['ok']); eq($t['missing'], ['ads_read']); eq(meta_integration()['status'], 'error', 'no fake success');
    _meta_mock(['me' => [400, ['error' => ['message' => 'Session has expired', 'code' => 190]]]]);
    $t = meta_test_connection();
    ok(!$t['ok']); eq(meta_integration()['status'], 'expired');
}

function test_meta_retries_transient_and_rate_limits() {
    _meta_connected();
    $n = 0;
    _meta_mock(['me' => function () use (&$n) { $n++; return $n < 3 ? [400, ['error' => ['message' => 'User request limit reached', 'code' => 17]], ['x-app-usage' => '{"call_count":92}']] : [200, ['id' => '1', 'name' => 'ok']]; }]);
    eq(meta_graph('GET', 'me')['name'], 'ok'); eq($n, 3, 'retried twice');
    ok(meta_throttled(), 'usage header noted → worker backs off');
    _meta_mock(['me' => [400, ['error' => ['message' => 'Invalid parameter', 'code' => 100]]]]);
    throws(fn() => meta_graph('GET', 'me'), MetaError::class, 'permanent error not retried');
    eq(count($GLOBALS['__meta_calls']), 1);
}

function test_meta_accounts_pagination_and_link() {
    [, $c] = _meta_connected();
    _meta_mock(['me/adaccounts' => fn($p) => empty($p['after'])
        ? [200, ['data' => [['id' => 'act_111', 'name' => 'A', 'currency' => 'EGP', 'timezone_name' => 'Africa/Cairo', 'account_status' => 1]], 'paging' => ['next' => 'https://graph.facebook.com/v23.0/me/adaccounts?after=X&access_token=LEAK']]]
        : [200, ['data' => [['id' => 'act_222', 'name' => 'B', 'currency' => 'USD', 'account_status' => 2]]]]]);
    $accs = meta_ad_accounts();
    eq(count($accs), 2, 'followed paging.next'); eq($accs[0]['id'], '111');
    eq($GLOBALS['__meta_calls'][1][2]['access_token'], 'EAAtoken123456789012345', 'next-page token replaced by ours');
    $id = meta_link_account($c, $accs[0]);
    $a = q_one('SELECT * FROM ad_accounts WHERE id = ?', [$id]);
    eq($a['external_id'], '111'); eq((int)$a['sync_enabled'], 1); eq($a['currency'], 'EGP');
    $c2 = client_create(['name' => 'Other']);
    throws(fn() => meta_link_account($c2, $accs[0]), InvalidArgumentException::class, 'account cannot belong to two clients');
}

function test_meta_sync_maps_structure_insights_and_breakdowns() {
    [, $c] = _meta_connected();
    $acc = meta_link_account($c, ['id' => '555', 'name' => 'Acc', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo']);
    _meta_mock([
        'act_555/campaigns' => [200, ['data' => [['id' => '10', 'name' => 'Leads C', 'objective' => 'OUTCOME_LEADS', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '50000']]]],
        'act_555/adsets' => [200, ['data' => [['id' => '20', 'name' => 'LAL', 'campaign_id' => '10', 'status' => 'ACTIVE', 'targeting' => ['geo_locations' => ['countries' => ['EG']], 'custom_audiences' => [['name' => 'Lookalike (EG, 1%)']]]]]]],
        'act_555/ads' => [200, ['data' => [['id' => '30', 'name' => 'Video 1', 'adset_id' => '20', 'status' => 'ACTIVE', 'creative' => ['id' => '40', 'title' => 'احجز', 'body' => 'شقق', 'link_url' => 'https://x.com/?utm_source=fb']]]]],
        'act_555/insights' => function ($p) {
            if (!empty($p['breakdowns'])) return [200, ['data' => $p['breakdowns'] === 'age' ? [['campaign_id' => '10', 'adset_id' => '20', 'date_start' => '2026-10-01', 'date_stop' => '2026-10-01', 'age' => '25-34', 'spend' => '60', 'impressions' => '3000', 'actions' => [['action_type' => 'lead', 'value' => '4']]]] : []]];
            $row = ['campaign_id' => '10', 'adset_id' => '20', 'ad_id' => '30', 'date_start' => '2026-10-01', 'date_stop' => '2026-10-01', 'spend' => '100.50', 'impressions' => '5000', 'clicks' => '120', 'inline_link_clicks' => '80',
                'actions' => [['action_type' => 'link_click', 'value' => '80'], ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '5'], ['action_type' => 'lead', 'value' => '5'], ['action_type' => 'video_view', 'value' => '1200']],
                'video_thruplay_watched_actions' => [['action_type' => 'video_view', 'value' => '300']]];
            if ($p['level'] === 'campaign') { $row['reach'] = '4100'; unset($row['adset_id'], $row['ad_id']); }
            return [200, ['data' => [$row]]];
        },
    ]);
    $s = meta_sync_account($acc, '2026-10-01', '2026-10-01');
    eq([$s['campaigns'], $s['adsets'], $s['ads'], $s['rows'], $s['breakdown_rows']], [1, 1, 1, 2, 1]);
    $cp = q_one("SELECT * FROM campaigns WHERE external_id = '10'");
    eq((float)$cp['daily_budget'], 500.0, 'budgets come in minor units'); eq($cp['objective'], 'OUTCOME_LEADS');
    eq(q_val("SELECT audience_type FROM adsets WHERE external_id = '20'"), 'lookalike');
    eq(q_val("SELECT link_url FROM creative_assets WHERE external_id = '40'"), 'https://x.com/?utm_source=fb');
    $m = totals_by_currency(perf_rows([$c], '2026-10-01', '2026-10-01'))['EGP']['m'];
    eq($m['spend'], 100.5); eq($m['leads'], 5.0, 'lead counted once (no double counting across action types)'); eq($m['reach'], 4100.0, 'campaign-level reach kept');
    eq($m['purchases'], null, 'purchases not reported → null, not 0');
    eq((int)q_val("SELECT video_3s FROM performance_snapshots WHERE level = 'ad'"), 1200);
    eq(count(audience_analyze([$c], 'bd:age', '2026-10-01', '2026-10-01')['EGP']['segments']), 1);
    eq((int)q_val("SELECT COUNT(*) FROM events WHERE type = 'performance.imported'"), 1);
    // re-sync updates, no duplicates; auditor can now check targeting & UTM & access
    meta_sync_account($acc, '2026-10-01', '2026-10-01');
    eq((int)q_val("SELECT COUNT(*) FROM performance_snapshots WHERE breakdown_key = ''"), 2);
    $auto = audit_auto_checks((int)$cp['id']);
    eq($auto['targeting'][0], 'pass'); eq($auto['utm'][0], 'pass'); eq($auto['access'][0], 'pass'); eq($auto['budget'][0], 'pass');
}

function test_meta_sync_failure_recorded_on_account() {
    [, $c] = _meta_connected();
    $acc = meta_link_account($c, ['id' => '777', 'name' => 'Acc', 'currency' => 'EGP', 'timezone' => '']);
    _meta_mock(['act_777/campaigns' => [403, ['error' => ['message' => 'Ad account owner has NOT grant ads_management or ads_read permission', 'code' => 200]]]]);
    throws(fn() => meta_sync_account($acc, '2026-10-01', '2026-10-01'), MetaError::class);
    ok(str_contains((string)q_val('SELECT last_sync_error FROM ad_accounts WHERE id = ?', [$acc]), 'permission'));
    eq(q_val('SELECT last_sync_at FROM ad_accounts WHERE id = ?', [$acc]), null, 'no fake success time');
}

function test_meta_leads_sync_idempotent_with_dedupe() {
    [, $c] = _meta_connected();
    $acc = meta_link_account($c, ['id' => '888', 'name' => 'Acc', 'currency' => 'EGP', 'timezone' => '']);
    $camp = upsert_campaign($acc, '1', ['name' => 'L', 'objective' => 'OUTCOME_LEADS'], 'meta'); $as = upsert_adset($camp, '2', ['name' => 'S'], 'meta'); upsert_ad($as, '3', ['name' => 'A'], 'meta');
    _meta_mock(['3/leads' => [200, ['data' => [
        ['id' => 'L1', 'created_time' => '2026-10-10T10:00:00+0000', 'form_id' => 'F1', 'field_data' => [['name' => 'full_name', 'values' => ['منى']], ['name' => 'phone_number', 'values' => ['+201000000009']], ['name' => 'الميزانية', 'values' => ['2 مليون']]]],
        ['id' => 'L2', 'created_time' => '2026-10-11T10:00:00+0000', 'form_id' => 'F1', 'field_data' => [['name' => 'full_name', 'values' => ['منى تاني']], ['name' => 'phone_number', 'values' => ['01000000009']]]],
    ]]]]);
    $s = meta_sync_leads($acc, 0);
    eq([$s['new'], $s['duplicates']], [1, 1]);
    $l = q_one("SELECT * FROM leads WHERE external_id = 'L1'");
    eq($l['source'], 'meta_form'); eq((int)$l['campaign_id'], $camp); ok($l['ad_id'] !== null);
    eq(json_decode($l['extra_json'], true)['الميزانية'], '2 مليون', 'custom form answers kept');
    $s2 = meta_sync_leads($acc, 0);
    eq($s2['existing'], 2, 'second pull adds nothing');
}

function test_meta_disconnect_keeps_data_stops_sync() {
    [, $c] = _meta_connected();
    $acc = meta_link_account($c, ['id' => '999', 'name' => 'A', 'currency' => 'EGP', 'timezone' => '']);
    q("INSERT INTO campaigns (ad_account_id, external_id, name, created_at) VALUES (?, 'k', 'K', ?)", [$acc, now()]);
    meta_disconnect();
    eq(meta_config()['access_token'] ?? null, null); eq(meta_config()['app_id'], '123', 'app config kept');
    eq((int)q_val('SELECT sync_enabled FROM ad_accounts WHERE id = ?', [$acc]), 0);
    eq((int)q_val('SELECT COUNT(*) FROM campaigns'), 1, 'history kept');
    eq(meta_sync_due(), [], 'worker does nothing without a token');
}

function _ai_on(): void {
    ai_save_config(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'api_key' => 'sk-ant-secret-key', 'monthly_calls' => 3, 'enabled' => true]);
}

function test_ai_summary_guarded_against_invented_numbers() {
    [, $c] = _mon_setup();
    _ai_on();
    ok(!str_contains((string)q_val("SELECT config_enc FROM integrations WHERE type = 'ai'"), 'sk-ant'), 'API key encrypted');
    $data = report_build('weekly', $c, '2026-10-01', '2026-10-07');
    $GLOBALS['AI_HTTP'] = fn($u, $h, $b) => [200, json_encode(['content' => [['type' => 'text', 'text' => 'إجمالي الصرف 14,070 EGP والـ CPL للحملة الأفضل 50.00.']], 'usage' => ['input_tokens' => 900, 'output_tokens' => 60]])];
    [$t, $e] = ai_report_summary($data);
    eq($e, null); ok(str_contains($t, '14,070'));
    $GLOBALS['AI_HTTP'] = fn($u, $h, $b) => [200, json_encode(['content' => [['type' => 'text', 'text' => 'الصرف 14,070 والمبيعات زادت 37% بسبب الموسم.']]])];
    [$t, $e] = ai_report_summary($data);
    eq($t, null, '37% is not in the data → rejected'); ok(str_contains($e, 'أرقام'));
    eq((int)q_val("SELECT COUNT(*) FROM ai_calls WHERE task = 'report_summary'"), 2, 'every call logged');
    ok(!q_val("SELECT 1 FROM ai_calls WHERE error LIKE '%sk-ant%'"));
    $GLOBALS['AI_HTTP'] = fn($u, $h, $b) => [200, json_encode(['content' => [['type' => 'text', 'text' => 'ok']]])];
    ai_complete('x', 's', 'u');
    ok(!ai_available(), 'monthly cap reached');
    [$t, $e] = ai_complete('x', 's', 'u'); eq($t, null); ok(str_contains($e, 'حد'));
    unset($GLOBALS['AI_HTTP']);
}

function test_ai_copy_falls_back_and_respects_limits() {
    [, $c] = _auto_setup();
    _ai_on();
    $d = copy_inputs(['product' => 'Perfume', 'lang' => 'en']);
    $GLOBALS['AI_HTTP'] = fn($u, $h, $b) => [500, json_encode(['error' => ['message' => 'overloaded']])];
    [$out, $src, $err] = copy_generate($d, true);
    eq($src, 'template', 'AI failure → template fallback'); ok(!empty($out['hooks'])); ok(str_contains($err, '500'));
    $GLOBALS['AI_HTTP'] = fn($u, $h, $b) => [200, json_encode(['content' => [['type' => 'text', 'text' => '```json' . json_encode(['hooks' => ['H1'], 'primary_text' => ['P1'], 'headlines' => [str_repeat('x', 60)], 'descriptions' => ['d']]) . '```']]])];
    [$out, $src] = copy_generate($d, true);
    eq($src, 'ai'); eq(mb_strlen($out['headlines'][0]), 40, 'headline trimmed to 40');
    unset($GLOBALS['AI_HTTP']);
}

function test_numbers_guard_unit() {
    $data = ['spend' => 14070.0, 'ctr' => 0.0156, 'leads' => 20.0];
    ok(ai_numbers_supported('الصرف 14,070 و CTR 1.56% و 20 ليد في 2026', $data));
    ok(!ai_numbers_supported('الصرف 15,000', $data));
    ok(ai_numbers_supported('3 حملات', $data), 'single digits ignored');
}
