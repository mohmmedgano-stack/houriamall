<?php
declare(strict_types=1);
/*
 * Bridge to the existing "Gano Auto Reply" tool (ganoadsai.online/autoreply).
 * Read-only: we never write to its database, never read its tokens, and never move its webhook/OAuth URLs.
 * Rules are still edited in the tool itself; here we map pages to clients, show activity, and feed failures to automations.
 */

function ar_db_path(): string { return (string)(setting('autoreply_db') ?: realpath(APP_ROOT . '/../autoreply/data/app.sqlite') ?: APP_ROOT . '/../autoreply/data/app.sqlite'); }
function ar_url(): string { return (string)(setting('autoreply_url') ?: '../autoreply/'); }

function ar_db(): ?PDO {
    static $pdo = false;
    $path = ar_db_path();
    if ($pdo !== false && ($GLOBALS['__ar_path'] ?? '') === $path) return $pdo;
    $GLOBALS['__ar_path'] = $path;
    if (!is_file($path) || !is_readable($path)) return $pdo = null;
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_TIMEOUT => 5]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->query('SELECT 1 FROM pages LIMIT 1');
    } catch (Throwable $e) { log_error('autoreply.db', $e); $pdo = null; }
    return $pdo;
}
function ar_reset(): void { $GLOBALS['__ar_path'] = ''; }
function ar_q(string $sql, array $p = []): array { $db = ar_db(); if (!$db) return []; $st = $db->prepare($sql); $st->execute($p); $r = $st->fetchAll(); $st->closeCursor(); return $r; }
function ar_val(string $sql, array $p = []) { $r = ar_q($sql, $p); return $r ? array_values($r[0])[0] : null; }
function ar_setting(string $k): ?string { $v = ar_val('SELECT v FROM settings WHERE k = ?', [$k]); return $v === null ? null : (string)$v; }

/** Health of the tool, from its own heartbeat settings. */
function ar_status(): array {
    if (!ar_db()) return ['connected' => false];
    $ws = ar_setting('worker_seen'); $wh = ar_setting('last_webhook');
    return ['connected' => true, 'worker_seen' => $ws, 'worker_ok' => $ws && time() - strtotime($ws . ' UTC') < 300, 'last_webhook' => $wh,
        'token_problem' => ar_setting('token_problem') ?: null, 'worker_error' => ar_setting('worker_error') ?: null,
        'pages' => (int)ar_val('SELECT COUNT(*) FROM pages'), 'active_pages' => (int)ar_val('SELECT COUNT(*) FROM pages WHERE active = 1'),
        'rules' => (int)ar_val('SELECT COUNT(*) FROM rules WHERE active = 1')];
}

