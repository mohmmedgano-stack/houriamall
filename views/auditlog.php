<?php
$where = '1=1'; $params = [];
if ($act = in_str('action')) { $where .= ' AND l.action LIKE ?'; $params[] = $act . '%'; }
if ($uid = in_int('user')) { $where .= ' AND l.user_id = ?'; $params[] = $uid; }
$p = paginate("SELECT COUNT(*) FROM audit_logs l WHERE $where", $params, 50);
$rows = q_all("SELECT l.*, u.name uname FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $where ORDER BY l.id DESC LIMIT {$p['per']} OFFSET {$p['offset']}", $params);
$users = array_column(q_all('SELECT id, name FROM users ORDER BY name'), 'name', 'id');
layout_start('سجل العمليات'); ?>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="auditlog">
  <div><label>العملية</label><input type="text" name="action" value="<?= h(in_str('action')) ?>" placeholder="lead. / client. / login" dir="ltr"></div>
  <div><label>المستخدم</label><select name="user"><?= select_opts($users, in_int('user'), true) ?></select></div>
  <div><button class="btn ghost">فلترة</button></div>
</form>
<div class="tbl"><table class="t cards">
  <thead><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th>العنصر</th><th>تفاصيل</th><th>IP</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td data-l="الوقت" class="ltr"><?= h(fmt_dt($r['created_at'], 'Y-m-d H:i:s')) ?></td><td class="main-cell"><?= h($r['uname'] ?? 'النظام') ?></td>
      <td data-l="العملية" class="ltr"><code><?= h($r['action']) ?></code></td><td data-l="العنصر" class="ltr"><?= h(trim(($r['entity'] ?? '') . ' #' . ($r['entity_id'] ?? ''), ' #')) ?></td>
      <td data-l="تفاصيل" class="small ltr" style="max-width:360px;word-break:break-word"><?= h(mb_substr((string)$r['details_json'], 0, 300)) ?></td><td data-l="IP" class="ltr small"><?= h($r['ip']) ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php if (!$rows) echo empty_state('مفيش عمليات مسجلة'); ?></div>
<?= pager_html($p) ?>
<?php layout_end();
