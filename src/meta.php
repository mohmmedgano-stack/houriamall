<?php
declare(strict_types=1);
/*
 * Meta Marketing API integration (official Graph API).
 * Secrets live encrypted in integrations.config_enc; every call sends appsecret_proof; tokens never reach the browser or logs.
 * Read-only by design: we pull accounts, structure, insights and leads. Nothing here edits campaigns or budgets.
 */

const META_SCOPES = ['ads_read', 'read_insights', 'leads_retrieval', 'pages_show_list', 'pages_read_engagement', 'pages_manage_ads', 'business_management'];
const META_TRANSIENT = [1, 2, 4, 17, 32, 341, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

function meta_integration(): ?array { return q_one("SELECT * FROM integrations WHERE type = 'meta' ORDER BY id LIMIT 1"); }
function meta_config(): array { $i = meta_integration(); return $i ? decrypt_json($i['config_enc']) : []; }
function meta_save_config(array $patch, ?string $status = null, ?string $error = null): int {
    $i = meta_integration();
    $cfg = array_merge($i ? decrypt_json($i['config_enc']) : [], $patch);
    $cfg = array_filter($cfg, fn($v) => $v !== null && $v !== '');
    $row = ['config_enc' => encrypt_json($cfg), 'updated_at' => now()];
    if ($status !== null) $row['status'] = $status;
    if ($status === 'connected') { $row['last_ok_at'] = now(); $row['last_error'] = null; }
    if ($error !== null) $row['last_error'] = mb_substr(redact($error), 0, 500);
    if ($i) { db_update('integrations', (int)$i['id'], $row); return (int)$i['id']; }
    return db_insert('integrations', $row + ['type' => 'meta', 'name' => 'Meta Marketing API', 'status' => $status ?? 'not_configured', 'created_at' => now()]);
}
function meta_version(): string { $v = meta_config()['graph_version'] ?? 'v23.0'; return preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v23.0'; }

class MetaError extends RuntimeException {
    public function __construct(string $m, public int $metaCode = 0, public bool $transient = false, public ?int $subcode = null) { parent::__construct($m); }
}

/**
 * One Graph API call with appsecret_proof, transient-error retries (exponential backoff) and rate-limit awareness.
 * $GLOBALS['META_HTTP'] can replace the HTTP layer in tests: fn(method, url, params) => [status, body, headers].
 */
function meta_graph(string $method, string $path, array $params = [], ?string $token = null, int $maxTries = 3): array {
    $cfg = meta_config();
    $token ??= $cfg['access_token'] ?? null;
    if (!$token) throw new MetaError('مفيش Access Token — اربط حساب Meta الأول');
    $params['access_token'] = $token;
    if (!empty($cfg['app_secret'])) $params['appsecret_proof'] = hash_hmac('sha256', $token, $cfg['app_secret']);
    $url = str_starts_with($path, 'https://') ? $path : 'https://graph.facebook.com/' . meta_version() . '/' . ltrim($path, '/');
    for ($try = 1; ; $try++) {
        [$status, $body, $headers] = meta_http($method, $url, $params);
        $j = json_decode((string)$body, true);
        meta_note_usage($headers);
        if ($status >= 200 && $status < 300 && is_array($j) && !isset($j['error'])) return $j;
        $e = is_array($j) ? ($j['error'] ?? []) : [];
        $code = (int)($e['code'] ?? 0);
        $transient = $status === 0 || $status >= 500 || in_array($code, META_TRANSIENT, true) || !empty($e['is_transient']);
        $msg = ($e['error_user_msg'] ?? $e['message'] ?? ('HTTP ' . $status)) . ($code ? " (#$code)" : '');
        if ($code === 190) { meta_save_config([], 'expired', 'التوكن انتهى أو اتلغى: ' . $msg); throw new MetaError('صلاحية الربط مع Meta انتهت — أعد الربط من التكاملات', 190); }
        if (!$transient || $try >= $maxTries) throw new MetaError($msg, $code, $transient, isset($e['error_subcode']) ? (int)$e['error_subcode'] : null);
        $wait = in_array($code, [4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014], true) ? 30 * $try : 2 ** $try;
        if (!empty($GLOBALS['META_HTTP'])) $wait = 0;   // tests don't sleep
        sleep(min($wait, 60));
    }
}
function meta_http(string $method, string $url, array $params): array {
    if (!empty($GLOBALS['META_HTTP'])) return ($GLOBALS['META_HTTP'])($method, $url, $params);
    $ch = curl_init();
    $method = strtoupper($method);
    if ($method === 'GET') { $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params); }
    else { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params)); }
    $headers = [];
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) { $p = explode(':', $line, 2); if (count($p) === 2) $headers[strtolower(trim($p[0]))] = trim($p[1]); return strlen($line); }]);
    $body = curl_exec($ch);
    $status = $body === false ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) $body = json_encode(['error' => ['message' => 'Network: ' . curl_error($ch)]]);
    curl_close($ch);
    return [$status, (string)$body, $headers];
}
/** Remember Meta's own usage headers so the worker slows down before hitting limits. */
function meta_note_usage(array $headers): void {
    $max = 0;
    foreach (['x-business-use-case-usage', 'x-app-usage', 'x-ad-account-usage'] as $h) {
        if (empty($headers[$h])) continue;
        $j = json_decode($headers[$h], true);
        array_walk_recursive($j, function ($v, $k) use (&$max) { if (in_array($k, ['call_count', 'total_cputime', 'total_time', 'acc_id_util_pct'], true)) $max = max($max, (float)$v); });
    }
    if ($max) set_setting('meta_usage_pct', (string)$max);
}
function meta_throttled(): bool { return (float)setting('meta_usage_pct', '0') >= 85; }

