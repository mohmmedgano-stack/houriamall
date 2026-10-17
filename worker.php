<?php
/**
 * Background worker — run every minute by cron:  * * * * * php /path/to/command/worker.php
 * Each job is isolated: one failing job never stops the others, and errors are logged without secrets.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/src/bootstrap.php';
restore_exception_handler();

$lock = fopen(DATA_DIR . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;   // previous run still going

$results = [];
$job = function (string $name, callable $fn, int $everySeconds = 60) use (&$results) {
    $key = 'job_last_' . $name;
    if ($everySeconds > 60 && time() - (int)setting($key, '0') < $everySeconds) return;
    set_setting($key, (string)time());
    try { $results[$name] = $fn(); }
    catch (Throwable $e) { log_error('worker.' . $name, $e); set_setting('worker_error', now() . ' ' . $name . ': ' . redact($e->getMessage())); }
};

set_setting('worker_seen', now());
if (function_exists('meta_sync_due')) $job('meta_sync', fn() => meta_sync_due(), 60);
if (function_exists('meta_leads_sync_due')) $job('meta_leads', fn() => meta_leads_sync_due(), 300);
$job('watchdog', fn() => run_watchdog(), 900);
$job('overdue', fn() => scan_overdue_leads(), 300);
$job('autoreply', fn() => ar_scan_failures(), 300);
$job('invoices', fn() => invoices_generate_monthly() + scan_due_invoices(), 6 * 3600);
$job('automation', fn() => process_automation(), 60);
$job('prune', function () {
    q("DELETE FROM events WHERE processed_at IS NOT NULL AND created_at < datetime('now', '-60 days')");
    q("DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < datetime('now', '-90 days')");
    q("DELETE FROM login_attempts WHERE last_at < ?", [time() - 86400]);
    return true;
}, 86400);
flock($lock, LOCK_UN);
if (in_array('-v', $argv ?? [], true)) echo json_encode($results, JSON_UNESCAPED_UNICODE), "\n";
