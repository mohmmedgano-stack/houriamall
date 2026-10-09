<?php
// Houria Mall — shared server helpers (database, auth, JSON, files).
// The database and uploaded files live OUTSIDE public_html so that
// redeploying the site (which replaces public_html) never wipes data.

declare(strict_types=1);

if (!defined('HOURIA_API')) {
    http_response_code(403);
    exit;
}

const SESSION_TTL = 60 * 60 * 24 * 7;      // admin login lasts 7 days
const LOGIN_WINDOW = 15 * 60;              // rate-limit window for login attempts
const LOGIN_MAX_ATTEMPTS = 10;
const MAX_IMAGE_BYTES = 6 * 1024 * 1024;   // per decoded image

function data_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $override = getenv('HOURIA_DATA_DIR');
    $candidates = [];
    if ($override) $candidates[] = $override;
    // public_html/api -> public_html -> <domain dir>/hourya_data
    $candidates[] = dirname(__DIR__, 2) . '/hourya_data';
    // Fallback inside the site (protected by .htaccess); survives only until the next deploy.
    $candidates[] = __DIR__ . '/_data';

    foreach ($candidates as $c) {
        if (!is_dir($c)) @mkdir($c, 0750, true);
        if (is_dir($c) && is_writable($c)) {
            foreach (['media', 'proofs'] as $sub) {
                if (!is_dir("$c/$sub")) @mkdir("$c/$sub", 0750, true);
            }
            if (!file_exists("$c/.htaccess")) @file_put_contents("$c/.htaccess", "Require all denied\nDeny from all\n");
            return $dir = $c;
        }
    }
    fail(500, 'storage_unavailable', 'مش قادر أكتب البيانات على السيرفر');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . data_dir() . '/hourya.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (
        id TEXT PRIMARY KEY, data TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reservations (
        id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, product_id TEXT NOT NULL,
        phone_norm TEXT NOT NULL, status TEXT NOT NULL, data TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_res_product ON reservations(product_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_res_phone ON reservations(phone_norm)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
        id TEXT PRIMARY KEY, data TEXT NOT NULL, is_read INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (token TEXT PRIMARY KEY, expires_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, ts INTEGER NOT NULL)');

    // First run: store defaults + the initial admin login (admin / admin)
    if (kv_get('settings', $pdo) === null) {
        $seed = json_decode((string)@file_get_contents(__DIR__ . '/seed.json'), true) ?: [];
        kv_set('settings', $seed['settings'] ?? [], $pdo);
    }
    if (kv_get('admin', $pdo) === null) {
        kv_set('admin', ['username' => 'admin', 'hash' => password_hash('admin', PASSWORD_DEFAULT)], $pdo);
    }
}

function kv_get(string $k, ?PDO $pdo = null)
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare('SELECT v FROM kv WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : json_decode($v, true);
}

function kv_set(string $k, $value, ?PDO $pdo = null): void
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare('INSERT INTO kv (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $st->execute([$k, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

// ───────────── HTTP helpers ─────────────

function send_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $code, string $message): void
{
    send_json(['ok' => false, 'error' => $code, 'message' => $message], $status);
}

function body(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $raw = file_get_contents('php://input') ?: '';
    $b = $raw === '' ? [] : (json_decode($raw, true) ?? []);
    if (!is_array($b)) $b = [];
    return $b;
}

function client_ip(): string
{
    return (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0');
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function rand_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function normalize_phone(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($d) === 12 && str_starts_with($d, '20')) $d = '0' . substr($d, 2);
    return $d;
}

function str_field($v, int $max = 2000): string
{
    $s = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($s, 0, $max);
}

// ───────────── Auth ─────────────

function bearer_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v;
    }
    if (preg_match('/Bearer\s+([A-Za-z0-9]+)/', (string)$h, $m)) return $m[1];
    // <img> tags can't send headers — proofs pass the token as ?t=
    return preg_match('/^[A-Za-z0-9]{20,}$/', (string)($_GET['t'] ?? '')) ? (string)$_GET['t'] : '';
}

function require_admin(): void
{
    $t = bearer_token();
    if ($t === '') fail(401, 'unauthorized', 'لازم تسجل دخول');
    $st = db()->prepare('SELECT expires_at FROM sessions WHERE token = ?');
    $st->execute([hash('sha256', $t)]);
    $exp = $st->fetchColumn();
    if ($exp === false || (int)$exp < time()) fail(401, 'unauthorized', 'انتهت الجلسة، سجل دخول تاني');
}

// ───────────── Images ─────────────

/** Store a data: URL image; returns the stored file name. */
function store_data_url_image(string $dataUrl, string $kind): string
{
    if (!preg_match('#^data:image/(png|jpe?g|webp|gif);base64,(.+)$#s', $dataUrl, $m)) {
        fail(422, 'bad_image', 'نوع الصورة مش مدعوم');
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) === 0) fail(422, 'bad_image', 'الصورة بايظة');
    if (strlen($bytes) > MAX_IMAGE_BYTES) fail(413, 'image_too_large', 'الصورة كبيرة قوي');
    $info = @getimagesizefromstring($bytes);
    if ($info === false) fail(422, 'bad_image', 'الملف ده مش صورة');
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime']] ?? null;
    if (!$ext) fail(422, 'bad_image', 'نوع الصورة مش مدعوم');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $dir = data_dir() . '/' . ($kind === 'proof' ? 'proofs' : 'media');
    if (file_put_contents("$dir/$name", $bytes) === false) fail(500, 'write_failed', 'مش قادر أحفظ الصورة');
    return $name;
}

function safe_file_name(string $f): ?string
{
    return preg_match('/^[a-f0-9]{24}\.(png|jpg|webp|gif)$/', $f) ? $f : null;
}

function serve_file(string $path): void
{
    if (!is_file($path)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
