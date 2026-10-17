<?php
$id = (int)in_int('id');
$c = q_one('SELECT * FROM clients WHERE id = ?', [$id]);
if (!$c || (!can_client($id) && !($c['status'] === 'archived' && is_admin() && (int)$c['is_demo'] === (mode() === 'demo' ? 1 : 0)))) throw new Forbidden('client');

if (is_post()) {
    $a = in_str('a');
    require_perm('clients.manage');
    switch ($a) {
        case 'update':
            client_update($id, $_POST);
            if (!empty($_FILES['logo']) && ($e = client_logo_save($id, $_FILES['logo']))) flash($e, 'err');
            flash('اتحفظت بيانات العميل ✅'); break;
        case 'status':
            client_set_status($id, in_str('status')); flash('اتغيرت حالة العميل'); break;
        case 'delete':
            if (!can('users.manage')) throw new Forbidden('delete');
            if (in_str('confirm_name') !== $c['name']) { flash('اكتب اسم العميل بالظبط عشان تأكد الحذف', 'err'); break; }
            client_delete($id); flash('اتمسح العميل وكل بياناته'); redirect(url('clients'));
        case 'account_save':
            $aid = in_int('account_id');
            if ($aid && account_client_id($aid) !== $id) throw new Forbidden('account');
            account_save($aid, $id, $_POST); flash('اتحفظ الحساب الإعلاني ✅'); break;
        case 'account_status':
            $aid = (int)in_int('account_id');
            if (account_client_id($aid) !== $id) throw new Forbidden('account');
            db_update('ad_accounts', $aid, ['status' => in_str('status') === 'active' ? 'active' : 'disabled', 'updated_at' => now()]);
            audit('account.status', 'ad_account', $aid, ['status' => in_str('status')]);
            flash('اتغيرت حالة الحساب'); break;
        case 'access':
            require_perm('users.manage');
            $want = array_map('intval', (array)($_POST['users'] ?? []));
            foreach (q_all("SELECT id FROM users WHERE role NOT IN ('owner','admin')") as $u) {
                $has = (bool)q_val('SELECT 1 FROM user_clients WHERE user_id = ? AND client_id = ?', [$u['id'], $id]);
                $w = in_array((int)$u['id'], $want, true);
                if ($w && !$has) q('INSERT INTO user_clients (user_id, client_id) VALUES (?, ?)', [$u['id'], $id]);
                if (!$w && $has) q('DELETE FROM user_clients WHERE user_id = ? AND client_id = ?', [$u['id'], $id]);
            }
            audit('client.access', 'client', $id, ['users' => $want]);
            flash('اتحدّثت صلاحيات الوصول ✅'); break;
    }
    redirect(url('client', ['id' => $id]));
}

