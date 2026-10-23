<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/tt_lib.php';
start_session();
require_login();

if (!setting('tt_app_id') || !setting('tt_app_secret')) { flash('حط App ID و Secret بتوع تيك توك الأول', 'err'); redirect('index.php?p=tt'); }

if (isset($_GET['error']) || isset($_GET['error_code'])) {
    flash('اتلغى الربط: ' . ($_GET['error_description'] ?? $_GET['error'] ?? $_GET['error_code']), 'err');
    redirect('index.php?p=tt');
}

$code = (string)($_GET['auth_code'] ?? $_GET['code'] ?? '');
if ($code === '') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['tt_state'] = $state;
    redirect(tt_authorize_url($state));
}

$state = (string)($_GET['state'] ?? '');
if (!empty($_SESSION['tt_state']) && !hash_equals($_SESSION['tt_state'], $state)) {
    flash('الطلب مش سليم، جرب تاني', 'err');
    redirect('index.php?p=tt');
}
unset($_SESSION['tt_state']);

$res = tt_exchange_code($code);
if (isset($res['error'])) { flash('فشل ربط تيك توك: ' . $res['error'], 'err'); redirect('index.php?p=tt'); }
$acc = tt_account($res['id']);
flash('تم ربط حساب تيك توك ' . ($acc['username'] ? '@' . $acc['username'] : '') . ' ✅ فعّله واعمل قواعد الرد');
redirect('index.php?p=tt_acc&id=' . urlencode($res['id']));
