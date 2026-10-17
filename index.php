<?php
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
start_session();

/** route => [view file, permission or null] */
const ROUTES = [
    'home' => ['home', null], 'dashboard' => ['dashboard', 'analytics.view'], 'campaign' => ['campaign', 'analytics.view'],
    'import' => ['import', 'analytics.import'],
    'clients' => ['clients', 'clients.view'], 'client' => ['client', 'clients.view'],
    'users' => ['users', 'users.manage'], 'settings' => ['settings', null], 'auditlog' => ['auditlog', 'auditlog.view'],
    'notifications' => ['notifications', null],
    'budgets' => ['budgets', 'budgets.view'], 'auditor' => ['auditor', 'audits.view'], 'audience' => ['audience', 'audience.view'],
    'creatives' => ['creatives', 'creatives.view'],
    'crm' => ['crm', 'crm.view'], 'lead' => ['lead', 'crm.view'], 'leadquality' => ['leadquality', 'leadquality.view'],
    'alerts' => ['alerts', 'alerts.view'], 'reports' => ['reports', 'reports.view'], 'report' => ['report', 'reports.view'],
    'automation' => ['automation', 'automation.view'], 'autoreply' => ['autoreply', 'autoreply.view'],
    'copy' => ['copy', 'copy.use'], 'finance' => ['finance', 'finance.view'], 'integrations' => ['integrations', 'integrations.manage'],
    'export' => ['export', null], 'oauth' => ['oauth', 'integrations.manage'],
];

$route = (string)($_GET['r'] ?? 'home');

/* ---------- first run ---------- */
if (needs_setup()) {
    $GLOBALS['CC_ROUTE'] = 'setup';
    require __DIR__ . '/views/setup.php';
    exit;
}

/* ---------- login / logout ---------- */
if ($route === 'logout' && is_post()) { check_csrf(); logout(); redirect(url('home')); }
if (!current_user()) {
    $GLOBALS['CC_ROUTE'] = 'login';
    require __DIR__ . '/views/login.php';
    exit;
}
if ($route === 'mode' && is_post()) {
    check_csrf();
    set_mode((string)($_POST['mode'] ?? 'live'));
    $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
    redirect($back !== '' && parse_url($back, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '') ? $back : url('home'));
}

if (!isset(ROUTES[$route])) { http_response_code(404); $route = 'home'; }
[$view, $perm] = ROUTES[$route];
$GLOBALS['CC_ROUTE'] = $route;

try {
    if ($perm !== null) require_perm($perm);
    if (is_post()) check_csrf();
    require __DIR__ . '/views/' . $view . '.php';
} catch (Forbidden $e) {
    log_error('forbidden', $route . ': ' . $e->getMessage());
    http_response_code(403);
    layout_start('غير مسموح');
    echo '<div class="card">' . empty_state('مش عندك صلاحية للصفحة دي', ' كلم صاحب الحساب لو محتاج وصول.') . '</div>';
    layout_end();
} catch (InvalidArgumentException $e) {
    // validation errors thrown from modules → show them on the page the user came from
    flash($e->getMessage(), 'err');
    redirect((string)($_SERVER['HTTP_REFERER'] ?? url($route)));
}
