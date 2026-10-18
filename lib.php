<?php
declare(strict_types=1);

const APP_NAME = 'Gano Auto Reply';
define('DATA_DIR', __DIR__ . '/data');

/* ---------------- Database ---------------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0750, true); }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite', null, null, [PDO::ATTR_TIMEOUT => 30]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // wait (instead of failing) when another process is writing, and let readers and writers work side by side
    $pdo->exec('PRAGMA busy_timeout = 30000');
    try { $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA synchronous = NORMAL'); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pages (
        id TEXT PRIMARY KEY, name TEXT, token TEXT, active INTEGER DEFAULT 0,
        picture TEXT, updated_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT, page_id TEXT, post_id TEXT, post_label TEXT,
        name TEXT, active INTEGER DEFAULT 1, match_mode TEXT DEFAULT 'all', keywords TEXT,
        send_comment INTEGER DEFAULT 1, comment_replies TEXT,
        send_dm INTEGER DEFAULT 1, dm_messages TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS log (
        comment_id TEXT PRIMARY KEY, page_id TEXT, post_id TEXT, rule_id INTEGER,
        from_name TEXT, message TEXT, comment_status TEXT, dm_status TEXT,
        error TEXT, created_at TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS log_page ON log(page_id, created_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INTEGER, status TEXT, after_cursor TEXT,
        seen INTEGER DEFAULT 0, done INTEGER DEFAULT 0, dup INTEGER DEFAULT 0, nomatch INTEGER DEFAULT 0, fail INTEGER DEFAULT 0,
        error TEXT, created_at TEXT, updated_at TEXT)");
    $cols = array_column($pdo->query('PRAGMA table_info(rules)')->fetchAll(), 'name');
    if (!in_array('mention_user', $cols, true)) $pdo->exec("ALTER TABLE rules ADD COLUMN mention_user INTEGER DEFAULT 0");
    if (!in_array('fallback_replies', $cols, true)) $pdo->exec("ALTER TABLE rules ADD COLUMN fallback_replies TEXT");
    if (!in_array('images', $cols, true)) $pdo->exec("ALTER TABLE rules ADD COLUMN images TEXT");
    if (!in_array('images_button', $cols, true)) $pdo->exec("ALTER TABLE rules ADD COLUMN images_button TEXT");
    if (!in_array('like_comment', $cols, true)) $pdo->exec("ALTER TABLE rules ADD COLUMN like_comment INTEGER DEFAULT 0");
    if (!in_array('wait_next', $cols, true)) $pdo->exec("ALTER TABLE rules ADD COLUMN wait_next INTEGER DEFAULT 0");
    if (!in_array('wait_text', $cols, true)) $pdo->exec("ALTER TABLE rules ADD COLUMN wait_text TEXT");
    $lcols = array_column($pdo->query('PRAGMA table_info(log)')->fetchAll(), 'name');
    foreach (['from_id' => 'TEXT', 'comment_ts' => 'INTEGER', 'attempts' => 'INTEGER DEFAULT 0', 'next_retry' => 'TEXT', 'psid' => 'TEXT', 'images_status' => 'TEXT'] as $c => $t) {
        if (!in_array($c, $lcols, true)) $pdo->exec("ALTER TABLE log ADD COLUMN $c $t");
    }
    $jcols = array_column($pdo->query('PRAGMA table_info(jobs)')->fetchAll(), 'name');
    foreach (['fail_streak' => 'INTEGER DEFAULT 0', 'next_run' => 'TEXT'] as $c => $t) {
        if (!in_array($c, $jcols, true)) $pdo->exec("ALTER TABLE jobs ADD COLUMN $c $t");
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS log_retry ON log(next_retry)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, page_id TEXT DEFAULT '', name TEXT, active INTEGER DEFAULT 1,
        keywords TEXT, block_links INTEGER DEFAULT 0, block_phones INTEGER DEFAULT 0, block_mentions INTEGER DEFAULT 0,
        action TEXT DEFAULT 'hide', hits INTEGER DEFAULT 0, created_at TEXT)");
    return $pdo;
}

/** Read one row / one value and release the read lock right away (keeps SQLite from reporting "database is locked"). */
function fetch_one(PDOStatement $st) { $r = $st->fetch(); $st->closeCursor(); return $r; }
function fetch_col(PDOStatement $st) { $v = $st->fetchColumn(); $st->closeCursor(); return $v; }

/** Run a DB write, retrying for a while if another process holds the lock. */
function db_run(string $sql, array $params = []): PDOStatement {
    $tries = 0;
    while (true) {
        try {
            $st = db()->prepare($sql);
            $st->execute($params);
            return $st;
        } catch (PDOException $e) {
            $locked = stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false;
            if (!$locked || ++$tries >= 8) throw $e;
            usleep(500000 * $tries);   // 0.5s, 1s, 1.5s … then try again
        }
    }
}

function setting(string $k, ?string $default = null): ?string {
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = fetch_col($st);
    return ($v === false || $v === null) ? $default : (string)$v;
}

