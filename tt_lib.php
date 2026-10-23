<?php
// TikTok (organic Business Account) comment auto-reply — TikTok API for Business, Accounts API.
declare(strict_types=1);

define('TT_API', getenv('TT_API_BASE') ?: 'https://business-api.tiktok.com/open_api/v1.3/');
const TT_SCOPES = 'user.info.basic,user.info.username,user.info.profile,video.list,comment.list,comment.list.manage';
const TT_MAX_REPLIES_PER_POLL = 25;

function tt_db(): PDO {
    static $done = false;
    $pdo = db();
    if ($done) return $pdo;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_accounts (
        id TEXT PRIMARY KEY, username TEXT, display_name TEXT, avatar TEXT,
        access_token TEXT, refresh_token TEXT, expires_at INTEGER, refresh_expires_at INTEGER,
        active INTEGER DEFAULT 0, problem TEXT DEFAULT '', last_poll INTEGER DEFAULT 0, info_at INTEGER DEFAULT 0,
        created_at TEXT, updated_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT, account_id TEXT, video_id TEXT DEFAULT '', video_label TEXT DEFAULT '',
        video_thumb TEXT DEFAULT '', name TEXT, active INTEGER DEFAULT 1, match_mode TEXT DEFAULT 'all', keywords TEXT,
        replies TEXT, like_comment INTEGER DEFAULT 0, wait_next INTEGER DEFAULT 0, wait_text TEXT DEFAULT '',
        backfill INTEGER DEFAULT 0, bf_cursor TEXT DEFAULT '', bf_done INTEGER DEFAULT 0,
        since_ts INTEGER DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tt_log (
        comment_id TEXT PRIMARY KEY, account_id TEXT, video_id TEXT, rule_id INTEGER,
        username TEXT, text TEXT, comment_ts INTEGER, status TEXT, error TEXT DEFAULT '',
        attempts INTEGER DEFAULT 0, next_retry TEXT, reply_text TEXT, created_at TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS tt_log_acc ON tt_log(account_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS tt_log_retry ON tt_log(next_retry)");
    return $pdo;
}

/* ---------------- HTTP ---------------- */
function tt_api(string $method, string $path, array $params = [], ?string $token = null): array {
    $url = TT_API . ltrim($path, '/');
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = 'Access-Token: ' . $token;
    $ch = curl_init();
    if (strtoupper($method) === 'GET') {
        foreach ($params as $k => $v) if (is_array($v)) $params[$k] = json_encode($v);
        if ($params) $url .= '?' . http_build_query($params);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10]);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); return ['code' => -1, 'message' => 'Network error: ' . $e]; }
    curl_close($ch);
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : ['code' => -2, 'message' => 'Bad response: ' . mb_substr((string)$body, 0, 200)];
}

function tt_err(array $r): ?string {
    $c = $r['code'] ?? null;
    if ($c === 0 || $c === '0') return null;
    return (string)($r['message'] ?? 'Unknown error') . ' (#' . (string)($c ?? '?') . ')';
}

/** Errors worth trying again later: network trouble, rate limits, TikTok-side hiccups. */
function tt_transient(array $r): bool {
    $c = (int)($r['code'] ?? 0);
    return $c < 0 || in_array($c, [40100, 40102, 40103, 40104, 50000, 50001, 50002, 51001, 61000], true);
}

/* ---------------- Auth ---------------- */
function tt_redirect_uri(): string { return app_base() . '/tt_oauth.php'; }

function tt_authorize_url(string $state): string {
    $custom = trim((string)setting('tt_auth_url', ''));
    if ($custom !== '') {
        // the authorization link copied from the TikTok developer portal — just add our state
        $u = preg_replace('/([?&])state=[^&]*&?/', '$1', $custom) ?? $custom;
        return rtrim($u, '&?') . (strpos($u, '?') === false ? '?' : '&') . 'state=' . rawurlencode($state);
    }
    return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
        'client_key' => setting('tt_app_id', ''), 'scope' => TT_SCOPES, 'response_type' => 'code',
        'redirect_uri' => tt_redirect_uri(), 'state' => $state,
    ]);
}

