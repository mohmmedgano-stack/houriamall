<?php
if (is_post()) {
    $a = in_str('a');
    if ($a === 'create') {
        $role = in_str('role');
        if ($role === 'owner' && current_user()['role'] !== 'owner') throw new Forbidden('owner');
        $id = create_user(in_str('name'), in_str('email'), (string)($_POST['password'] ?? ''), $role, (array)($_POST['clients'] ?? []));
        audit('user.create', 'user', $id, ['role' => $role]);
        flash('اتضاف المستخدم ✅ ابعتله الإيميل والباسورد بطريقة آمنة');
    } elseif ($a === 'update') {
        $id = (int)in_int('id');
        $u = q_one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) redirect(url('users'));
        if ($u['role'] === 'owner' && current_user()['role'] !== 'owner') throw new Forbidden('owner');
        $role = in_str('role', $u['role']);
        if ($u['role'] === 'owner' && $role !== 'owner' && (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'owner' AND active = 1") <= 1)
            throw new InvalidArgumentException('لازم يفضل فيه مالك واحد على الأقل');
        if ($role === 'owner' && current_user()['role'] !== 'owner') throw new Forbidden('owner');
        $active = in_str('active') === '1' ? 1 : 0;
        if ($id === uid() && !$active) throw new InvalidArgumentException('مينفعش توقف حسابك انت');
        db_update('users', $id, ['name' => in_str('name', $u['name']), 'role' => $role, 'active' => $active, 'updated_at' => now()]);
        set_user_clients($id, (array)($_POST['clients'] ?? []));
        $pw = (string)($_POST['password'] ?? '');
        if ($pw !== '') {
            if ($e = validate_password($pw)) throw new InvalidArgumentException($e);
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            audit('user.password_reset', 'user', $id);
        }
        audit('user.update', 'user', $id, ['role' => $role, 'active' => $active]);
        flash('اتحفظ ✅');
    }
    redirect(url('users'));
}
$roles = q_all('SELECT * FROM roles ORDER BY position');
$roleNames = array_column($roles, 'name', 'key');
if (current_user()['role'] !== 'owner') unset($roleNames['owner']);
$users = q_all('SELECT u.*, (SELECT GROUP_CONCAT(client_id) FROM user_clients WHERE user_id = u.id) cids FROM users u ORDER BY u.active DESC, u.name');
$allClients = q_all("SELECT id, name, is_demo FROM clients WHERE status <> 'archived' ORDER BY is_demo, name");
$edit = in_int('edit') ? q_one('SELECT * FROM users WHERE id = ?', [in_int('edit')]) : null;
$editCids = $edit ? array_map('intval', q_col('SELECT client_id FROM user_clients WHERE user_id = ?', [$edit['id']])) : [];
layout_start('المستخدمين والصلاحيات'); ?>
<div class="grid g2">
<div class="card">
  <h2>الفريق</h2>
  <div class="tbl"><table class="t cards">
    <thead><tr><th>الاسم</th><th>الإيميل</th><th>الدور</th><th>العملاء</th><th>آخر دخول</th><th></th></tr></thead>
    <tbody><?php foreach ($users as $u): $n = $u['cids'] ? count(explode(',', $u['cids'])) : 0; ?>
      <tr><td class="main-cell"><?= h($u['name']) ?> <?= $u['active'] ? '' : '<span class="badge">موقوف</span>' ?></td>
        <td data-l="الإيميل" class="ltr"><?= h($u['email']) ?></td>
        <td data-l="الدور"><span class="badge b-acc"><?= h(q_val('SELECT name FROM roles WHERE key = ?', [$u['role']])) ?></span></td>
        <td data-l="العملاء"><?= in_array($u['role'], ['owner', 'admin'], true) ? 'الكل' : $n ?></td>
        <td data-l="آخر دخول"><?= h(fmt_dt($u['last_login_at'])) ?></td>
        <td><a class="btn sm ghost" href="<?= h(url('users', ['edit' => $u['id']])) ?>">تعديل</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <details class="box" style="margin-top:14px"><summary>الصلاحيات حسب الدور</summary>
    <ul class="small"><li><b>المالك / المدير:</b> كل حاجة، بما فيها المستخدمين والتكاملات والأرباح.</li>
      <li><b>ميديا باير:</b> التحليلات والميزانيات والتنبيهات والتقارير والكريتيف والأتمتة للعملاء المخصصين ليه.</li>
      <li><b>مبيعات:</b> الليدز بس، وبيشوف الليدز المسندة ليه فقط.</li>
      <li><b>مشاهدة فقط:</b> بيشوف الأداء والتقارير من غير تعديل.</li></ul></details>
</div>

<div class="card">
  <h2><?= $edit ? 'تعديل ' . h($edit['name']) : 'مستخدم جديد' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="<?= $edit ? 'update' : 'create' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="fgrid">
      <div><label class="f">الاسم</label><input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>"></div>
      <?php if (!$edit): ?><div><label class="f">الإيميل</label><input type="email" name="email" required dir="ltr"></div><?php endif; ?>
      <div><label class="f">الدور</label><select name="role"><?= select_opts($roleNames, $edit['role'] ?? 'sales') ?></select></div>
      <div><label class="f"><?= $edit ? 'باسورد جديد (اختياري)' : 'الباسورد' ?></label><input type="password" name="password" <?= $edit ? '' : 'required' ?> minlength="10" autocomplete="new-password"></div>
      <?php if ($edit): ?><div><label class="f">الحالة</label><select name="active"><?= select_opts(['1' => 'نشط', '0' => 'موقوف'], (string)$edit['active']) ?></select></div><?php endif; ?>
    </div>
    <label class="f">العملاء المسموح بيهم (لغير المديرين)</label>
    <div class="fgrid"><?php foreach ($allClients as $c): ?><label class="check"><input type="checkbox" name="clients[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $editCids, true) ? 'checked' : '' ?>> <?= h($c['name']) ?><?= $c['is_demo'] ? ' <span class="badge b-demo">DEMO</span>' : '' ?></label><?php endforeach; ?></div>
    <div class="row" style="margin-top:14px"><button class="btn"><?= $edit ? 'حفظ' : 'إضافة' ?></button><?php if ($edit): ?><a class="btn ghost" href="<?= h(url('users')) ?>">إلغاء</a><?php endif; ?></div>
  </form>
</div>
</div>
<?php layout_end();
