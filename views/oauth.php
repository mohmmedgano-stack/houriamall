<?php
// Facebook Login for the Meta Marketing API. The code is exchanged server-side; the token never reaches the browser.
$cfg = meta_config();
if (empty($cfg['app_id']) || empty($cfg['app_secret'])) { flash('حط App ID و App Secret الأول', 'err'); redirect(url('integrations')); }
$base = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
$redirectUri = $base . '/?r=oauth';
if (isset($_GET['error'])) { flash('اتلغى الربط: ' . (string)($_GET['error_description'] ?? $_GET['error']), 'err'); redirect(url('integrations')); }
if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(16)); $_SESSION['meta_oauth_state'] = $state;
    $p = ['client_id' => $cfg['app_id'], 'redirect_uri' => $redirectUri, 'state' => $state];
    if (!empty($cfg['login_config_id'])) $p += ['config_id' => $cfg['login_config_id'], 'response_type' => 'code', 'override_default_response_type' => 'true'];
    else $p['scope'] = implode(',', META_SCOPES);
    redirect('https://www.facebook.com/' . meta_version() . '/dialog/oauth?' . http_build_query($p));
}
if (!hash_equals((string)($_SESSION['meta_oauth_state'] ?? ''), (string)($_GET['state'] ?? ''))) { flash('الطلب مش سليم، جرّب تاني', 'err'); redirect(url('integrations')); }
unset($_SESSION['meta_oauth_state']);
[$status, $body] = meta_http('GET', 'https://graph.facebook.com/' . meta_version() . '/oauth/access_token',
    ['client_id' => $cfg['app_id'], 'client_secret' => $cfg['app_secret'], 'redirect_uri' => $redirectUri, 'code' => (string)$_GET['code']]);
$j = json_decode($body, true);
if (empty($j['access_token'])) { flash('فشل الربط: ' . ($j['error']['message'] ?? 'HTTP ' . $status), 'err'); redirect(url('integrations')); }
$patch = ['access_token' => $j['access_token'], 'token_type' => 'user'];
try { $patch = array_merge($patch, meta_exchange_long_lived($j['access_token'])); } catch (MetaError $e) {}
meta_save_config($patch, 'testing');
$t = meta_test_connection();
audit('meta.oauth_connected');
$t['ok'] ? flash('اتربط Meta ✅ باسم ' . $t['name'] . ' — اختار الحسابات الإعلانية واربطها بالعملاء') : flash('الربط تم بس الاختبار فشل: ' . ($t['error'] ?? 'صلاحيات ناقصة'), 'err');
redirect(url('integrations', ['list' => 1]));