function tt_store_tokens(array $d, ?string $id = null): ?string {
    $id = $id ?? (string)($d['open_id'] ?? '');
    if ($id === '' || empty($d['access_token'])) return null;
    $exp = time() + (int)($d['expires_in'] ?? 86400);
    $rexp = time() + (int)($d['refresh_token_expires_in'] ?? 31536000);
    tt_db();
    db_run("INSERT INTO tt_accounts (id, access_token, refresh_token, expires_at, refresh_expires_at, active, problem, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, '', ?, ?)
        ON CONFLICT(id) DO UPDATE SET access_token = excluded.access_token, refresh_token = excluded.refresh_token,
        expires_at = excluded.expires_at, refresh_expires_at = excluded.refresh_expires_at, problem = '', updated_at = excluded.updated_at",
        [$id, $d['access_token'], (string)($d['refresh_token'] ?? ''), $exp, $rexp, now(), now()]);
    return $id;
}

function tt_exchange_code(string $code): array {
    $r = tt_api('POST', 'tt_user/oauth2/token/', [
        'client_id' => setting('tt_app_id', ''), 'client_secret' => setting('tt_app_secret', ''),
        'grant_type' => 'authorization_code', 'auth_code' => $code, 'redirect_uri' => tt_redirect_uri(),
    ]);
    if ($e = tt_err($r)) return ['error' => $e];
    $id = tt_store_tokens($r['data'] ?? []);
    if (!$id) return ['error' => 'تيك توك مرجعش بيانات الحساب'];
    $acc = tt_account($id);
    tt_refresh_info($acc, true);
    return ['id' => $id];
}

function tt_account(string $id): ?array {
    $st = tt_db()->prepare('SELECT * FROM tt_accounts WHERE id = ?'); $st->execute([$id]);
    return fetch_one($st) ?: null;
}

/** Keep the access token fresh (it lives 24h); returns a usable token or null. */
function tt_token(array &$acc): ?string {
    if ((int)$acc['expires_at'] - time() > 3600) return $acc['access_token'];
    $r = tt_api('POST', 'tt_user/oauth2/refresh_token/', [
        'client_id' => setting('tt_app_id', ''), 'client_secret' => setting('tt_app_secret', ''),
        'grant_type' => 'refresh_token', 'refresh_token' => $acc['refresh_token'],
    ]);
    if ($e = tt_err($r)) {
        if (!tt_transient($r)) db_run('UPDATE tt_accounts SET problem = ? WHERE id = ?', [$e, $acc['id']]);
        return (int)$acc['expires_at'] > time() ? $acc['access_token'] : null;
    }
    tt_store_tokens($r['data'] ?? [], $acc['id']);
    $acc = tt_account($acc['id']) ?? $acc;
    return $acc['access_token'];
}

/** Name and photo (the photo link expires, so refresh it every few hours). */
function tt_refresh_info(?array $acc, bool $force = false): void {
    if (!$acc || (!$force && time() - (int)$acc['info_at'] < 6 * 3600)) return;
    $tok = tt_token($acc);
    if (!$tok) return;
    $r = tt_api('GET', 'business/get/', ['business_id' => $acc['id'], 'fields' => ['username', 'display_name', 'profile_image']], $tok);
    if (tt_err($r)) return;
    $d = $r['data'] ?? [];
    db_run('UPDATE tt_accounts SET username = ?, display_name = ?, avatar = ?, info_at = ? WHERE id = ?',
        [(string)($d['username'] ?? $acc['username'] ?? ''), (string)($d['display_name'] ?? $acc['display_name'] ?? ''), (string)($d['profile_image'] ?? ''), time(), $acc['id']]);
}