/** Pages with their activity. Only safe columns are read (never the token). */
function ar_pages(): array {
    $pages = ar_q('SELECT id, name, active, picture, updated_at FROM pages ORDER BY active DESC, name');
    if (!$pages) return [];
    $map = array_column(q_all('SELECT page_id, client_id FROM autoreply_pages'), 'client_id', 'page_id');
    $since7 = gmdate('Y-m-d H:i:s', time() - 7 * 86400); $since1 = gmdate('Y-m-d H:i:s', time() - 86400);
    $stats = [];
    foreach (ar_q("SELECT page_id, COUNT(*) total, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) d1,
        SUM(CASE WHEN comment_status IN ('sent','fallback') THEN 1 ELSE 0 END) replied, SUM(CASE WHEN dm_status = 'sent' THEN 1 ELSE 0 END) dms,
        SUM(CASE WHEN comment_status = 'failed' OR dm_status = 'failed' THEN 1 ELSE 0 END) failed, SUM(CASE WHEN comment_status IN ('hidden','deleted') THEN 1 ELSE 0 END) blocked
        FROM log WHERE created_at >= ? GROUP BY page_id", [$since1, $since7]) as $s) $stats[$s['page_id']] = $s;
    $rules = array_column(ar_q('SELECT page_id, COUNT(*) n FROM rules WHERE active = 1 GROUP BY page_id'), 'n', 'page_id');
    foreach ($pages as &$p) { $p['client_id'] = isset($map[$p['id']]) ? (int)$map[$p['id']] : null; $p['stats'] = $stats[$p['id']] ?? null; $p['rules'] = (int)($rules[$p['id']] ?? 0); }
    return $pages;
}
/** Pages the current user may see: mapped to one of their clients (admins also see unmapped pages). */
function ar_visible_pages(): array {
    $ids = scope_client_ids();
    return array_values(array_filter(ar_pages(), fn($p) => $p['client_id'] ? in_array($p['client_id'], $ids, true) : is_admin()));
}
function autoreply_pages_for_client(int $clientId): array { return ar_db() ? array_values(array_filter(ar_pages(), fn($p) => $p['client_id'] === $clientId)) : []; }

function ar_map_page(string $pageId, ?int $clientId): void {
    if ($clientId && !can_client($clientId)) throw new Forbidden('client');
    $name = (string)ar_val('SELECT name FROM pages WHERE id = ?', [$pageId]);
    if ($name === '' && !q_val('SELECT 1 FROM autoreply_pages WHERE page_id = ?', [$pageId])) throw new InvalidArgumentException('الصفحة مش موجودة في أداة الرد');
    q('INSERT INTO autoreply_pages (page_id, page_name, client_id) VALUES (?, ?, ?) ON CONFLICT(page_id) DO UPDATE SET client_id = excluded.client_id, page_name = excluded.page_name', [$pageId, $name, $clientId]);
    audit('autoreply.map', 'page', $pageId, ['client_id' => $clientId]);
}

function ar_log(array $pageIds, array $f, int $per = 30): array {
    if (!$pageIds || !ar_db()) return [[], 0];
    $w = 'l.page_id IN (' . implode(',', array_fill(0, count($pageIds), '?')) . ')'; $p = $pageIds;
    if (!empty($f['status'])) {
        if ($f['status'] === 'failed') $w .= " AND (l.comment_status = 'failed' OR l.dm_status = 'failed')";
        elseif ($f['status'] === 'blocked') $w .= " AND l.comment_status IN ('hidden','deleted')";
        elseif ($f['status'] === 'retry') $w .= " AND (l.comment_status = 'retry' OR l.dm_status = 'retry')";
    }
    if (!empty($f['q'])) { $w .= ' AND (l.from_name LIKE ? OR l.message LIKE ?)'; $p[] = '%' . $f['q'] . '%'; $p[] = '%' . $f['q'] . '%'; }
    $total = (int)ar_val("SELECT COUNT(*) FROM log l WHERE $w", $p);
    $off = max(0, ((int)($f['page'] ?? 1) - 1) * $per);
    $rows = ar_q("SELECT l.comment_id, l.page_id, l.post_id, l.from_name, l.message, l.comment_status, l.dm_status, l.error, l.created_at, l.attempts, l.images_status, r.name rule_name
        FROM log l LEFT JOIN rules r ON r.id = l.rule_id WHERE $w ORDER BY l.created_at DESC LIMIT $per OFFSET $off", $p);
    return [$rows, $total];
}

/**
 * Worker: turn new reply failures into one autoreply.failed event per page per hour (no event floods),
 * so the Automation Hub can notify someone. Remembers how far it has scanned.
 */
function ar_scan_failures(): int {
    if (!ar_db()) return 0;
    $last = setting('ar_last_scan') ?: gmdate('Y-m-d H:i:s', time() - 3600);
    $rows = ar_q("SELECT page_id, COUNT(*) n, MAX(created_at) mx FROM log WHERE created_at > ? AND (comment_status = 'failed' OR dm_status = 'failed') GROUP BY page_id", [$last]);
    $map = array_column(q_all('SELECT page_id, client_id, page_name FROM autoreply_pages'), null, 'page_id');
    $n = 0;
    foreach ($rows as $r) {
        $ref = $r['page_id'] . '@' . gmdate('Y-m-d H', time());
        if (q_val("SELECT 1 FROM events WHERE type = 'autoreply.failed' AND ref = ?", [$ref])) continue;
        emit_event('autoreply.failed', isset($map[$r['page_id']]) ? (int)$map[$r['page_id']]['client_id'] : null, $ref,
            ['page_id' => $r['page_id'], 'page_name' => $map[$r['page_id']]['page_name'] ?? $r['page_id'], 'failures' => (int)$r['n']]);
        $n++;
    }
    set_setting('ar_last_scan', max($last, (string)(ar_val('SELECT MAX(created_at) FROM log') ?? $last)));
    return $n;
}
function ar_status_badge(?string $s): string {
    $m = ['sent' => ['b-ok', 'اتبعت'], 'fallback' => ['b-info', 'رد بديل'], 'skipped' => ['', 'مفيش'], 'failed' => ['b-bad', 'فشل'], 'retry' => ['b-warn', 'هيعيد'], 'pending' => ['b-info', 'جاري'],
        'expired' => ['b-warn', 'أقدم من 7 أيام'], 'closed' => ['b-warn', 'الرسايل مقفولة'], 'already' => ['', 'اتبعت قبل كده'], 'hidden' => ['b-warn', 'اتخفى'], 'deleted' => ['b-bad', 'اتمسح']];
    [$c, $l] = $m[$s ?? ''] ?? ['', (string)$s];
    return '<span class="badge ' . $c . '">' . h($l) . '</span>';
}
