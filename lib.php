<?php
declare(strict_types=1);

const APP_NAME = 'Gano Auto Reply';
define('DATA_DIR', __DIR__ . '/data');

/* ---------------- Database ---------------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0750, true); }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
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
    return $pdo;
}

function setting(string $k, ?string $default = null): ?string {
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : (string)$v;
}

function set_setting(string $k, ?string $v): void {
    db()->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

function get_page(string $id): ?array {
    $st = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function now(): string { return gmdate('Y-m-d H:i:s'); }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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
        graph('POST', $pg['id'] . '/subscribed_apps', ['subscribed_fields' => 'feed'], $pg['token']);
    }
    return ['count' => $count];
}

/** Register the app-level webhook (Page → feed) pointing at this install. */
function subscribe_app_webhook(): ?string {
    $appId = setting('app_id');
    if (!$appId || !setting('app_secret')) return 'حط App ID و App Secret الأول';
    $r = graph('POST', $appId . '/subscriptions', [
        'object' => 'page',
        'callback_url' => base_url() . '/webhook.php',
        'fields' => 'feed',
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
    $st = db()->prepare("SELECT * FROM rules WHERE page_id = ? AND active = 1 AND (post_id = ? OR post_id = '' OR post_id IS NULL)");
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

/**
 * Reply to one comment according to the matching rule. Idempotent per comment_id.
 * Returns ['status' => no_rule|duplicate|done, ...]
 */
function process_comment(array $page, string $postId, string $commentId, string $fromName, string $message, ?int $createdTs = null): array {
    $rule = find_rule($page['id'], $postId, $message);
    if (!$rule) return ['status' => 'no_rule'];

    $ins = db()->prepare("INSERT OR IGNORE INTO log (comment_id, page_id, post_id, rule_id, from_name, message, comment_status, dm_status, error, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', '', ?)");
    $ins->execute([$commentId, $page['id'], $postId, (int)$rule['id'], $fromName, mb_substr($message, 0, 500), now()]);
    if ($ins->rowCount() === 0) return ['status' => 'duplicate'];

    $errors = []; $cs = 'skipped'; $ds = 'skipped';

    if ((int)$rule['send_dm'] === 1 && ($txt = pick($rule['dm_messages']))) {
        if ($createdTs && (time() - $createdTs) > (7 * 86400 - 600)) {
            $ds = 'expired';
        } else {
            $r = graph('POST', $page['id'] . '/messages', [
                'recipient' => json_encode(['comment_id' => $commentId]),
                'message'   => json_encode(['text' => personalize($txt, $fromName)], JSON_UNESCAPED_UNICODE),
            ], $page['token']);
            if ($e = gerr($r)) { $ds = 'failed'; $errors[] = 'رسالة: ' . $e; } else { $ds = 'sent'; }
        }
    }

    if ((int)$rule['send_comment'] === 1 && ($txt = pick($rule['comment_replies']))) {
        $r = graph('POST', $commentId . '/comments', ['message' => personalize($txt, $fromName)], $page['token']);
        if ($e = gerr($r)) { $cs = 'failed'; $errors[] = 'كومنت: ' . $e; } else { $cs = 'sent'; }
    }

    db()->prepare('UPDATE log SET comment_status = ?, dm_status = ?, error = ? WHERE comment_id = ?')
        ->execute([$cs, $ds, implode(' | ', $errors), $commentId]);

    return ['status' => 'done', 'comment' => $cs, 'dm' => $ds, 'errors' => $errors];
}