function set_setting(string $k, ?string $v): void {
    db_run('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
}

function get_page(string $id): ?array {
    $st = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $st->execute([$id]);
    $r = fetch_one($st);
    return $r ?: null;
}

function now(): string { return gmdate('Y-m-d H:i:s'); }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function app_base(): string {
    return PHP_SAPI === 'cli' ? (string)setting('base_url', '') : base_url();
}

function base_url(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return 'https://' . $host . $dir;
}

/* ---------------- Session / auth ---------------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('ganoar');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 14, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 14));
    session_start();
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-LiteSpeed-Cache-Control: no-cache');
}

function require_login(): void {
    if (empty($_SESSION['auth'])) { header('Location: index.php'); exit; }
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }

function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('انتهت صلاحية الصفحة، ارجع وجرب تاني.');
    }
}

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }

function redirect(string $to): void { header('Location: ' . $to); exit; }

/* ---------------- Graph API ---------------- */
function graph_version(): string { return setting('graph_version', 'v23.0') ?: 'v23.0'; }

function graph(string $method, string $path, array $params = [], ?string $token = null): array {
    $url = 'https://graph.facebook.com/' . graph_version() . '/' . ltrim($path, '/');
    if ($token !== null) $params['access_token'] = $token;
    $method = strtoupper($method);
    $ch = curl_init();
    if ($method === 'GET' || $method === 'DELETE') {
        if ($params) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        if ($method === 'DELETE') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => ['message' => 'Network error: ' . $err]];
    }
    curl_close($ch);
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : ['error' => ['message' => 'Bad response: ' . mb_substr((string)$body, 0, 200)]];
}

function gerr(array $r): ?string {
    if (!isset($r['error'])) return null;
    $e = $r['error'];
    return ($e['error_user_msg'] ?? $e['message'] ?? 'Unknown error') . (isset($e['code']) ? ' (#' . $e['code'] . ')' : '');
}

function app_token(): string { return setting('app_id', '') . '|' . setting('app_secret', ''); }

/** Exchange a user token for a long-lived one, then import every page it manages. */
function import_pages(string $userToken): array {
    $appId = setting('app_id'); $secret = setting('app_secret');
    if (!$appId || !$secret) return ['error' => 'حط App ID و App Secret في الإعدادات الأول'];

    $r = graph('GET', 'oauth/access_token', [
        'grant_type' => 'fb_exchange_token', 'client_id' => $appId,
        'client_secret' => $secret, 'fb_exchange_token' => trim($userToken),
    ]);
    if ($e = gerr($r)) return ['error' => $e];
    $long = (string)$r['access_token'];
    set_setting('user_token', $long);

    $count = 0; $after = null; $guard = 0;
    $up = db()->prepare("INSERT INTO pages (id, name, token, active, picture, updated_at) VALUES (?, ?, ?, 0, ?, ?)
        ON CONFLICT(id) DO UPDATE SET name = excluded.name, token = excluded.token, picture = excluded.picture, updated_at = excluded.updated_at");
    do {
        $params = ['fields' => 'id,name,access_token,picture{url}', 'limit' => 100];
        if ($after) $params['after'] = $after;
        $res = graph('GET', 'me/accounts', $params, $long);
        if ($e = gerr($res)) return ['error' => $e];
        foreach ($res['data'] ?? [] as $pg) {
            if (empty($pg['access_token'])) continue;
            $up->execute([(string)$pg['id'], $pg['name'] ?? '', $pg['access_token'], $pg['picture']['data']['url'] ?? '', now()]);
            $count++;
        }
        $after = $res['paging']['cursors']['after'] ?? null;
        $hasNext = isset($res['paging']['next']);
    } while ($hasNext && $after && ++$guard < 20);

    // Re-subscribe pages that were already active (tokens changed)
    foreach (db()->query('SELECT * FROM pages WHERE active = 1')->fetchAll() as $pg) {
        graph('POST', $pg['id'] . '/subscribed_apps', ['subscribed_fields' => PAGE_FIELDS], $pg['token']);
    }
    set_setting('token_problem', '');
    return ['count' => $count];
}

const PAGE_FIELDS = 'feed,messages,messaging_postbacks,standby';

/** Register the app-level webhook (Page → feed + messages) pointing at this install. */
function subscribe_app_webhook(): ?string {
    $appId = setting('app_id');
    if (!$appId || !setting('app_secret')) return 'حط App ID و App Secret الأول';
    if (app_base() === '') return 'لسه مفيش رابط محفوظ للأداة';
    $r = graph('POST', $appId . '/subscriptions', [
        'object' => 'page',
        'callback_url' => app_base() . '/webhook.php',
        'fields' => PAGE_FIELDS,
        'verify_token' => setting('verify_token', ''),
        'include_values' => 'true',
    ], app_token());
    return gerr($r);
}

/* ---------------- Reply engine ---------------- */
function variants(?string $t): array {
    $parts = preg_split('/^\s*-{3,}\s*$/mu', (string)$t) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
}

function pick(?string $t): ?string {
    $v = variants($t);
    return $v ? $v[array_rand($v)] : null;
}

function norm_ar(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s) ?? $s;
    return strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
}

