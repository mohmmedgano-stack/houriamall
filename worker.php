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

// self-heal: jobs that stopped because of a temporary problem (DB lock, network…) start again by themselves
$safe(function () {
    db_run("UPDATE jobs SET status = 'running', next_run = NULL, error = '' WHERE status = 'error'
        AND (error LIKE '%locked%' OR error LIKE '%busy%' OR error LIKE '%Network%' OR error LIKE '%Bad response%' OR error LIKE '%timed out%' OR error LIKE '%#2)%' OR error LIKE '%#1)%')");
}, 'resume');

// one-time upgrade: subscribe pages to message events too (needed for sending photos)
if (setting('subs_version', '') !== PAGE_FIELDS) {
    $safe(function () {
        if (setting('base_url', '') === '') return;            // wait until the dashboard has been opened once
        if (subscribe_app_webhook() === null) {
            foreach (db()->query('SELECT * FROM pages WHERE active = 1')->fetchAll() as $pg) {
                graph('POST', $pg['id'] . '/subscribed_apps', ['subscribed_fields' => PAGE_FIELDS], $pg['token']);
            }
            set_setting('subs_version', PAGE_FIELDS);
        }
    }, 'subscribe');
}

// one-time cleanup: old rows that showed "Activity already replied to" as an error
if (setting('fix_10900', '') !== '2') {
    $safe(function () {
        db_run("UPDATE log SET dm_status = 'already', error = '' WHERE dm_status = 'failed' AND error LIKE '%(#10900)%' AND error NOT LIKE '%كومنت:%'");
        db_run("UPDATE log SET dm_status = 'already' WHERE dm_status = 'failed' AND error LIKE '%(#10900)%'");
        db_run("UPDATE log SET dm_status = 'closed', error = 'رسالة: الشخص ده مينفعش يوصله رسايل خاصة من الصفحة' WHERE dm_status = 'failed' AND (error LIKE '%(#10903)%' OR error LIKE '%(#551)%')");
        set_setting('fix_10900', '2');
    }, 'fix10900');
}

// one-time: comments that were skipped earlier only because they were older than 7 days → try the private reply now
if (setting('retry_expired', '') !== '1') {
    $safe(function () {
        db_run("UPDATE log SET dm_status = 'retry', next_retry = datetime('now'), attempts = 0 WHERE dm_status = 'expired' AND created_at > datetime('now', '-30 days')");
        set_setting('retry_expired', '1');
    }, 'retry_expired');
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
