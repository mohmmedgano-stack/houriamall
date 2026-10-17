<?php
$me = current_user();
if (is_post()) {
    $a = in_str('a');
    switch ($a) {
        case 'profile':
            $tz = in_str('timezone', 'Africa/Cairo');
            if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('المنطقة الزمنية مش صحيحة');
            db_update('users', (int)$me['id'], ['name' => in_str('name', $me['name']) ?: $me['name'], 'timezone' => $tz, 'updated_at' => now()]);
            flash('اتحفظ ✅'); break;
        case 'password':
            if (!password_verify((string)($_POST['current'] ?? ''), $me['password_hash'])) throw new InvalidArgumentException('الباسورد الحالي غلط');
            $pw = (string)($_POST['password'] ?? '');
            if ($e = validate_password($pw)) throw new InvalidArgumentException($e);
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            q('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $me['id']]);
            $_SESSION['pwv'] = substr(hash('sha256', $hash), 0, 16);
            audit('user.password_change', 'user', $me['id']);
            flash('اتغير الباسورد ✅'); break;
        case 'stages':
            require_perm('settings.manage');
            foreach ((array)($_POST['stage'] ?? []) as $sid => $s) {
                $name = trim((string)($s['name'] ?? ''));
                if ($name === '') continue;
                db_update('pipeline_stages', (int)$sid, ['name' => mb_substr($name, 0, 40), 'position' => (int)($s['position'] ?? 0),
                    'is_contacted' => !empty($s['is_contacted']) ? 1 : 0, 'is_qualified' => !empty($s['is_qualified']) ? 1 : 0]);
            }
            if ($new = trim(in_str('new_stage'))) db_insert('pipeline_stages', ['name' => mb_substr($new, 0, 40), 'position' => (int)q_val('SELECT MAX(position) + 1 FROM pipeline_stages'), 'is_contacted' => 1]);
            audit('settings.stages'); flash('اتحفظت مراحل الـ Pipeline ✅'); break;
        case 'dedupe':
            require_perm('settings.manage');
            $rules = ['phone' => !empty($_POST['phone']), 'email' => !empty($_POST['email']), 'window_days' => max(0, min(3650, (int)in_int('window_days', 90))), 'action' => in_str('action') === 'merge' ? 'merge' : 'flag'];
            set_setting('dedupe_rules', json_encode($rules));
            audit('settings.dedupe', null, null, $rules); flash('اتحفظت قواعد التكرار ✅'); break;
        case 'demo_seed':
            require_perm('settings.manage');
            $n = demo_seed();
            set_mode('demo');
            flash("اتعملت بيانات تجريبية ($n عميل) واتنقلت لوضع Demo ✅"); break;
        case 'demo_clear':
            require_perm('settings.manage');
            $n = demo_clear();
            flash("اتمسحت كل البيانات التجريبية ($n عميل)"); break;
    }
    redirect(url('settings'));
}
$stages = q_all('SELECT * FROM pipeline_stages ORDER BY position');
$dedupe = dedupe_rules();
layout_start('الإعدادات'); ?>
<div class="grid g2">
<div class="stack">
  <div class="card"><h2>حسابي</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="profile">
      <div class="fgrid"><div><label class="f">الاسم</label><input type="text" name="name" value="<?= h($me['name']) ?>"></div>
        <div><label class="f">المنطقة الزمنية لعرض التواريخ</label><input type="text" name="timezone" value="<?= h($me['timezone'] ?: 'Africa/Cairo') ?>" dir="ltr"></div></div>
      <div class="hint ltr"><?= h($me['email']) ?> · <?= h(q_val('SELECT name FROM roles WHERE key = ?', [$me['role']])) ?></div>
      <button class="btn" style="margin-top:12px">حفظ</button></form></div>
  <div class="card"><h2>تغيير الباسورد</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="password">
      <label class="f">الباسورد الحالي</label><input type="password" name="current" required autocomplete="current-password">
      <label class="f">الباسورد الجديد</label><input type="password" name="password" required minlength="10" autocomplete="new-password">
      <button class="btn" style="margin-top:12px">تغيير</button></form></div>
  <?php if (can('settings.manage')): ?>
  <div class="card"><h2>البيانات التجريبية (Demo)</h2>
    <p class="muted small">بتتعمل على عملاء معلّمين DEMO وبتظهر بس في وضع Demo. عمرها ما بتدخل في أرقام وضع Live.</p>
    <div class="row">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="demo_seed"><button class="btn ghost">إنشاء / تجديد بيانات تجريبية</button></form>
      <form method="post" data-confirm="هيتمسح كل البيانات التجريبية. متأكد؟"><?= csrf_field() ?><input type="hidden" name="a" value="demo_clear"><button class="btn danger">مسح البيانات التجريبية</button></form>
    </div></div>
  <div class="card"><h2>النسخ الاحتياطي</h2>
    <p class="muted small">نسخة كاملة من قاعدة البيانات (SQLite). احفظها في مكان آمن لأنها فيها بيانات العملاء والليدز. مفاتيح التكاملات متشفرة بمفتاح منفصل (<code>data/secret.key</code>) ولازم تاخد نسخة منه برضه عشان تقدر ترجعها.</p>
    <form method="post" action="<?= h(url('export', ['kind' => 'backup'])) ?>"><?= csrf_field() ?><button class="btn ghost"><?= icon('download') ?> تنزيل نسخة احتياطية</button></form></div>
  <?php endif; ?>
</div>

<?php if (can('settings.manage')): ?>
<div class="stack">
  <div class="card"><h2>مراحل الـ Pipeline</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="stages">
      <div class="tbl"><table class="t"><thead><tr><th>الترتيب</th><th>الاسم</th><th>اتواصلنا</th><th>مؤهل</th><th>النوع</th></tr></thead><tbody>
      <?php foreach ($stages as $s): ?><tr>
        <td style="width:70px"><input type="number" name="stage[<?= (int)$s['id'] ?>][position]" value="<?= (int)$s['position'] ?>"></td>
        <td style="min-width:130px"><input type="text" name="stage[<?= (int)$s['id'] ?>][name]" value="<?= h($s['name']) ?>"></td>
        <td><input type="checkbox" name="stage[<?= (int)$s['id'] ?>][is_contacted]" value="1" <?= $s['is_contacted'] ? 'checked' : '' ?>></td>
        <td><input type="checkbox" name="stage[<?= (int)$s['id'] ?>][is_qualified]" value="1" <?= $s['is_qualified'] ? 'checked' : '' ?>></td>
        <td class="small"><?= $s['is_won'] ? '<span class="badge b-ok">بيع</span>' : ($s['is_lost'] ? '<span class="badge b-bad">خسارة</span>' : 'مفتوح') ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
      <label class="f">إضافة مرحلة</label><input type="text" name="new_stage" placeholder="مثلاً: Appointment">
      <div class="hint">"اتواصلنا" و"مؤهل" بيتحسب منهم Contact Rate و Qualified Rate. مرحلتي البيع والخسارة ثابتين.</div>
      <button class="btn" style="margin-top:12px">حفظ</button></form></div>

  <div class="card"><h2>قواعد اكتشاف الليدز المكررة</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="dedupe">
      <label class="check"><input type="checkbox" name="phone" value="1" <?= $dedupe['phone'] ? 'checked' : '' ?>> نفس رقم التليفون (بعد توحيد الصيغة 01… / +20…)</label><br>
      <label class="check"><input type="checkbox" name="email" value="1" <?= $dedupe['email'] ? 'checked' : '' ?>> نفس الإيميل</label>
      <div class="fgrid"><div><label class="f">خلال كام يوم</label><input type="number" name="window_days" value="<?= (int)$dedupe['window_days'] ?>" min="0"><div class="hint">0 = أي وقت</div></div>
        <div><label class="f">لما يتلاقى تكرار</label><select name="action"><?= select_opts(['flag' => 'يتسجل ويتعلّم مكرر', 'merge' => 'ميتسجلش، ويتضاف نشاط على الليد الأصلي'], $dedupe['action']) ?></select></div></div>
      <div class="hint">المقارنة بتكون جوه نفس العميل بس.</div>
      <button class="btn" style="margin-top:12px">حفظ</button></form></div>
</div>
<?php endif; ?>
</div>
<?php layout_end();