function personalize(string $text, string $fullName): string {
    $fullName = trim($fullName);
    $first = $fullName !== '' ? explode(' ', $fullName)[0] : '';
    $out = str_replace(['{full_name}', '{name}'], [$fullName, $first], $text);
    return trim(preg_replace('/[ \t]{2,}/u', ' ', $out) ?? $out);
}

function keywords_of(?string $k): array {
    return array_values(array_filter(array_map('trim', preg_split('/[,،\n]+/u', (string)$k) ?: []), fn($x) => $x !== ''));
}

function find_rule(string $pageId, string $postId, string $message): ?array {
    $st = db()->prepare("SELECT * FROM rules WHERE page_id = ? AND active = 1 AND (post_id = ? OR ((post_id = '' OR post_id IS NULL) AND COALESCE(wait_next, 0) = 0))");
    $st->execute([$pageId, $postId]);
    $rules = $st->fetchAll();
    $score = fn($r) => (($r['post_id'] === $postId) ? 0 : 2) + (($r['match_mode'] === 'keywords') ? 0 : 1);
    usort($rules, fn($a, $b) => ($score($a) <=> $score($b)) ?: ((int)$a['id'] <=> (int)$b['id']));
    $m = norm_ar($message);
    foreach ($rules as $r) {
        if ($r['match_mode'] === 'keywords') {
            $hit = false;
            foreach (keywords_of($r['keywords']) as $k) {
                if (mb_strpos($m, norm_ar($k)) !== false) { $hit = true; break; }
            }
            if (!$hit) continue;
        }
        return $r;
    }
    return null;
}

const MAX_ATTEMPTS = 6;

/**
 * How long to wait before retrying a failed Graph call, or null when the error is permanent
 * (e.g. the person can't receive messages, the comment was deleted, missing permission).
 */
function retry_delay(array $r, int $attempt = 1): ?int {
    $e = $r['error'] ?? null;
    if (!$e) return null;
    $code = (int)($e['code'] ?? 0);
    $grow = min(8, 2 ** max(0, $attempt - 1));            // 1,2,4,8 × base
    if ($code === 0) return 60 * $grow;                     // network error / bad response from Meta
    if (in_array($code, [4, 17, 32, 613, 80001, 80006], true)) return 600 * $grow; // rate limits
    if ($code === 368) return 3600;                         // temporarily blocked → wait an hour
    if ($code === 190) { refresh_page_tokens(); return 300; } // token expired → refresh and retry
    if (in_array($code, [1, 2], true) || !empty($e['is_transient'])) return 60 * $grow;
    return null;
}

/** Re-fetch every page token with the saved long-lived user token (self-heal for expired page tokens). */
function refresh_page_tokens(): void {
    $last = (int)setting('tokens_refreshed', '0');
    if (time() - $last < 1800) return;
    set_setting('tokens_refreshed', (string)time());
    $user = setting('user_token');
    if (!$user) return;
    $after = null; $guard = 0;
    $up = db()->prepare('UPDATE pages SET token = ?, updated_at = ? WHERE id = ?');
    do {
        $params = ['fields' => 'id,access_token', 'limit' => 100];
        if ($after) $params['after'] = $after;
        $res = graph('GET', 'me/accounts', $params, $user);
        if (gerr($res)) { set_setting('token_problem', now()); return; }
        foreach ($res['data'] ?? [] as $pg) if (!empty($pg['access_token'])) $up->execute([$pg['access_token'], now(), (string)$pg['id']]);
        $after = $res['paging']['cursors']['after'] ?? null;
    } while (isset($res['paging']['next']) && $after && ++$guard < 20);
    set_setting('token_problem', '');
}

/**
 * Reply to one comment according to the matching rule. Idempotent per comment_id.
 * Returns ['status' => no_rule|duplicate|done, ...]
 */
function process_comment(array $page, string $postId, string $commentId, string $fromName, string $message, ?int $createdTs = null, string $fromId = '', bool $isReply = false): array {
    $mod = moderate_comment($page, $postId, $commentId, $fromName, $message, $fromId);
    if ($mod) return $mod;
    if ($isReply) return ['status' => 'no_rule'];   // replies are only moderated, never auto-answered
    $rule = find_rule($page['id'], $postId, $message);
    if (!$rule) return ['status' => 'no_rule'];

    $ins = db_run("INSERT OR IGNORE INTO log (comment_id, page_id, post_id, rule_id, from_name, message, comment_status, dm_status, error, created_at, from_id, comment_ts, attempts)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', '', ?, ?, ?, 0)",
        [$commentId, $page['id'], $postId, (int)$rule['id'], $fromName, mb_substr($message, 0, 500), now(), $fromId, $createdTs]);
    if ($ins->rowCount() === 0) return ['status' => 'duplicate'];

    $st = db()->prepare('SELECT * FROM log WHERE comment_id = ?'); $st->execute([$commentId]);
    return deliver($page, $rule, fetch_one($st));
}

