<?php
// Background worker — run every minute by cron. Replies to old comments in small batches.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

$lock = fopen(DATA_DIR . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;   // previous run still going

set_setting('worker_seen', now());

// housekeeping once a day: keep the log to the last 120 days
if (setting('last_prune', '') !== gmdate('Y-m-d')) {
    db()->exec("DELETE FROM log WHERE created_at < datetime('now', '-120 days')");
    db()->exec("DELETE FROM jobs WHERE status = 'done' AND updated_at < datetime('now', '-30 days')");
    set_setting('last_prune', gmdate('Y-m-d'));
}
$deadline = time() + 50;
while (time() < $deadline) {
    $jobs = db()->query("SELECT * FROM jobs WHERE status = 'running' ORDER BY updated_at ASC")->fetchAll();
    if (!$jobs) break;
    foreach ($jobs as $job) {
        if (time() >= $deadline) break;
        try { run_job_batch($job); }
        catch (Throwable $e) {
            db()->prepare("UPDATE jobs SET status = 'error', error = ?, updated_at = ? WHERE id = ?")->execute([$e->getMessage(), now(), $job['id']]);
        }
        sleep(1);
    }
}
flock($lock, LOCK_UN);
