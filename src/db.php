<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    $path = db_path();
    if ($pdo && ($GLOBALS['__db_path'] ?? '') === $path) return $pdo;
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0750, true);
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_TIMEOUT => 30]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 30000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    try { $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA synchronous = NORMAL'); } catch (Throwable $e) {}
    $GLOBALS['__db_path'] = $path;
    migrate($pdo);
    return $pdo;
}

function db_path(): string {
    return (string)($GLOBALS['CC_DB_PATH'] ?? (DATA_DIR . '/app.sqlite'));
}

/** Reset the cached connection (tests switch databases). */
function db_reset(string $path): void {
    $GLOBALS['CC_DB_PATH'] = $path;
    $GLOBALS['__db_path'] = '';
}

function migrate(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
    $done = array_map('intval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    foreach (migrations() as $v => $sql) {
        if (in_array($v, $done, true)) continue;
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')->execute([$v, now()]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migration $v failed: " . $e->getMessage(), 0, $e);
        }
        if ($v === 1) seed_defaults($pdo);
    }
}

/** Fixed reference data the app relies on. */
function seed_defaults(PDO $pdo): void {
    $roles = [
        ['owner', 'المالك', ['*'], 1],
        ['admin', 'مدير', ['*', '!users.delete_owner'], 2],
        ['buyer', 'ميديا باير', ['analytics.view', 'analytics.import', 'budgets.manage', 'alerts.manage', 'reports.manage', 'creatives.manage', 'audits.manage', 'copy.use', 'audience.view', 'automation.manage', 'crm.view', 'crm.view_all', 'leadquality.view', 'autoreply.view', 'clients.view'], 3],
        ['sales', 'مبيعات', ['crm.view', 'crm.edit', 'clients.view'], 4],
        ['viewer', 'مشاهدة فقط', ['analytics.view', 'reports.view', 'leadquality.view', 'clients.view', 'audience.view'], 5],
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO roles (key, name, permissions, position) VALUES (?, ?, ?, ?)');
    foreach ($roles as $r) $st->execute([$r[0], $r[1], json_encode($r[2]), $r[3]]);

    $stages = [
        ['New', 0, '#64748b', 0, 0, 0, 0], ['Contacted', 1, '#0ea5e9', 1, 0, 0, 0],
        ['Qualified', 2, '#8b5cf6', 1, 1, 0, 0], ['Interested', 3, '#f59e0b', 1, 1, 0, 0],
        ['Negotiation', 4, '#ec4899', 1, 1, 0, 0], ['Won', 5, '#16a34a', 1, 1, 1, 0], ['Lost', 6, '#dc2626', 1, 0, 0, 1],
    ];
    $st = $pdo->prepare('INSERT INTO pipeline_stages (name, position, color, is_contacted, is_qualified, is_won, is_lost) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($stages as $s) $st->execute($s);
}

/* ---------- query helpers ---------- */
function q(string $sql, array $params = []): PDOStatement {
    $tries = 0;
    while (true) {
        try {
            $st = db()->prepare($sql);
            $st->execute(array_values($params) === $params ? $params : $params);
            return $st;
        } catch (PDOException $e) {
            $locked = stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false;
            if (!$locked || ++$tries >= 6) throw $e;
            usleep(400000 * $tries);
        }
    }
}
function q_all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function q_one(string $sql, array $params = []): ?array { $st = q($sql, $params); $r = $st->fetch(); $st->closeCursor(); return $r ?: null; }
function q_val(string $sql, array $params = []) { $st = q($sql, $params); $v = $st->fetchColumn(); $st->closeCursor(); return $v === false ? null : $v; }
function q_col(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN); }

/** Insert a row from an associative array; returns the new id. */
function db_insert(string $table, array $row): int {
    $cols = array_keys($row);
    q('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    return (int)db()->lastInsertId();
}
function db_update(string $table, int $id, array $row): void {
    if (!$row) return;
    $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($row)));
    q("UPDATE $table SET $sets WHERE id = ?", array_merge(array_values($row), [$id]));
}
function in_list(array $ids): string { return $ids ? implode(',', array_map('intval', $ids)) : '0'; }

function tx(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function setting(string $k, ?string $default = null): ?string {
    $v = q_val('SELECT v FROM settings WHERE k = ?', [$k]);
    return $v === null ? $default : (string)$v;
}
function set_setting(string $k, ?string $v): void { q('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, $v]); }
