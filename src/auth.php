<?php
declare(strict_types=1);

/* ---------- current user ---------- */
function current_user(): ?array {
    if (array_key_exists('CC_USER', $GLOBALS)) return $GLOBALS['CC_USER'];
    $u = null;
    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id) {
        $u = q_one('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);
        // log out if the password changed after this session started
        if ($u && ($_SESSION['pwv'] ?? '') !== substr(hash('sha256', $u['password_hash']), 0, 16)) $u = null;
    }
    return $GLOBALS['CC_USER'] = $u;
}
function set_current_user(?array $u): void { $GLOBALS['CC_USER'] = $u; }
function uid(): ?int { $u = current_user(); return $u ? (int)$u['id'] : null; }

function role_permissions(string $role): array {
    static $cache = [];
    if (!isset($cache[$role])) {
        $p = q_val('SELECT permissions FROM roles WHERE key = ?', [$role]);
        $cache[$role] = $p ? (json_decode((string)$p, true) ?: []) : [];
    }
    return $cache[$role];
}
function can(string $perm, ?array $u = null): bool {
    $u ??= current_user();
    if (!$u) return false;
    $p = role_permissions((string)$u['role']);
    if (in_array('!' . $perm, $p, true)) return false;
    if (in_array('*', $p, true) || in_array($perm, $p, true)) return true;
    // "x.manage" implies "x.view"
    [$area, $act] = array_pad(explode('.', $perm, 2), 2, '');
    if ($act === 'view' && (in_array("$area.manage", $p, true) || in_array("$area.edit", $p, true))) return true;
    return false;
}
function is_admin(?array $u = null): bool { $u ??= current_user(); return $u && in_array($u['role'], ['owner', 'admin'], true); }

class Forbidden extends Exception {}
function require_perm(string $perm): void { if (!can($perm)) throw new Forbidden($perm); }

/* ---------- login ---------- */
const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_SECONDS = 900;

function login_key(string $email): string { return hash('sha256', mb_strtolower(trim($email)) . '|' . client_ip()); }

/** Returns [ok(bool), message]. Throttled per email+IP so nobody can lock the owner out from elsewhere. */
function attempt_login(string $email, string $password): array {
    $k = login_key($email);
    $row = q_one('SELECT * FROM login_attempts WHERE k = ?', [$k]);
    if ($row && (int)$row['fails'] >= LOGIN_MAX_FAILS && time() - (int)$row['last_at'] < LOGIN_LOCK_SECONDS) {
        return [false, 'محاولات غلط كتير. استنى ' . ceil((LOGIN_LOCK_SECONDS - (time() - (int)$row['last_at'])) / 60) . ' دقيقة.'];
    }
    $u = q_one('SELECT * FROM users WHERE email = ? AND active = 1', [trim($email)]);
    // always run password_verify so response time doesn't reveal whether the email exists
    $ok = password_verify($password, $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltIJ0nP6M5hWg7Oa9F0nQvM5Hq3kFhWa');
    if (!$u || !$ok) {
        $fails = ($row && time() - (int)$row['last_at'] < LOGIN_LOCK_SECONDS) ? (int)$row['fails'] + 1 : 1;
        q('INSERT OR REPLACE INTO login_attempts (k, fails, last_at) VALUES (?, ?, ?)', [$k, $fails, time()]);
        audit('login.failed', 'user', $u['id'] ?? null, ['email' => $email]);
        return [false, 'الإيميل أو الباسورد غلط'];
    }
    q('DELETE FROM login_attempts WHERE k = ?', [$k]);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        q('UPDATE users SET password_hash = ? WHERE id = ?', [$u['password_hash'], $u['id']]);
    }
    login_as($u);
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $u['id']]);
    audit('login', 'user', $u['id']);
    return [true, ''];
}
function login_as(array $u): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['pwv'] = substr(hash('sha256', $u['password_hash']), 0, 16);
    $_SESSION['mode'] = $_SESSION['mode'] ?? 'live';
    set_current_user($u);
}
function logout(): void {
    audit('logout', 'user', uid());
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    set_current_user(null);
}

function validate_password(string $pw): ?string {
    if (mb_strlen($pw) < 10) return 'الباسورد لازم يكون 10 حروف على الأقل';
    if (!preg_match('/\d/', $pw) || !preg_match('/\p{L}/u', $pw)) return 'الباسورد لازم يكون فيه حروف وأرقام';
    return null;
}

/* ---------- first-run setup (needs the setup key from data/setup.key) ---------- */
function needs_setup(): bool { return (int)q_val('SELECT COUNT(*) FROM users') === 0; }
function setup_key(): string {
    $f = DATA_DIR . '/setup.key';
    if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(12)), LOCK_EX); @chmod($f, 0600); }
    return trim((string)file_get_contents($f));
}

function create_user(string $name, string $email, string $password, string $role, array $clientIds = []): int {
    $email = trim($email);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('الاسم والإيميل مطلوبين والإيميل لازم يكون صحيح');
    if ($e = validate_password($password)) throw new InvalidArgumentException($e);
    if (!q_val('SELECT 1 FROM roles WHERE key = ?', [$role])) throw new InvalidArgumentException('صلاحية غير معروفة');
    if (q_val('SELECT 1 FROM users WHERE email = ?', [$email])) throw new InvalidArgumentException('الإيميل ده مستخدم قبل كده');
    return tx(function () use ($name, $email, $password, $role, $clientIds) {
        $id = db_insert('users', ['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role, 'active' => 1, 'created_at' => now()]);
        set_user_clients($id, $clientIds);
        return $id;
    });
}
function set_user_clients(int $userId, array $clientIds): void {
    q('DELETE FROM user_clients WHERE user_id = ?', [$userId]);
    foreach (array_unique(array_map('intval', $clientIds)) as $c) {
        if ($c > 0) q('INSERT OR IGNORE INTO user_clients (user_id, client_id) VALUES (?, ?)', [$userId, $c]);
    }
}
function users_list(): array { return q_all('SELECT id, name, email, role, active FROM users ORDER BY active DESC, name'); }