$accounts = q_all('SELECT a.*, (SELECT COUNT(*) FROM campaigns cp WHERE cp.ad_account_id = a.id) campaigns,
    (SELECT MAX(date_end) FROM performance_snapshots p WHERE p.ad_account_id = a.id) last_data FROM ad_accounts a WHERE a.client_id = ? ORDER BY a.name', [$id]);
$editAcc = in_int('edit_account') ? q_one('SELECT * FROM ad_accounts WHERE id = ? AND client_id = ?', [in_int('edit_account'), $id]) : null;
$pages = function_exists('autoreply_pages_for_client') ? autoreply_pages_for_client($id) : [];
layout_start($c['name']); ?>
<div class="row" style="margin-bottom:14px">
  <?php if ($c['logo_path']): ?><img src="<?= h($c['logo_path']) ?>" alt="" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:#fff;border:1px solid var(--line)"><?php endif; ?>
  <div><b style="font-size:17px"><?= h($c['name']) ?></b> <?= $c['is_demo'] ? '<span class="badge b-demo">DEMO</span>' : '' ?>
    <div class="muted small"><?= h($c['industry'] ?: '—') ?> · <span class="ltr"><?= h($c['currency']) ?> · <?= h($c['timezone']) ?></span></div></div>
  <span class="sp"></span>
  <?php if (can('analytics.view')): ?><a class="btn sm ghost" href="<?= h(url('dashboard', ['client' => $id])) ?>">الأداء</a><?php endif; ?>
  <?php if (can('crm.view')): ?><a class="btn sm ghost" href="<?= h(url('crm', ['client' => $id])) ?>">الليدز</a><?php endif; ?>
  <?php if (can('budgets.view')): ?><a class="btn sm ghost" href="<?= h(url('budgets', ['client' => $id])) ?>">الميزانيات</a><?php endif; ?>
  <?php if (can('finance.view')): ?><a class="btn sm ghost" href="<?= h(url('finance', ['client' => $id])) ?>">الأتعاب</a><?php endif; ?>
</div>

<div class="grid g2">
<div class="card">
  <h2>الحسابات الإعلانية</h2>
  <?php if ($accounts): ?>
  <div class="tbl"><table class="t cards">
    <thead><tr><th>الحساب</th><th>المنصة</th><th>العملة</th><th class="n">حملات</th><th>آخر بيانات</th><th>المزامنة</th><th></th></tr></thead>
    <tbody><?php foreach ($accounts as $a): ?>
      <tr><td class="main-cell"><?= h($a['name']) ?><div class="muted small ltr"><?= h($a['external_id'] ? 'act_' . $a['external_id'] : 'بدون رقم') ?></div></td>
        <td data-l="المنصة"><?= h(ucfirst($a['platform'])) ?></td><td data-l="العملة" class="ltr"><?= h($a['currency']) ?></td>
        <td data-l="حملات" class="n"><?= (int)$a['campaigns'] ?></td><td data-l="آخر بيانات"><?= h($a['last_data'] ?: '—') ?></td>
        <td data-l="المزامنة"><?php if ($a['sync_enabled']): ?><span class="status-dot <?= $a['last_sync_error'] ? 'bad' : 'ok' ?>"></span><?= $a['last_sync_at'] ? h(fmt_dt($a['last_sync_at'])) : 'لسه' ?><?php if ($a['last_sync_error']): ?><div class="small" style="color:var(--bad)"><?= h(mb_substr($a['last_sync_error'], 0, 120)) ?></div><?php endif; ?><?php else: ?><span class="muted">يدوي / CSV</span><?php endif; ?>
          <?= $a['status'] !== 'active' ? ' <span class="badge">موقوف</span>' : '' ?></td>
        <td><?php if (can('clients.manage')): ?><a class="btn sm ghost" href="<?= h(url('client', ['id' => $id, 'edit_account' => $a['id']])) ?>#acc">تعديل</a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php else: echo empty_state('مفيش حسابات إعلانية', ' ضيف حساب يدوي واستورد بياناته CSV، أو اربطه بـ Meta من التكاملات.'); endif; ?>

  <?php if (can('clients.manage')): ?>
  <hr id="acc">
  <h2><?= $editAcc ? 'تعديل الحساب' : 'إضافة حساب إعلاني' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="account_save"><?php if ($editAcc): ?><input type="hidden" name="account_id" value="<?= (int)$editAcc['id'] ?>"><?php endif; ?>
    <div class="fgrid">
      <div><label class="f">اسم الحساب *</label><input type="text" name="name" required value="<?= h($editAcc['name'] ?? '') ?>"></div>
      <div><label class="f">رقم الحساب (act_…)</label><input type="text" name="external_id" dir="ltr" value="<?= h($editAcc['external_id'] ?? '') ?>" placeholder="123456789"></div>
      <div><label class="f">المنصة</label><select name="platform"><?= select_opts(['meta' => 'Meta', 'google' => 'Google', 'tiktok' => 'TikTok'], $editAcc['platform'] ?? 'meta') ?></select></div>
      <div><label class="f">عملة الحساب</label><select name="currency"><?= select_opts(array_combine(currencies(), currencies()), $editAcc['currency'] ?? $c['currency']) ?></select></div>
    </div>
    <div class="hint">عملة الحساب لازم تطابق عملة الحساب في Ads Manager. مينفعش تتغير بعد ما يبقى عليه بيانات.</div>
    <div class="row" style="margin-top:12px"><button class="btn"><?= $editAcc ? 'حفظ' : 'إضافة' ?></button>
      <?php if ($editAcc): ?><a class="btn ghost" href="<?= h(url('client', ['id' => $id])) ?>">إلغاء</a><?php endif; ?></div>
  </form>
  <?php if ($editAcc): ?>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="a" value="account_status"><input type="hidden" name="account_id" value="<?= (int)$editAcc['id'] ?>">
    <input type="hidden" name="status" value="<?= $editAcc['status'] === 'active' ? 'disabled' : 'active' ?>"><button class="btn sm ghost"><?= $editAcc['status'] === 'active' ? 'إيقاف الحساب' : 'تفعيل الحساب' ?></button></form>
  <?php endif; endif; ?>
</div>

<div class="stack">
<?php if (can('clients.manage')): ?>
<div class="card">
  <h2>بيانات العميل</h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="update">
    <div class="fgrid">
      <div><label class="f">الاسم *</label><input type="text" name="name" required value="<?= h($c['name']) ?>"></div>
      <div><label class="f">المجال</label><input type="text" name="industry" value="<?= h($c['industry']) ?>"></div>
      <div><label class="f">العملة</label><select name="currency"><?= select_opts(array_combine(currencies(), currencies()), $c['currency']) ?></select></div>
      <div><label class="f">المنطقة الزمنية</label><input type="text" name="timezone" value="<?= h($c['timezone']) ?>" dir="ltr"></div>
      <div><label class="f">المسؤول</label><input type="text" name="contact_name" value="<?= h($c['contact_name']) ?>"></div>
      <div><label class="f">التليفون</label><input type="text" name="contact_phone" value="<?= h($c['contact_phone']) ?>" dir="ltr"></div>
      <div><label class="f">الشعار</label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></div>
    </div>
    <label class="f">ملاحظات</label><textarea name="notes" style="min-height:60px"><?= h($c['notes']) ?></textarea>
    <div style="margin-top:12px"><button class="btn">حفظ</button></div>
  </form>
  <hr>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="status">
    <span class="muted">الحالة:</span>
    <?php foreach (['active' => 'نشط', 'paused' => 'متوقف', 'archived' => 'أرشفة'] as $s => $l): ?>
      <button class="btn sm <?= $c['status'] === $s ? '' : 'ghost' ?>" name="status" value="<?= $s ?>"><?= $l ?></button>
    <?php endforeach; ?>
  </form>
</div>
<?php endif; ?>

<?php if (can('users.manage')):
  $staff = q_all("SELECT u.*, EXISTS(SELECT 1 FROM user_clients uc WHERE uc.user_id = u.id AND uc.client_id = ?) has FROM users u WHERE u.role NOT IN ('owner','admin') AND u.active = 1 ORDER BY u.name", [$id]); ?>
<div class="card">
  <h2>مين يشوف العميل ده</h2>
  <p class="muted small">المالك والمديرين بيشوفوا كل العملاء. حدد باقي الفريق.</p>
  <?php if ($staff): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="access">
    <?php foreach ($staff as $s): ?><label class="check" style="display:flex;margin:6px 0"><input type="checkbox" name="users[]" value="<?= (int)$s['id'] ?>" <?= $s['has'] ? 'checked' : '' ?>> <?= h($s['name']) ?> <span class="muted small">(<?= h($s['role']) ?>)</span></label><?php endforeach; ?>
    <button class="btn sm" style="margin-top:8px">حفظ</button>
  </form>
  <?php else: echo '<p class="muted">مفيش أعضاء فريق غير المديرين. ضيفهم من صفحة المستخدمين.</p>'; endif; ?>
</div>
<?php endif; ?>

<?php if ($pages): ?>
<div class="card"><h2>صفحات الرد التلقائي</h2>
  <?php foreach ($pages as $p): ?><div class="row"><span class="status-dot <?= $p['active'] ? 'ok' : '' ?>"></span><?= h($p['name']) ?> <span class="sp"></span><a class="small" href="<?= h(url('autoreply', ['page' => $p['id']])) ?>">السجل</a></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (can('users.manage')): ?>
<details class="box"><summary style="color:var(--bad)">حذف العميل نهائياً</summary>
  <p class="muted small">ده هيمسح العميل وكل حساباته وحملاته وليدزه وتقاريره. مفيش رجوع.</p>
  <form method="post" data-confirm="متأكد؟ الحذف نهائي."><?= csrf_field() ?><input type="hidden" name="a" value="delete">
    <label class="f">اكتب اسم العميل للتأكيد</label><input type="text" name="confirm_name" autocomplete="off">
    <button class="btn danger sm" style="margin-top:10px">حذف نهائي</button></form>
</details>
<?php endif; ?>
</div>
</div>
<?php layout_end();