/**
 * Do (or finish) the replies for one logged comment. Safe to call again: it only redoes the parts
 * that are still 'pending' or 'retry'. Temporary errors schedule a retry instead of failing.
 */
function deliver(array $page, array $rule, array $row): array {
    $commentId = (string)$row['comment_id'];
    $fromName  = (string)$row['from_name'];
    $fromId    = (string)($row['from_id'] ?? '');
    $ts        = $row['comment_ts'] ? (int)$row['comment_ts'] : null;
    $attempt   = (int)($row['attempts'] ?? 0) + 1;
    $ds = (string)$row['dm_status']; $cs = (string)$row['comment_status'];
    $errors = []; $wait = null;

    // 1) private reply
    if (in_array($ds, ['pending', 'retry'], true)) {
        $txt = ((int)$rule['send_dm'] === 1) ? pick($rule['dm_messages']) : null;
        if (!$txt) $ds = 'skipped';
        else {
            // no local 7-day cut-off any more: always try, and let Meta decide (it sometimes still accepts)
            $msg = ['text' => personalize($txt, $fromName)];
            $imgs = rule_images($rule);
            if ($imgs) {
                // Meta allows only ONE private reply per comment until the person answers →
                // offer a button; tapping it (or any reply) opens the chat and the photos are sent right away
                $msg['quick_replies'] = [['content_type' => 'text', 'title' => mb_substr((string)($rule['images_button'] ?? '') ?: '📸 شوف الصور', 0, 20), 'payload' => 'IMG|' . $commentId]];
            }
            $r = graph('POST', $page['id'] . '/messages', [
                'recipient' => json_encode(['comment_id' => $commentId]),
                'message'   => json_encode($msg, JSON_UNESCAPED_UNICODE),
            ], $page['token']);
            $ecode = (int)($r['error']['code'] ?? 0);
            $old = $ts && (time() - $ts) > 7 * 86400;
            if (gerr($r) && $old && retry_delay($r, 1) === null && $ecode !== 10900) {
                // older than 7 days and Meta refused → that's the 7-day rule, use the fallback/public reply
                $ds = 'expired';
            } elseif (in_array($ecode, [551, 10903, 2018108], true)) {
                // Meta won't let this person receive a private reply (messages closed, blocked the page, commented as a page…)
                $ds = 'closed';
                $errors[] = 'رسالة: الشخص ده مينفعش يوصله رسايل خاصة من الصفحة (' . $ecode . ')';
            } elseif ($ecode === 10900) {
                // a private reply already went to this comment (from us earlier, Meta automations or another tool) → nothing to do
                $ds = 'already';
            } elseif (!gerr($r)) {
                $ds = 'sent';
                if (!empty($r['recipient_id'])) {
                    db_run('UPDATE log SET psid = ?, images_status = ? WHERE comment_id = ?',
                        [(string)$r['recipient_id'], $imgs ? 'waiting' : null, $commentId]);
                }
            }
            else {
                $d = $attempt < MAX_ATTEMPTS ? retry_delay($r, $attempt) : null;
                if ($d !== null) { $ds = 'retry'; $wait = $d; } else $ds = 'failed';
                $errors[] = 'رسالة: ' . gerr($r);
            }
        }
    }

    // 2) public reply — waits while the DM is still being retried, so we know if the fallback is needed
    if ($ds !== 'retry' && in_array($cs, ['pending', 'retry'], true)) {
        $fallback = in_array($ds, ['failed', 'expired', 'closed'], true) ? pick($rule['fallback_replies'] ?? '') : null;
        $txt = $fallback ?: (((int)$rule['send_comment'] === 1) ? pick($rule['comment_replies']) : null);
        if (!$txt) $cs = 'skipped';
        else {
            $body = personalize($txt, $fromName);
            $withMention = (int)($rule['mention_user'] ?? 0) === 1 && $fromId !== '';
            $r = graph('POST', $commentId . '/comments', ['message' => ($withMention ? '@[' . $fromId . '] ' : '') . $body], $page['token']);
            if ($withMention && gerr($r) && retry_delay($r, 1) === null) {
                // mention rejected → send without it so the customer still gets an answer
                $r = graph('POST', $commentId . '/comments', ['message' => $body], $page['token']);
            }
            if (!gerr($r)) $cs = $fallback ? 'fallback' : 'sent';
            else {
                $d = $attempt < MAX_ATTEMPTS ? retry_delay($r, $attempt) : null;
                if ($d !== null) { $cs = 'retry'; $wait = max($wait ?? 0, $d); } else $cs = 'failed';
                $errors[] = 'كومنت: ' . gerr($r);
            }
        }
    }

    // 3) like the comment (once, best effort — never blocks the replies)
    if ((int)($rule['like_comment'] ?? 0) === 1 && $wait === null && (int)($row['attempts'] ?? 0) === 0) {
        $lr = graph('POST', $commentId . '/likes', [], $page['token']);
        if (gerr($lr) && retry_delay($lr, 1) !== null) { usleep(1000000); $lr = graph('POST', $commentId . '/likes', [], $page['token']); }
        if (gerr($lr)) $errors[] = 'لايك: ' . gerr($lr);
    }

    $next = $wait !== null ? gmdate('Y-m-d H:i:s', time() + $wait) : null;
    db_run('UPDATE log SET comment_status = ?, dm_status = ?, error = ?, attempts = ?, next_retry = ? WHERE comment_id = ?',
        [$cs, $ds, implode(' | ', $errors), $attempt, $next, $commentId]);

    return ['status' => 'done', 'comment' => $cs, 'dm' => $ds, 'errors' => $errors, 'retry' => $next !== null];
}

