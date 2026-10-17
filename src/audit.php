<?php
declare(strict_types=1);

/** Record a sensitive action. Details are redacted so secrets never land in the log. */
function audit(string $action, ?string $entity = null, $entityId = null, array $details = []): void {
    try {
        db_insert('audit_logs', ['user_id' => $GLOBALS['CC_USER']['id'] ?? null, 'action' => $action, 'entity' => $entity,
            'entity_id' => $entityId === null ? null : (string)$entityId,
            'details_json' => $details ? json_encode(redact($details), JSON_UNESCAPED_UNICODE) : null,
            'ip' => client_ip(), 'created_at' => now()]);
    } catch (Throwable $e) { error_log('[mbcc audit] ' . $e->getMessage()); }
}

/** Log an operational error without any secret values. */
function log_error(string $where, Throwable|string $e): void {
    $msg = $e instanceof Throwable ? $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() : $e;
    error_log('[mbcc ' . $where . '] ' . redact($msg));
    if (!is_dir(DATA_DIR)) return;
    @file_put_contents(DATA_DIR . '/app.log', gmdate('c') . " [$where] " . redact($msg) . "\n", FILE_APPEND | LOCK_EX);
}

function notify(int $userId, string $title, string $body = '', string $link = '', string $severity = 'info'): void {
    db_insert('notifications', ['user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link, 'severity' => $severity, 'created_at' => now()]);
}
/** Users who can see a client (admins + assigned users). */
function client_audience(int $clientId, ?string $perm = null): array {
    $rows = q_all("SELECT DISTINCT u.* FROM users u LEFT JOIN user_clients uc ON uc.user_id = u.id
        WHERE u.active = 1 AND (u.role IN ('owner','admin') OR uc.client_id = ?)", [$clientId]);
    return $perm ? array_values(array_filter($rows, fn($u) => can($perm, $u))) : $rows;
}