/* ---------------- Videos & comments ---------------- */
function tt_videos(array $acc, int $max = 20, string $cursor = ''): array {
    $tok = tt_token($acc);
    if (!$tok) return ['error' => 'صلاحية الحساب انتهت — اربطه تاني'];
    $q = ['business_id' => $acc['id'], 'fields' => ['item_id', 'create_time', 'thumbnail_url', 'share_url', 'caption'], 'max_count' => min(20, $max)];
    if ($cursor !== '') $q['cursor'] = $cursor;
    $r = tt_api('GET', 'business/video/list/', $q, $tok);
    if ($e = tt_err($r)) return ['error' => $e];
    $d = $r['data'] ?? [];
    return ['videos' => $d['videos'] ?? [], 'cursor' => (string)($d['cursor'] ?? ''), 'has_more' => !empty($d['has_more'])];
}

function tt_comments(array $acc, string $tok, string $videoId, string $cursor = ''): array {
    $q = ['business_id' => $acc['id'], 'video_id' => $videoId, 'max_count' => 30, 'sort_field' => 'create_time', 'sort_order' => 'desc'];
    if ($cursor !== '') $q['cursor'] = $cursor;
    $r = tt_api('GET', 'business/comment/list/', $q, $tok);
    if ($e = tt_err($r)) return ['error' => $e, 'raw' => $r];
    $d = $r['data'] ?? [];
    return ['comments' => $d['comments'] ?? [], 'cursor' => (string)($d['cursor'] ?? ''), 'has_more' => !empty($d['has_more'])];
}

function tt_vid(array $v): string { return (string)($v['item_id'] ?? $v['video_id'] ?? $v['id'] ?? ''); }

/* ---------------- Rules ---------------- */
function tt_find_rule(array $rules, string $videoId, string $text): ?array {
    $cand = array_filter($rules, fn($r) => (int)$r['active'] === 1 && (int)$r['wait_next'] === 0 && ($r['video_id'] === $videoId || $r['video_id'] === ''));
    $score = fn($r) => (($r['video_id'] === $videoId) ? 0 : 2) + (($r['match_mode'] === 'keywords') ? 0 : 1);
    usort($cand, fn($a, $b) => ($score($a) <=> $score($b)) ?: ((int)$a['id'] <=> (int)$b['id']));
    $m = norm_ar($text);
    foreach ($cand as $r) {
        if ($r['match_mode'] === 'keywords') {
            $hit = false;
            foreach (keywords_of($r['keywords']) as $k) if (mb_strpos($m, norm_ar($k)) !== false) { $hit = true; break; }
            if (!$hit) continue;
        }
        return $r;
    }
    return null;
}

/** "Next video" rules: attach to the first new video (optionally the one whose caption contains a phrase). */
function tt_bind_waiting(array $acc, array $videos): void {
    $st = tt_db()->prepare("SELECT * FROM tt_rules WHERE account_id = ? AND wait_next = 1 AND active = 1");
    $st->execute([$acc['id']]);
    $waiting = $st->fetchAll();
    if (!$waiting) return;
    usort($videos, fn($a, $b) => (int)($a['create_time'] ?? 0) <=> (int)($b['create_time'] ?? 0));
    foreach ($waiting as $r) {
        $from = strtotime($r['created_at'] . ' UTC') - 120;
        $need = trim((string)$r['wait_text']);
        foreach ($videos as $v) {
            if ((int)($v['create_time'] ?? 0) < $from) continue;
            if ($need !== '' && mb_strpos(norm_ar((string)($v['caption'] ?? '')), norm_ar($need)) === false) continue;
            db_run("UPDATE tt_rules SET video_id = ?, video_label = ?, video_thumb = ?, wait_next = 0 WHERE id = ? AND wait_next = 1",
                [tt_vid($v), mb_substr(trim((string)($v['caption'] ?? '')), 0, 80), (string)($v['thumbnail_url'] ?? ''), $r['id']]);
            break;
        }
    }
}

