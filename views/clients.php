<?php
if (is_post()) {
    require_perm('clients.manage');
    $a = in_str('a');
    if ($a === 'create') {
        $id = client_create($_POST, mode() === 'demo');
        if (!is_admin()) set_user_clients(uid(), array_merge(q_col('SELECT client_id FROM user_clients WHERE user_id = ?', [uid()]), [$id]));
        if (!empty($_FILES['logo']) && ($e = client_logo_save($id, $_FILES['logo']))) flash($e, 'err');
        flash('اتضاف العميل ✅ ضيف حساباته الإعلانية من صفحته');
        redirect(url('client', ['id' => $id]));
    }
}
$show = in_str('status', 'active');
$ids = scope_client_ids();
if ($show === 'archived') {
    $demo = mode() === 'demo' ? 1 : 0;
    $rows = is_admin() ? q_all("SELECT * FROM clients WHERE status = 'archived' AND is_demo = ? ORDER BY name", [$demo]) : [];
} else {
    $rows = $ids ? q_all('SELECT c.*,
        (SELECT COUNT(*) FROM ad_accounts a WHERE a.client_id = c.id) accounts,
        (SELECT COUNT(*) FROM leads l WHERE l.client_id = c.id) leads,
        (SELECT COUNT(*) FROM alerts al WHERE al.client_id = c.id AND al.status = \'open\') open_alerts
        FROM clients c WHERE c.id IN (' . in_list($ids) . ') ORDER BY c.name') : [];
}
layout_start('العملاء والحسابات'); ?>
<div class="row" style="margin-bottom:14px">
  <div class="tabs" style="margin:0;border:0"><a href="<?= h(url('clients')) ?>" class="<?= $show !== 'archived' ? 'on' : '' ?>">النشطين</a><?php if (is_admin()): ?><a href="<?= h(url('clients', ['status' => 'archived'])) ?>" class="<?= $show === 'archived' ? 'on' : '' ?>">المؤرشفين</a><?php endif; ?></div>
  <span class="sp"></span>
  <input type="search" placeholder="بحث…" data-search-for="clientsT" style="max-width:220px">
</div>
<div class="tbl"><table class="t cards" id="clientsT">
  <thead><tr><th>العميل</th><th>المجال</th><th>العملة</th><th class="n">حسابات إعلانية</th><th class="n">ليدز</th><th class="n">تنبيهات مفتوحة</th><th>الحالة</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td class="main-cell"><a href="<?= h(url('client', ['id' => $c['id']])) ?>"><?= h($c['name']) ?></a> <?= $c['is_demo'] ? '<span class="badge b-demo">DEMO</span>' : '' ?></td>
      <td data-l="المجال"><?= h($c['industry'] ?: '—') ?></td>
      <td data-l="العملة" class="ltr"><?= h($c['currency']) ?></td>
      <td data-l="حسابات" class="n"><?= (int)($c['accounts'] ?? 0) ?></td>
      <td data-l="ليدز" class="n"><?= number_format((int)($c['leads'] ?? 0)) ?></td>
      <td data-l="تنبيهات" class="n"><?= ($c['open_alerts'] ?? 0) ? '<span class="badge b-bad">' . (int)$c['open_alerts'] . '</span>' : '0' ?></td>
      <td data-l="الحالة"><?= ['active' => '<span class="badge b-ok">نشط</span>', 'paused' => '<span class="badge b-warn">متوقف</span>', 'archived' => '<span class="badge">مؤرشف</span>'][$c['status']] ?? h($c['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (!$rows) echo empty_state('مفيش عملاء لسه', mode() === 'demo' ? ' تقدر تملا بيانات تجريبية من الإعدادات.' : ' ضيف أول عميل من تحت.'); ?>
</div>

<?php if (can('clients.manage') && $show !== 'archived'): ?>
<div class="card" style="margin-top:16px">
  <h2><?= icon('plus') ?> عميل جديد <?= mode() === 'demo' ? '<span class="badge b-demo">هيتسجل كتجريبي</span>' : '' ?></h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="create">
    <div class="fgrid">
      <div><label class="f">اسم العميل *</label><input type="text" name="name" required maxlength="120"></div>
      <div><label class="f">المجال</label><input type="text" name="industry" placeholder="عقارات، صيدليات، أثاث…"></div>
      <div><label class="f">العملة الأساسية</label><select name="currency"><?= select_opts(array_combine(currencies(), currencies()), 'EGP') ?></select></div>
      <div><label class="f">المنطقة الزمنية</label><input type="text" name="timezone" value="Africa/Cairo" dir="ltr"></div>
      <div><label class="f">اسم المسؤول</label><input type="text" name="contact_name"></div>
      <div><label class="f">تليفون المسؤول</label><input type="text" name="contact_phone" dir="ltr"></div>
      <div><label class="f">الشعار (للتقارير)</label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></div>
    </div>
    <label class="f">ملاحظات</label><textarea name="notes" style="min-height:60px"></textarea>
    <div style="margin-top:14px"><button class="btn">إضافة العميل</button></div>
  </form>
</div>
<?php endif; layout_end();
