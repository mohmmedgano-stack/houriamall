<?php
// First run: creating the owner account requires the one-time key stored in data/setup.key (never shown on the web).
$key = setup_key();
if (is_post()) {
    check_csrf();
    if (!hash_equals($key, trim((string)($_POST['setup_key'] ?? '')))) {
        sleep(1);
        flash('مفتاح التجهيز غلط', 'err');
        redirect(url('home'));
    }
    try {
        if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) throw new InvalidArgumentException('الباسوردين مش زي بعض');
        $id = create_user((string)$_POST['name'], (string)$_POST['email'], (string)$_POST['password'], 'owner');
        @unlink(DATA_DIR . '/setup.key');
        login_as(q_one('SELECT * FROM users WHERE id = ?', [$id]));
        audit('setup.owner_created', 'user', $id);
        flash('تم إنشاء حساب المالك ✅');
        redirect(url('home'));
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
        redirect(url('home'));
    }
}
auth_page_start('تجهيز المنصة'); ?>
<h1>تجهيز المنصة لأول مرة</h1>
<p class="muted">اعمل حساب المالك. محتاج <b>مفتاح التجهيز</b> اللي اتولد على السيرفر في ملف <code>data/setup.key</code>، عشان محدش غيرك يقدر يعمل الحساب ده.</p>
<form method="post"><?= csrf_field() ?>
  <label class="f">مفتاح التجهيز</label><input type="text" name="setup_key" required autocomplete="off" dir="ltr">
  <label class="f">الاسم</label><input type="text" name="name" required>
  <label class="f">الإيميل</label><input type="email" name="email" required dir="ltr">
  <label class="f">الباسورد</label><input type="password" name="password" required minlength="10" autocomplete="new-password">
  <div class="hint">10 حروف على الأقل وفيه حروف وأرقام.</div>
  <label class="f">أكّد الباسورد</label><input type="password" name="password2" required minlength="10" autocomplete="new-password">
  <div style="margin-top:18px"><button class="btn" style="width:100%">إنشاء الحساب</button></div>
</form>
<?php auth_page_end();
