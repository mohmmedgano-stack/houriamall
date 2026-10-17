<?php
if (is_post()) {
    check_csrf();
    [$ok, $msg] = attempt_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
    if (!$ok) { flash($msg, 'err'); redirect(url('home')); }
    $to = (string)($_POST['next'] ?? '');
    redirect(preg_match('~^\?r=[a-z]+[A-Za-z0-9_=&%.\-]*$~', $to) ? base_path() . '/' . $to : url('home'));
}
$next = ($_GET['r'] ?? '') !== '' ? '?' . (string)($_SERVER['QUERY_STRING'] ?? '') : '';
auth_page_start('تسجيل الدخول'); ?>
<h1>تسجيل الدخول</h1>
<p class="muted">ادخل بحسابك عشان تكمل.</p>
<form method="post" action="<?= h(url('login')) ?>"><?= csrf_field() ?><input type="hidden" name="next" value="<?= h($next) ?>">
  <label class="f">الإيميل</label><input type="email" name="email" required autofocus autocomplete="username" dir="ltr">
  <label class="f">الباسورد</label><input type="password" name="password" required autocomplete="current-password">
  <div style="margin-top:18px"><button class="btn" style="width:100%">دخول</button></div>
</form>
<?php auth_page_end();
