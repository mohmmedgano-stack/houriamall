<?php
declare(strict_types=1);

const APP_NAME = 'Media Buying Command Center';
const APP_VERSION = '1.0.0';
if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));
if (!defined('DATA_DIR')) define('DATA_DIR', (string)(getenv('MBCC_DATA_DIR') ?: APP_ROOT . '/data'));

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

foreach (['util', 'ui', 'migrations', 'db', 'crypto', 'audit', 'auth', 'scope', 'metrics', 'xlsx', 'importer', 'analytics', 'perf_ui', 'export', 'home',
          'clients', 'budget', 'auditor', 'audience', 'creative', 'crm', 'leadquality', 'watchdog', 'reports',
          'events', 'automation', 'finance', 'copygen', 'autoreply', 'meta', 'ai', 'demo', 'demo_perf', 'demo_biz', 'leadquality', 'watchdog', 'reports'] as $m) {
    $f = __DIR__ . "/$m.php";
    if (is_file($f)) require_once $f;
}

set_exception_handler(function (Throwable $e) {
    log_error('uncaught', $e);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;padding:40px;direction:rtl">حصل خطأ غير متوقع واتسجل. ارجع للصفحة اللي قبلها وجرّب تاني.</body>';
});
