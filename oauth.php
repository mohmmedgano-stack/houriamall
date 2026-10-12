<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_login();

$appId = setting('app_id');
$secret = setting('app_secret');
if (!$appId || !$secret) { flash('حط App ID و App Secret في الإعدادات الأول', 'err'); redirect('index.php?p=settings'); }

$redirectUri = base_url() . '/oauth.php';

if (isset($_GET['error'])) {
    flash('اتلغى الربط: ' . ($_GET['error_description'] ?? $_GET['error']), 'err');
    redirect('index.php');
}

if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $scope = implode(',', [
        'pages_show_list', 'pages_read_engagement', 'pages_read_user_content',
        'pages_manage_engagement', 'pages_manage_metadata', 'pages_messaging', 'business_management',
    ]);
    redirect('https://www.facebook.com/' . graph_version() . '/dialog/oauth?' . http_build_query([
        'client_id' => $appId, 'redirect_uri' => $redirectUri, 'state' => $state, 'scope' => $scope,
    ]));
}

if (!hash_equals($_SESSION['oauth_state'] ?? '', (string)($_GET['state'] ?? ''))) {
    flash('الطلب مش سليم، جرب تاني', 'err');
    redirect('index.php');
}
unset($_SESSION['oauth_state']);

$r = graph('GET', 'oauth/access_token', [
    'client_id' => $appId, 'client_secret' => $secret,
    'redirect_uri' => $redirectUri, 'code' => (string)$_GET['code'],
]);
if ($e = gerr($r)) { flash('فشل الربط: ' . $e, 'err'); redirect('index.php'); }

$res = import_pages((string)$r['access_token']);
if (isset($res['error'])) flash('فشل جلب الصفحات: ' . $res['error'], 'err');
else { set_setting('token_problem', ''); flash('تم ربط ' . $res['count'] . ' صفحة ✅ فعّل الصفحات اللي عايزها من تحت'); }
redirect('index.php');
