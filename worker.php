<?php
// Background worker — run every minute by cron.
//  1) retries replies that hit a temporary error
//  2) every 10 minutes, catches up on comments whose webhook never arrived
//  3) replies to old comments for running jobs, in small batches
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

$lock = fopen(DATA_DIR . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;   // previous run still going

$safe = function (callable $fn, string $what) {
    try { $fn(); } catch (Throwable $e) { error_log("[autoreply worker] $what: " . $e->getMessage()); set_setting('worker_error', now() . ' ' . $what . ': ' . $e->getMessage()); }
};

set_setting('worker_seen', now());

// housekeeping once a day
if (setting('last_prune', '') !== gmdate('Y-m-d')) {
    $safe(function () {
        db()->exec("DELETE FROM log WHERE created_at < datetime('now', '-120 days')");
        db()->exec("DELETE FROM jobs WHERE status = 'done' AND updated_at < datetime('now', '-30 days')");
        set_setting('last_prune', gmdate('Y-m-d'));
    }, 'prune');
}

$deadline = time() + 50;

$safe(fn() => retry_due(10), 'retry');

if (time() - (int)setting('last_sweep', '0') >= 600) {
    set_setting('last_sweep', (string)time());
    $safe(fn() => sweep_missed(), 'sweep');
}

while (time() < $deadline) {
    $jobs = db()->query("SELECT * FROM jobs WHERE status = 'running' AND (next_run IS NULL OR next_run <= datetime('now')) ORDER BY updated_at ASC")->fetchAll();
    if (!$jobs) break;
    foreach ($jobs as $job) {
        if (time() >= $deadline) break;
        $safe(fn() => run_job_batch($job), 'job ' . $job['id']);
        sleep(1);
    }
    $safe(fn() => retry_due(5), 'retry');
}
flock($lock, LOCK_UN);