/* ---------------- Replying ---------------- */
function tt_reply(array $acc, string $tok, array $rule, array $row): void {
    $text = pick($rule['replies']);
    if ($text === null) { db_run("UPDATE tt_log SET status = 'failed', error = 'القاعدة مفيهاش رد' WHERE comment_id = ?", [$row['comment_id']]); return; }
    $text = personalize($text, (string)$row['username']);
    $r = tt_api('POST', 'business/comment/reply/create/', [
        'business_id' => $acc['id'], 'video_id' => $row['video_id'], 'comment_id' => $row['comment_id'], 'text' => mb_substr($text, 0, 150),
    ], $tok);
    $att = (int)$row['attempts'] + 1;
    if ($e = tt_err($r)) {
        if (tt_transient($r) && $att < 6) {
            db_run("UPDATE tt_log SET status = 'retry', error = ?, attempts = ?, next_retry = datetime('now', ?) WHERE comment_id = ?",
                [$e, $att, '+' . (60 * $att * $att) . ' seconds', $row['comment_id']]);
        } else {
            db_run("UPDATE tt_log SET status = 'failed', error = ?, attempts = ?, next_retry = NULL WHERE comment_id = ?", [$e, $att, $row['comment_id']]);
        }
        return;
    }
    db_run("UPDATE tt_log SET status = 'sent', error = '', attempts = ?, next_retry = NULL, reply_text = ? WHERE comment_id = ?", [$att, $text, $row['comment_id']]);
    if ((int)$rule['like_comment'] === 1) {
        tt_api('POST', 'business/comment/like/', ['business_id' => $acc['id'], 'comment_id' => $row['comment_id'], 'action' => 'LIKE'], $tok);
    }
}

/** Handle one fetched comment. Returns: replied | seen | skip | old */
function tt_handle_comment(array $acc, string $tok, array $rules, string $videoId, array $c, bool $backfill = false): string {
    $cid = (string)($c['comment_id'] ?? $c['id'] ?? '');
    if ($cid === '') return 'skip';
    $parent = (string)($c['parent_comment_id'] ?? '');
    if ($parent !== '' && $parent !== $cid && $parent !== '0') return 'skip';           // replies under a comment
    if (!empty($c['owner']) || (string)($c['user_id'] ?? '') === $acc['id']
        || ($acc['username'] && strcasecmp((string)($c['username'] ?? ''), (string)$acc['username']) === 0)) return 'skip'; // the account's own comment
    $text = (string)($c['text'] ?? '');
    $ts = (int)($c['create_time'] ?? 0);
    $rule = tt_find_rule($rules, $videoId, $text);
    if (!$rule) return 'skip';
    if (!$backfill && $ts && $ts < (int)$rule['since_ts']) return 'old';
    $ins = db_run("INSERT OR IGNORE INTO tt_log (comment_id, account_id, video_id, rule_id, username, text, comment_ts, status, attempts, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?)",
        [$cid, $acc['id'], $videoId, (int)$rule['id'], (string)($c['display_name'] ?? $c['username'] ?? ''), mb_substr($text, 0, 500), $ts, now()]);
    if ($ins->rowCount() === 0) return 'seen';
    $st = db()->prepare('SELECT * FROM tt_log WHERE comment_id = ?'); $st->execute([$cid]);
    tt_reply($acc, $tok, $rule, fetch_one($st));
    return 'replied';
}