/** Worker: finish replies that hit a temporary error, or got stuck half-way (e.g. server restart). */
function retry_due(int $limit = 10): int {
    $rows = db()->query("SELECT * FROM log WHERE
        (next_retry IS NOT NULL AND next_retry <= datetime('now'))
        OR ((dm_status = 'pending' OR comment_status = 'pending') AND next_retry IS NULL AND created_at < datetime('now', '-3 minutes'))
        ORDER BY created_at DESC LIMIT " . (int)$limit)->fetchAll();
    foreach ($rows as $row) {
        $pg = get_page((string)$row['page_id']);
        $st = db()->prepare('SELECT * FROM rules WHERE id = ?'); $st->execute([(int)$row['rule_id']]);
        $rule = fetch_one($st);
        if (!$pg || !$rule) {
            db()->prepare("UPDATE log SET next_retry = NULL, comment_status = CASE WHEN comment_status IN ('pending','retry') THEN 'failed' ELSE comment_status END,
                dm_status = CASE WHEN dm_status IN ('pending','retry') THEN 'failed' ELSE dm_status END, error = 'القاعدة أو الصفحة اتمسحت' WHERE comment_id = ?")
                ->execute([$row['comment_id']]);
            continue;
        }
        deliver($pg, $rule, $row);
        usleep(400000);
    }
    return count($rows);
}

/** Handle one comment object fetched from the Graph API (used by old-comment jobs and the catch-up sweep). */
function handle_fetched_comment(array $pg, string $postId, array $cm): string {
    if ((string)($cm['from']['id'] ?? '') === $pg['id']) return 'skip';
    foreach ($cm['comments']['data'] ?? [] as $rep) {
        if ((string)($rep['from']['id'] ?? '') === $pg['id']) return 'dup';   // page already answered it by hand
    }
    $out = process_comment($pg, $postId, (string)$cm['id'], (string)($cm['from']['name'] ?? ''), (string)($cm['message'] ?? ''),
        isset($cm['created_time']) ? strtotime($cm['created_time']) : null, (string)($cm['from']['id'] ?? ''));
    if ($out['status'] === 'blocked') return 'done';
    if ($out['status'] === 'duplicate') return 'dup';
    if ($out['status'] === 'no_rule') return 'nomatch';
    if (($out['comment'] ?? '') === 'failed' && !in_array($out['dm'] ?? '', ['sent', 'already'], true)) return 'fail';
    return 'done';
}

const COMMENT_FIELDS = 'id,message,from{id,name},created_time,comments.limit(50){from{id}}';

/**
 * Catch-up sweep: picks up comments whose webhook never arrived (server down, Meta hiccup).
 * Only looks at comments from the last 48h that were written after the rule was created.
 */
function sweep_missed(): void {
    $pages = db()->query('SELECT * FROM pages WHERE active = 1')->fetchAll();
    foreach ($pages as $pg) {
        $rules = db()->prepare('SELECT * FROM rules WHERE page_id = ? AND active = 1'); $rules->execute([$pg['id']]);
        $targets = [];   // post_id => earliest timestamp to consider
        $pageWideSince = null;
        foreach ($rules->fetchAll() as $r) {
            $since = max(strtotime(($r['created_at'] ?: now()) . ' UTC'), time() - 48 * 3600);
            if ((int)($r['wait_next'] ?? 0) === 1 && !$r['post_id']) continue;   // still waiting for its post
            if ($r['post_id']) $targets[$r['post_id']] = min($targets[$r['post_id']] ?? PHP_INT_MAX, $since);
            else $pageWideSince = min($pageWideSince ?? PHP_INT_MAX, $since);
        }
        if ($pageWideSince !== null) {
            $res = graph('GET', $pg['id'] . '/posts', ['fields' => 'id', 'limit' => 5], $pg['token']);
            if (($res['error']['code'] ?? 0) == 190) refresh_page_tokens();
            foreach ($res['data'] ?? [] as $p) $targets[$p['id']] = min($targets[$p['id']] ?? PHP_INT_MAX, $pageWideSince);
        }
        foreach ($targets as $postId => $since) {
            $res = graph('GET', $postId . '/comments', ['filter' => 'toplevel', 'order' => 'reverse_chronological', 'limit' => 25, 'fields' => COMMENT_FIELDS], $pg['token']);
            if (gerr($res)) { if (($res['error']['code'] ?? 0) == 190) refresh_page_tokens(); continue; }
            foreach ($res['data'] ?? [] as $cm) {
                if (isset($cm['created_time']) && strtotime($cm['created_time']) < $since) break;   // newest first → stop at old ones
                handle_fetched_comment($pg, (string)$postId, $cm);
            }
        }
    }
}

/* ---------------- Background backfill jobs (run by worker.php from cron) ---------------- */
const BACKFILL_BATCH = 2;

function job_for_rule(int $ruleId): ?array {
    $st = db()->prepare('SELECT * FROM jobs WHERE rule_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$ruleId]);
    return fetch_one($st) ?: null;
}

/** Process one small batch of a job. Temporary errors pause it briefly and it carries on by itself. */
function run_job_batch(array $job): array {
    $upd = function (array $f) use (&$job) {
        $f['updated_at'] = now();
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        db_run("UPDATE jobs SET $sets WHERE id = ?", array_merge(array_values($f), [$job['id']]));
        $job = array_merge($job, $f);
    };
    $st = db()->prepare('SELECT * FROM rules WHERE id = ?'); $st->execute([(int)$job['rule_id']]);
    $rule = fetch_one($st);
    $pg = $rule ? get_page($rule['page_id']) : null;
    if (!$rule || !$pg || !$rule['post_id']) { $upd(['status' => 'error', 'error' => 'القاعدة أو الصفحة مش موجودة']); return $job; }

    $params = ['filter' => 'toplevel', 'fields' => COMMENT_FIELDS, 'order' => 'reverse_chronological', 'limit' => BACKFILL_BATCH];
    if (!empty($job['after_cursor'])) $params['after'] = $job['after_cursor'];
    $res = graph('GET', $rule['post_id'] . '/comments', $params, $pg['token']);
    if ($e = gerr($res)) {
        $streak = (int)($job['fail_streak'] ?? 0) + 1;
        $d = retry_delay($res, $streak);
        if ($d === null) { $upd(['status' => 'error', 'error' => $e]); return $job; }   // permanent problem → stop and show it
        $upd(['next_run' => gmdate('Y-m-d H:i:s', time() + $d), 'fail_streak' => $streak,
              'error' => 'مشكلة مؤقتة، هيكمل لوحده بعد ' . max(1, (int)round($d / 60)) . ' دقيقة: ' . $e]);
        return $job;
    }

    $c = ['done' => 0, 'dup' => 0, 'nomatch' => 0, 'fail' => 0];
    foreach ($res['data'] ?? [] as $cm) {
        $k = handle_fetched_comment($pg, (string)$rule['post_id'], $cm);
        if (isset($c[$k])) $c[$k]++;
    }
    $next = isset($res['paging']['next']) ? ($res['paging']['cursors']['after'] ?? null) : null;

    // the user may have paused while we were working — don't override that
    $cur = db()->prepare('SELECT status FROM jobs WHERE id = ?'); $cur->execute([$job['id']]);
    $status = (string)fetch_col($cur);
    if ($status === 'running') $status = $next === null ? 'done' : 'running';

    $upd([
        'status' => $status, 'after_cursor' => $next, 'fail_streak' => 0, 'next_run' => null, 'error' => '',
        'seen' => (int)$job['seen'] + count($res['data'] ?? []),
        'done' => (int)$job['done'] + $c['done'], 'dup' => (int)$job['dup'] + $c['dup'],
        'nomatch' => (int)$job['nomatch'] + $c['nomatch'], 'fail' => (int)$job['fail'] + $c['fail'],
    ]);
    return $job;
}


/* ---------------- Photos sent after the person answers the private reply ---------------- */
function rule_images(array $rule): array {
    $a = json_decode((string)($rule['images'] ?? ''), true);
    return is_array($a) ? array_values(array_filter($a, 'is_string')) : [];
}

/**
 * Called from the webhook when someone writes to the page (or taps "see photos").
 * If we owe them photos from a comment reply, send them now (the 24h window is open).
 */
function send_pending_images(array $page, string $psid, ?string $commentId = null): int {
    if ($commentId) {
        $st = db()->prepare("SELECT * FROM log WHERE comment_id = ? AND page_id = ? AND images_status = 'waiting'");
        $st->execute([$commentId, $page['id']]);
    } else {
        $st = db()->prepare("SELECT * FROM log WHERE psid = ? AND page_id = ? AND images_status = 'waiting' ORDER BY created_at DESC LIMIT 1");
        $st->execute([$psid, $page['id']]);
    }
    $row = fetch_one($st);
    if (!$row) return 0;
    // claim it first so a double tap doesn't send the photos twice
    $claim = db_run("UPDATE log SET images_status = 'sending' WHERE comment_id = ? AND images_status = 'waiting'", [$row['comment_id']]);
    if ($claim->rowCount() === 0) return 0;

    $st = db()->prepare('SELECT * FROM rules WHERE id = ?'); $st->execute([(int)$row['rule_id']]);
    $rule = fetch_one($st);
    $imgs = $rule ? rule_images($rule) : [];
    $sent = 0; $errs = []; $tookControl = false;
    foreach (array_slice($imgs, 0, 10) as $url) {
        $r = graph('POST', $page['id'] . '/messages', [
            'recipient' => json_encode(['id' => $psid]),
            'messaging_type' => 'RESPONSE',
            'message' => json_encode(['attachment' => ['type' => 'image', 'payload' => ['url' => $url, 'is_reusable' => true]]]),
        ], $page['token']);
        if (gerr($r)) {
            // one quick retry for temporary problems
            if (retry_delay($r, 1) !== null) { usleep(1500000); $r = graph('POST', $page['id'] . '/messages', [
                'recipient' => json_encode(['id' => $psid]), 'messaging_type' => 'RESPONSE',
                'message' => json_encode(['attachment' => ['type' => 'image', 'payload' => ['url' => $url, 'is_reusable' => true]]]),
            ], $page['token']); }
        }
        if (gerr($r) && !$tookControl) {
            // another bot (e.g. Meta Business AI) owns the conversation → take it over for this person, then retry
            $tookControl = true;
            graph('POST', $page['id'] . '/take_thread_control', ['recipient' => json_encode(['id' => $psid]), 'metadata' => 'autoreply photos'], $page['token']);
            $r = graph('POST', $page['id'] . '/messages', [
                'recipient' => json_encode(['id' => $psid]), 'messaging_type' => 'RESPONSE',
                'message' => json_encode(['attachment' => ['type' => 'image', 'payload' => ['url' => $url, 'is_reusable' => true]]]),
            ], $page['token']);
        }
        if (gerr($r)) $errs[] = 'صورة: ' . gerr($r); else $sent++;
    }
    $status = $sent > 0 ? 'sent' : 'failed';
    db_run("UPDATE log SET images_status = ?, error = CASE WHEN ? = '' THEN error ELSE TRIM(error || ' | ' || ?, ' |') END WHERE comment_id = ?",
        [$status, implode(' | ', $errs), implode(' | ', $errs), $row['comment_id']]);
    return $sent;
}


/* ---------------- Blocked comments: hide or delete automatically ---------------- */
function to_latin_digits(string $s): string {
    return strtr($s, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                      '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
}

/** Which block (if any) this comment falls under. Returns [block row, reason] or null. */
function find_block(string $pageId, string $message): ?array {
    $st = db()->prepare("SELECT * FROM blocks WHERE active = 1 AND (page_id = '' OR page_id IS NULL OR page_id = ?) ORDER BY id");
    $st->execute([$pageId]);
    $blocks = $st->fetchAll();
    if (!$blocks) return null;
    $norm = norm_ar($message);
    $digits = preg_replace('/[\s\-\.\(\)+]/u', '', to_latin_digits($message)) ?? '';
    foreach ($blocks as $b) {
        foreach (keywords_of($b['keywords']) as $k) {
            if ($k !== '' && mb_strpos($norm, norm_ar($k)) !== false) return [$b, 'كلمة: ' . $k];
        }
        if ((int)$b['block_links'] === 1 && preg_match('~(https?://|www\.|wa\.me|bit\.ly|t\.me|\b[a-z0-9-]{2,}\.(com|net|org|me|ly|link|io|co|online|store|shop|site|xyz|info|app|eg)\b)~iu', $message))
            return [$b, 'لينك'];
        if ((int)$b['block_phones'] === 1 && preg_match('/\d{8,}/', $digits))
            return [$b, 'رقم تليفون'];
        if ((int)$b['block_mentions'] === 1 && preg_match('/@\S/u', $message))
            return [$b, 'منشن'];
    }
    return null;
}

/** Hide/delete the comment if it matches a block. Returns a process_comment-style result, or null when not blocked. */
function moderate_comment(array $page, string $postId, string $commentId, string $fromName, string $message, string $fromId): ?array {
    if ($fromId !== '' && $fromId === (string)$page['id']) return null;   // never touch the page's own comments
    $hit = find_block((string)$page['id'], $message);
    if (!$hit) return null;
    [$b, $reason] = $hit;

    $ins = db_run("INSERT OR IGNORE INTO log (comment_id, page_id, post_id, rule_id, from_name, message, comment_status, dm_status, error, created_at, from_id, attempts)
        VALUES (?, ?, ?, 0, ?, ?, 'pending', 'skipped', ?, ?, ?, 0)",
        [$commentId, $page['id'], $postId, $fromName, mb_substr($message, 0, 500), 'محظور (' . $b['name'] . ' — ' . $reason . ')', now(), $fromId]);
    if ($ins->rowCount() === 0) {
        // already handled — but if it was auto-replied before the block existed, still hide/delete it now
        $st = db()->prepare('SELECT comment_status FROM log WHERE comment_id = ?'); $st->execute([$commentId]);
        if (in_array((string)fetch_col($st), ['hidden', 'deleted'], true)) return ['status' => 'duplicate'];
        db_run("UPDATE log SET error = ? WHERE comment_id = ?", ['محظور (' . $b['name'] . ' — ' . $reason . ')', $commentId]);
    }

    $act = $b['action'] === 'delete' ? 'delete' : 'hide';
    $call = fn() => $act === 'delete'
        ? graph('DELETE', $commentId, [], $page['token'])
        : graph('POST', $commentId, ['is_hidden' => 'true'], $page['token']);
    $r = $call();
    if (gerr($r) && retry_delay($r, 1) !== null) { usleep(1500000); $r = $call(); }
    if (gerr($r) && $act === 'delete') {
        // some comments (e.g. on certain ads) can't be deleted by the page → at least hide them
        $r2 = graph('POST', $commentId, ['is_hidden' => 'true'], $page['token']);
        if (!gerr($r2)) { $r = $r2; $act = 'hide'; }
    }
    $ok = !gerr($r);
    $status = $ok ? ($act === 'delete' ? 'deleted' : 'hidden') : 'failed';
    db_run("UPDATE log SET comment_status = ?, dm_status = 'skipped', next_retry = NULL, error = ? WHERE comment_id = ?",
        [$status, 'محظور (' . $b['name'] . ' — ' . $reason . ')' . ($ok ? '' : ' | ' . gerr($r)), $commentId]);
    if ($ok) db_run('UPDATE blocks SET hits = hits + 1 WHERE id = ?', [(int)$b['id']]);
    return ['status' => 'blocked', 'comment' => $status, 'dm' => 'skipped', 'errors' => $ok ? [] : [gerr($r)]];
}


/* ---------------- Rules waiting for the next post the page publishes ---------------- */
/** Attach every waiting rule of this page to a newly published post. */
function bind_next_rules(string $pageId, string $postId, ?int $postTs = null, string $label = '', string $postText = ''): int {
    $st = db()->prepare("SELECT id, created_at, wait_text FROM rules WHERE page_id = ? AND COALESCE(wait_next, 0) = 1 AND (post_id = '' OR post_id IS NULL)");
    $st->execute([$pageId]);
    $n = 0;
    $normText = norm_ar($postText !== '' ? $postText : $label);
    foreach ($st->fetchAll() as $r) {
        if ($postTs && $postTs < strtotime($r['created_at'] . ' UTC') - 120) continue;   // post is older than the rule
        $need = trim((string)($r['wait_text'] ?? ''));
        if ($need !== '' && mb_strpos(preg_replace('/\s+/u', ' ', $normText), preg_replace('/\s+/u', ' ', norm_ar($need))) === false) continue;   // not the post this rule waits for
        $lbl = '⏰ ' . ($label !== '' ? $label : 'البوست اللي نزل') . ' · ' . gmdate('Y-m-d', $postTs ?: time());
        db_run("UPDATE rules SET post_id = ?, post_label = ?, wait_next = 0 WHERE id = ? AND (post_id = '' OR post_id IS NULL)", [$postId, $lbl, (int)$r['id']]);
        $n++;
    }
    return $n;
}

/** Worker: look for the post a waiting rule is waiting for (backup for the webhook). */
function check_waiting_rules(): void {
    $pages = db()->query("SELECT DISTINCT p.* FROM pages p JOIN rules r ON r.page_id = p.id WHERE COALESCE(r.wait_next, 0) = 1 AND (r.post_id = '' OR r.post_id IS NULL)")->fetchAll();
    foreach ($pages as $pg) {
        $res = graph('GET', $pg['id'] . '/posts', ['fields' => 'id,message,created_time', 'limit' => 15], $pg['token']);
        if (gerr($res)) continue;
        $st = db()->prepare("SELECT MIN(created_at) FROM rules WHERE page_id = ? AND COALESCE(wait_next, 0) = 1 AND (post_id = '' OR post_id IS NULL)");
        $st->execute([$pg['id']]);
        $since = strtotime((string)fetch_col($st) . ' UTC') - 120;
        $fresh = array_filter($res['data'] ?? [], fn($p) => isset($p['created_time']) && strtotime($p['created_time']) >= $since);
        if (!$fresh) continue;
        usort($fresh, fn($a, $b) => strcmp($a['created_time'], $b['created_time']));   // oldest first: each rule gets the first post that fits it
        foreach ($fresh as $p) {
            bind_next_rules($pg['id'], (string)$p['id'], strtotime($p['created_time']),
                mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($p['message'] ?? ''))), 0, 60), (string)($p['message'] ?? ''));
        }
    }
}