/** Follow paging.next until done (or a safety cap). */
function meta_paginate(string $path, array $params, int $maxPages = 50): array {
    $out = []; $page = 0; $next = null;
    do {
        $r = $next ? meta_graph('GET', $next, []) : meta_graph('GET', $path, $params);
        array_push($out, ...($r['data'] ?? []));
        $next = $r['paging']['next'] ?? null;
        if ($next) { $q = []; parse_str((string)parse_url($next, PHP_URL_QUERY), $q); unset($q['access_token'], $q['appsecret_proof']); $next = strtok($next, '?') . '?' . http_build_query($q); }
    } while ($next && ++$page < $maxPages);
    return $out;
}

/* ---------- connection ---------- */
function meta_exchange_long_lived(string $shortToken): array {
    $cfg = meta_config();
    if (empty($cfg['app_id']) || empty($cfg['app_secret'])) throw new MetaError('حط App ID و App Secret الأول');
    [$status, $body] = meta_http('GET', 'https://graph.facebook.com/' . meta_version() . '/oauth/access_token',
        ['grant_type' => 'fb_exchange_token', 'client_id' => $cfg['app_id'], 'client_secret' => $cfg['app_secret'], 'fb_exchange_token' => $shortToken]);
    $j = json_decode($body, true);
    if (empty($j['access_token'])) throw new MetaError('فشل تحويل التوكن لطويل الأجل: ' . ($j['error']['message'] ?? 'HTTP ' . $status));
    return ['access_token' => $j['access_token'], 'token_expires_at' => isset($j['expires_in']) ? gmdate('Y-m-d H:i:s', time() + (int)$j['expires_in']) : null];
}
/** Verify the saved token for real: who is it, and which permissions were granted. */
function meta_test_connection(): array {
    try {
        $me = meta_graph('GET', 'me', ['fields' => 'id,name']);
        $perms = meta_graph('GET', 'me/permissions', []);
        $granted = array_values(array_map(fn($p) => $p['permission'], array_filter($perms['data'] ?? [], fn($p) => ($p['status'] ?? '') === 'granted')));
        $missing = array_values(array_diff(['ads_read'], $granted));
        meta_save_config(['user_name' => $me['name'] ?? '', 'user_id' => $me['id'] ?? '', 'scopes' => implode(',', $granted)], $missing ? 'error' : 'connected', $missing ? 'صلاحية ناقصة: ' . implode(', ', $missing) : null);
        return ['ok' => !$missing, 'name' => $me['name'] ?? '', 'granted' => $granted, 'missing' => $missing];
    } catch (MetaError $e) {
        meta_save_config([], $e->metaCode === 190 ? 'expired' : 'error', $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
function meta_disconnect(): void {
    $i = meta_integration(); if (!$i) return;
    $cfg = decrypt_json($i['config_enc']);
    db_update('integrations', (int)$i['id'], ['config_enc' => encrypt_json(array_intersect_key($cfg, array_flip(['app_id', 'app_secret', 'login_config_id', 'graph_version']))),
        'status' => 'disconnected', 'last_error' => null, 'updated_at' => now()]);
    q('UPDATE ad_accounts SET sync_enabled = 0 WHERE integration_id = ?', [$i['id']]);
    audit('meta.disconnect', 'integration', $i['id']);
}
function meta_ad_accounts(): array {
    return array_map(fn($a) => ['id' => preg_replace('/^act_/', '', (string)$a['id']), 'name' => $a['name'] ?? '', 'currency' => $a['currency'] ?? '', 'timezone' => $a['timezone_name'] ?? '',
        'status' => (int)($a['account_status'] ?? 0)], meta_paginate('me/adaccounts', ['fields' => 'id,name,currency,timezone_name,account_status', 'limit' => 100]));
}
/** Attach a Meta ad account to a client and turn on sync. Currency must match the platform's. */
function meta_link_account(int $clientId, array $acct): int {
    if (!can_client($clientId)) throw new Forbidden('client');
    $existing = q_one("SELECT * FROM ad_accounts WHERE platform = 'meta' AND external_id = ?", [$acct['id']]);
    if ($existing && (int)$existing['client_id'] !== $clientId) throw new InvalidArgumentException('الحساب ده مربوط بعميل تاني');
    $id = account_save($existing ? (int)$existing['id'] : null, $clientId, ['name' => $acct['name'], 'external_id' => $acct['id'], 'currency' => $acct['currency'], 'platform' => 'meta', 'timezone' => $acct['timezone']]);
    db_update('ad_accounts', $id, ['integration_id' => meta_integration()['id'] ?? null, 'sync_enabled' => 1, 'last_sync_error' => null]);
    audit('meta.link_account', 'ad_account', $id, ['external' => $acct['id']]);
    return $id;
}

/* ---------- sync ---------- */
function meta_money($v, string $currency): ?float {
    if ($v === null || $v === '') return null;
    $zeroDecimal = ['JPY', 'KRW', 'CLP', 'VND', 'ISK', 'HUF', 'TWD', 'COP', 'IDR', 'PYG'];
    return (float)$v / (in_array($currency, $zeroDecimal, true) ? 1 : 100);
}
/** Sum Meta "actions" entries whose action_type is in $types (first type wins per family to avoid double counting). */
function meta_action(array $row, string $field, array $types): ?float {
    if (!isset($row[$field])) return null;
    $by = []; foreach ($row[$field] as $a) $by[$a['action_type']] = (float)$a['value'];
    foreach ($types as $t) if (isset($by[$t])) return $by[$t];
    return null;
}
const META_LEAD_TYPES = ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead', 'leadgen_grouped'];
const META_PURCHASE_TYPES = ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'];

/** Convert one insights row into our snapshot fields. Metrics Meta didn't send stay null. */
function meta_row_metrics(array $r, ?string $objective, string $currency): array {
    $video = fn($f) => isset($r[$f]) ? (int)array_sum(array_map(fn($a) => (float)$a['value'], $r[$f])) : null;
    $m = ['spend' => isset($r['spend']) ? (float)$r['spend'] : null, 'impressions' => isset($r['impressions']) ? (int)$r['impressions'] : null,
        'reach' => isset($r['reach']) ? (int)$r['reach'] : null, 'clicks' => isset($r['clicks']) ? (int)$r['clicks'] : null,
        'link_clicks' => isset($r['inline_link_clicks']) ? (int)$r['inline_link_clicks'] : null,
        'leads' => meta_action($r, 'actions', META_LEAD_TYPES), 'purchases' => meta_action($r, 'actions', META_PURCHASE_TYPES),
        'purchase_value' => meta_action($r, 'action_values', META_PURCHASE_TYPES), 'landing_page_views' => meta_action($r, 'actions', ['landing_page_view', 'omni_landing_page_view']),
        'video_3s' => meta_action($r, 'actions', ['video_view']) === null ? null : (int)meta_action($r, 'actions', ['video_view']),
        'thruplays' => $video('video_thruplay_watched_actions'), 'video_p25' => $video('video_p25_watched_actions'), 'video_p50' => $video('video_p50_watched_actions'),
        'video_p75' => $video('video_p75_watched_actions'), 'video_p100' => $video('video_p100_watched_actions')];
    // If the account tracks leads/purchases but none happened, Meta just omits the action → that's a real 0 for the goal's own result
    $goal = goal_of($objective);
    if ($goal === 'leads' && $m['leads'] === null && $m['spend'] !== null) $m['leads'] = 0.0;
    if ($goal === 'sales' && $m['purchases'] === null && $m['spend'] !== null) $m['purchases'] = 0.0;
    [$m['results'], $m['result_type']] = match ($goal) {
        'leads' => [$m['leads'], 'lead'], 'sales' => [$m['purchases'], 'purchase'], 'traffic' => [$m['landing_page_views'] ?? (float)$m['link_clicks'], $m['landing_page_views'] !== null ? 'landing_page_view' : 'link_click'],
        'awareness' => [$m['reach'] === null ? null : (float)$m['reach'], 'reach'], default => [null, null] };
    return $m;
}

/** Pull structure + daily insights (+ audience breakdowns) for one account. Returns stats; records errors on the account. */
function meta_sync_account(int $accountId, string $from, string $to, bool $breakdowns = true): array {
    $acc = q_one('SELECT * FROM ad_accounts WHERE id = ?', [$accountId]);
    if (!$acc || !$acc['external_id']) throw new InvalidArgumentException('الحساب مش مربوط برقم Meta');
    $act = 'act_' . $acc['external_id']; $cur = $acc['currency'];
    $st = ['campaigns' => 0, 'adsets' => 0, 'ads' => 0, 'rows' => 0, 'breakdown_rows' => 0];
    try {
        $camps = meta_paginate("$act/campaigns", ['fields' => 'id,name,objective,status,effective_status,daily_budget,lifetime_budget,start_time,stop_time', 'limit' => 200]);
        $cmap = []; $obj = [];
        foreach ($camps as $c) {
            $cmap[$c['id']] = upsert_campaign($accountId, (string)$c['id'], ['name' => $c['name'], 'objective' => $c['objective'] ?? null, 'status' => $c['status'] ?? null, 'effective_status' => $c['effective_status'] ?? null,
                'daily_budget' => meta_money($c['daily_budget'] ?? null, $cur), 'lifetime_budget' => meta_money($c['lifetime_budget'] ?? null, $cur),
                'start_time' => $c['start_time'] ?? null, 'stop_time' => $c['stop_time'] ?? null], 'meta');
            $obj[$c['id']] = $c['objective'] ?? null; $st['campaigns']++;
        }
        $smap = [];
        foreach (meta_paginate("$act/adsets", ['fields' => 'id,name,campaign_id,status,optimization_goal,daily_budget,lifetime_budget,targeting', 'limit' => 200]) as $s) {
            if (!isset($cmap[$s['campaign_id']])) continue;
            $tg = $s['targeting'] ?? null;
            $type = !$tg ? null : (!empty($tg['custom_audiences']) ? (preg_grep('/lookalike/i', array_column($tg['custom_audiences'], 'name')) ? 'lookalike' : 'custom') : (!empty($tg['flexible_spec']) ? 'interests' : 'broad'));
            $smap[$s['id']] = upsert_adset($cmap[$s['campaign_id']], (string)$s['id'], ['name' => $s['name'], 'status' => $s['status'] ?? null, 'optimization_goal' => $s['optimization_goal'] ?? null,
                'daily_budget' => meta_money($s['daily_budget'] ?? null, $cur), 'lifetime_budget' => meta_money($s['lifetime_budget'] ?? null, $cur),
                'targeting_json' => $tg ? json_encode($tg, JSON_UNESCAPED_UNICODE) : null, 'audience_type' => $type], 'meta');
            $st['adsets']++;
        }
        $amap = [];
        foreach (meta_paginate("$act/ads", ['fields' => 'id,name,adset_id,status,creative{id,name,title,body,thumbnail_url,image_url,call_to_action_type,object_type,link_url}', 'limit' => 200]) as $a) {
            if (!isset($smap[$a['adset_id']])) continue;
            $cr = $a['creative'] ?? null; $crId = null;
            if ($cr) {
                $crId = q_val('SELECT id FROM creative_assets WHERE ad_account_id = ? AND external_id = ?', [$accountId, (string)$cr['id']]);
                $f = array_filter(['name' => $cr['name'] ?? null, 'title' => $cr['title'] ?? null, 'body' => $cr['body'] ?? null, 'cta' => $cr['call_to_action_type'] ?? null,
                    'thumbnail_url' => $cr['thumbnail_url'] ?? ($cr['image_url'] ?? null), 'link_url' => $cr['link_url'] ?? null, 'type' => strtolower((string)($cr['object_type'] ?? '')) ?: null], fn($v) => $v !== null);
                if ($crId) db_update('creative_assets', (int)$crId, $f + ['updated_at' => now()]);
                else $crId = db_insert('creative_assets', $f + ['client_id' => $acc['client_id'], 'ad_account_id' => $accountId, 'external_id' => (string)$cr['id'], 'created_at' => now()]);
            }
            $amap[$a['id']] = upsert_ad($smap[$a['adset_id']], (string)$a['id'], ['name' => $a['name'], 'status' => $a['status'] ?? null, 'creative_asset_id' => $crId], 'meta');
            $st['ads']++;
        }
        $fields = 'campaign_id,adset_id,ad_id,date_start,date_stop,spend,impressions,reach,clicks,inline_link_clicks,actions,action_values,video_thruplay_watched_actions,video_p25_watched_actions,video_p50_watched_actions,video_p75_watched_actions,video_p100_watched_actions';
        // chunks of 30 days keep each request small
        for ($s = new DateTimeImmutable($from); $s <= new DateTimeImmutable($to); $s = $s->modify('+30 days')) {
            $e = min($s->modify('+29 days')->format('Y-m-d'), $to);
            foreach (['campaign', 'ad'] as $level) {
                $rows = meta_paginate("$act/insights", ['level' => $level, 'fields' => $fields, 'time_increment' => 1, 'time_range' => json_encode(['since' => $s->format('Y-m-d'), 'until' => $e]), 'limit' => 500]);
                foreach ($rows as $r) {
                    $entity = $level === 'campaign' ? ($cmap[$r['campaign_id']] ?? null) : ($amap[$r['ad_id']] ?? null);
                    if (!$entity) continue;
                    upsert_snapshot(meta_row_metrics($r, $obj[$r['campaign_id']] ?? null, $cur) + ['ad_account_id' => $accountId, 'level' => $level, 'entity_id' => $entity,
                        'date_start' => $r['date_start'], 'date_end' => $r['date_stop'], 'currency' => $cur, 'source' => 'meta']);
                    $st['rows']++;
                }
            }
            if ($breakdowns) foreach (['age', 'gender', 'region', 'publisher_platform'] as $bk) {
                $rows = meta_paginate("$act/insights", ['level' => 'adset', 'fields' => 'campaign_id,adset_id,date_start,date_stop,spend,impressions,clicks,inline_link_clicks,actions,action_values',
                    'breakdowns' => $bk, 'time_increment' => 1, 'time_range' => json_encode(['since' => $s->format('Y-m-d'), 'until' => $e]), 'limit' => 500], 20);
                foreach ($rows as $r) {
                    if (!isset($smap[$r['adset_id']])) continue;
                    upsert_snapshot(meta_row_metrics($r, $obj[$r['campaign_id']] ?? null, $cur) + ['ad_account_id' => $accountId, 'level' => 'adset', 'entity_id' => $smap[$r['adset_id']],
                        'date_start' => $r['date_start'], 'date_end' => $r['date_stop'], 'currency' => $cur, 'source' => 'meta',
                        'breakdown_key' => $bk === 'publisher_platform' ? 'placement' : $bk, 'breakdown_value' => (string)($r[$bk] ?? 'unknown')]);
                    $st['breakdown_rows']++;
                }
            }
        }
        db_update('ad_accounts', $accountId, ['last_sync_at' => now(), 'last_sync_error' => null]);
        emit_event('performance.imported', (int)$acc['client_id'], (string)$accountId, $st + ['source' => 'meta']);
        return $st;
    } catch (MetaError $e) {
        db_update('ad_accounts', $accountId, ['last_sync_error' => mb_substr($e->getMessage(), 0, 300)]);
        throw $e;
    }
}

/** Worker: refresh synced accounts every 3 hours (last 3 days, so late attribution updates land). First sync pulls 30 days. */
function meta_sync_due(): array {
    $out = [];
    if (!(meta_config()['access_token'] ?? null)) return $out;
    if (meta_throttled()) { set_setting('meta_usage_pct', (string)max(0, (float)setting('meta_usage_pct', '0') - 10)); return ['throttled' => true]; }
    foreach (q_all("SELECT * FROM ad_accounts WHERE sync_enabled = 1 AND status = 'active' AND (last_sync_at IS NULL OR last_sync_at < ?) ORDER BY last_sync_at LIMIT 3", [gmdate('Y-m-d H:i:s', time() - 3 * 3600)]) as $a) {
        $from = (new DateTimeImmutable(today()))->modify($a['last_sync_at'] ? '-2 days' : '-29 days')->format('Y-m-d');
        try { $out[$a['id']] = meta_sync_account((int)$a['id'], $from, today()); }
        catch (Throwable $e) { $out[$a['id']] = ['error' => redact($e->getMessage())]; log_error('meta.sync', $e); if ($e instanceof MetaError && $e->metaCode === 190) break; }
    }
    return $out;
}

/** Pull new Lead Ads leads for ads we know about (needs leads_retrieval). Idempotent by Meta lead id. */
function meta_sync_leads(int $accountId, int $sinceTs): array {
    $acc = q_one('SELECT * FROM ad_accounts WHERE id = ?', [$accountId]);
    $ads = q_all("SELECT d.id, d.external_id, d.name, s.campaign_id, s.id adset_id FROM ads d JOIN adsets s ON s.id = d.adset_id JOIN campaigns c ON c.id = s.campaign_id
        WHERE c.ad_account_id = ? AND d.source = 'meta' AND (c.objective LIKE '%LEAD%')", [$accountId]);
    $st = ['ads' => count($ads), 'new' => 0, 'existing' => 0, 'duplicates' => 0];
    foreach ($ads as $ad) {
        $leads = meta_paginate($ad['external_id'] . '/leads', ['fields' => 'id,created_time,field_data,form_id,ad_id', 'limit' => 200,
            'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $sinceTs]])], 20);
        foreach ($leads as $l) {
            $fd = []; foreach ($l['field_data'] ?? [] as $f) $fd[$f['name']] = is_array($f['values'] ?? null) ? implode(', ', $f['values']) : '';
            $pick = function (array $keys) use (&$fd) { foreach ($keys as $k) if (isset($fd[$k]) && $fd[$k] !== '') { $v = $fd[$k]; unset($fd[$k]); return $v; } return ''; };
            $d = ['external_id' => (string)$l['id'], 'received_at' => $l['created_time'] ?? null, 'full_name' => $pick(['full_name', 'first_name', 'name']),
                'phone' => $pick(['phone_number', 'phone']), 'email' => $pick(['email']), 'city' => $pick(['city', 'state']), 'form_name' => (string)($l['form_id'] ?? ''),
                'campaign_id' => (int)$ad['campaign_id'], 'extra' => $fd];
            if ($d['email'] !== '' && !norm_email($d['email'])) { $d['extra']['email'] = $d['email']; $d['email'] = ''; }
            try {
                $r = lead_create((int)$acc['client_id'], $d, 'meta_form', null);
                if ($r['existing']) { $st['existing']++; continue; }
                if ($r['id']) q('UPDATE leads SET adset_id = ?, ad_id = ? WHERE id = ?', [$ad['adset_id'], $ad['id'], $r['id']]);
                $r['duplicate_of'] ? $st['duplicates']++ : $st['new']++;
            } catch (InvalidArgumentException $e) { log_error('meta.lead', $e->getMessage()); }
        }
    }
    return $st;
}
function meta_leads_sync_due(): array {
    $out = [];
    if (!(meta_config()['access_token'] ?? null) || !str_contains((string)(meta_config()['scopes'] ?? ''), 'leads_retrieval')) return $out;
    foreach (q_all("SELECT * FROM ad_accounts WHERE sync_enabled = 1 AND status = 'active'") as $a) {
        $since = (int)(setting('meta_leads_since_' . $a['id']) ?: time() - 7 * 86400);
        try { $out[$a['id']] = meta_sync_leads((int)$a['id'], $since - 600); set_setting('meta_leads_since_' . $a['id'], (string)time()); }
        catch (Throwable $e) { $out[$a['id']] = ['error' => redact($e->getMessage())]; log_error('meta.leads', $e); }
    }
    return $out;
}