/** One polling round for one account: new comments on the videos that have rules. */
function tt_poll_account(array $acc, int $deadline): int {
    $tok = tt_token($acc);
    if (!$tok) return 0;
    tt_refresh_info($acc);
    $st = tt_db()->prepare('SELECT * FROM tt_rules WHERE account_id = ? AND active = 1'); $st->execute([$acc['id']]);
    $rules = $st->fetchAll();
    if (!$rules) return 0;

    $vl = tt_videos($acc, 20);
    if (isset($vl['error'])) { db_run('UPDATE tt_accounts SET problem = ? WHERE id = ?', [$vl['error'], $acc['id']]); return 0; }
    db_run("UPDATE tt_accounts SET problem = '' WHERE id = ?", [$acc['id']]);
    if (array_filter($rules, fn($r) => (int)$r['wait_next'] === 1)) {
        tt_bind_waiting($acc, $vl['videos']);
        $st->execute([$acc['id']]); $rules = $st->fetchAll();
    }

    $videos = [];
    foreach ($rules as $r) if ($r['video_id'] !== '' && (int)$r['wait_next'] === 0) $videos[$r['video_id']] = true;
    if (array_filter($rules, fn($r) => $r['video_id'] === '' && (int)$r['wait_next'] === 0)) {
        foreach (array_slice($vl['videos'], 0, 10) as $v) if (tt_vid($v) !== '') $videos[tt_vid($v)] = true;
    }

    $sent = 0;
    foreach (array_keys($videos) as $vid) {
        $cursor = '';
        for ($page = 0; $page < 3 && time() < $deadline && $sent < TT_MAX_REPLIES_PER_POLL; $page++) {
            $cl = tt_comments($acc, $tok, (string)$vid, $cursor);
            if (isset($cl['error'])) { tt_note_error($acc, $cl['error']); break; }
            $allOld = true;
            foreach ($cl['comments'] as $c) {
                $res = tt_handle_comment($acc, $tok, $rules, (string)$vid, $c);
                if ($res !== 'old') $allOld = false;
                if ($res === 'replied') { $sent++; usleep(700000); if ($sent >= TT_MAX_REPLIES_PER_POLL) break; }
            }
            if ($allOld || !$cl['has_more'] || $cl['cursor'] === '') break;
            $cursor = $cl['cursor'];
        }
    }

    // replying to old comments (rules with "also old comments" on a specific video), a few pages per round
    foreach ($rules as $r) {
        if ((int)$r['backfill'] !== 1 || (int)$r['bf_done'] === 1 || $r['video_id'] === '' || (int)$r['wait_next'] === 1) continue;
        $cursor = (string)$r['bf_cursor'];
        for ($page = 0; $page < 2 && time() < $deadline && $sent < TT_MAX_REPLIES_PER_POLL; $page++) {
            $cl = tt_comments($acc, $tok, $r['video_id'], $cursor);
            if (isset($cl['error'])) { tt_note_error($acc, $cl['error']); break 2; }
            foreach ($cl['comments'] as $c) {
                if (tt_handle_comment($acc, $tok, $rules, $r['video_id'], $c, true) === 'replied') { $sent++; usleep(700000); }
                if ($sent >= TT_MAX_REPLIES_PER_POLL) break;
            }
            if (!$cl['has_more'] || $cl['cursor'] === '') { db_run('UPDATE tt_rules SET bf_done = 1, bf_cursor = ? WHERE id = ?', ['', $r['id']]); break; }
            $cursor = $cl['cursor'];
            db_run('UPDATE tt_rules SET bf_cursor = ? WHERE id = ?', [$cursor, $r['id']]);
        }
    }
    return $sent;
}

function tt_note_error(array $acc, string $e): void {
    set_setting('tt_last_error', now() . ' · ' . ($acc['username'] ?: $acc['id']) . ': ' . $e);
}

function tt_retry_due(array $acc, string $tok): void {
    $st = tt_db()->prepare("SELECT * FROM tt_log WHERE account_id = ? AND status = 'retry' AND next_retry <= datetime('now') LIMIT 10");
    $st->execute([$acc['id']]);
    foreach ($st->fetchAll() as $row) {
        $rs = db()->prepare('SELECT * FROM tt_rules WHERE id = ?'); $rs->execute([(int)$row['rule_id']]);
        $rule = fetch_one($rs);
        if (!$rule) { db_run("UPDATE tt_log SET status = 'failed', error = 'القاعدة اتمسحت' WHERE comment_id = ?", [$row['comment_id']]); continue; }
        tt_reply($acc, $tok, $rule, $row);
    }
}

/** Called by the worker every minute. */
function tt_run(int $deadline): void {
    if (!setting('tt_app_id') || !setting('tt_app_secret')) return;
    foreach (tt_db()->query('SELECT * FROM tt_accounts WHERE active = 1')->fetchAll() as $acc) {
        if (time() >= $deadline) break;
        if (time() - (int)$acc['last_poll'] < 55) continue;
        db_run('UPDATE tt_accounts SET last_poll = ? WHERE id = ?', [time(), $acc['id']]);
        $tok = tt_token($acc);
        if (!$tok) continue;
        tt_retry_due($acc, $tok);
        tt_poll_account($acc, $deadline);
    }
    set_setting('tt_worker_seen', now());
}
