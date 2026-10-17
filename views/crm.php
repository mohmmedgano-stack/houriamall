<?php
if (is_post()) {
    require_perm('crm.edit');
    $a = in_str('a');
    if ($a === 'create') {
        $cid = (int)in_int('client_id');
        if (!can_client($cid)) throw new Forbidden('client');
        $res = lead_create($cid, $_POST + ['campaign_id' => in_int('campaign_id')], 'manual');
        if ($res['merged']) { flash('الليد ده موجود قبل كده — اتسجل كنشاط على الليد الأصلي', 'warn'); redirect(url('lead', ['id' => $res['duplicate_of']])); }
        if (!can('crm.view_all') && !is_admin()) lead_assign($res['id'], uid());
        elseif (in_str('owner') !== '') lead_assign($res['id'], (int)in_str('owner'));
        flash($res['duplicate_of'] ? 'اتضاف، بس متعلّم إنه مكرر لليد #' . $res['duplicate_of'] : 'اتضاف الليد ✅', $res['duplicate_of'] ? 'warn' : 'ok');
        redirect(url('lead', ['id' => $res['id']]));
    }
    if ($a === 'import') {
        $cid = (int)in_int('client_id');
        if (!can_client($cid)) throw new Forbidden('client');
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('اختار ملف CSV أو XLSX');
        $st = leads_import($cid, read_table_file($file['tmp_name'], $file['name']), in_str('auto_assign') === '1');
        record_import($cid, 'leads', $file['name'], ['rows' => $st['rows'], 'inserted' => $st['inserted'], 'updated' => 0, 'skipped' => $st['skipped'] + $st['merged'] + $st['existing'], 'errors' => $st['errors']]);
        audit('import.leads', 'client', $cid, array_diff_key($st, ['errors' => 1]));
        flash("اتستورد {$st['inserted']} ليد" . ($st['duplicates'] ? " (منهم {$st['duplicates']} متعلّم مكرر)" : '') . ($st['merged'] ? " · {$st['merged']} اتدمجوا في ليدز موجودة" : '') . ($st['existing'] ? " · {$st['existing']} موجودين قبل كده" : '') . ($st['skipped'] ? " · {$st['skipped']} اتجاهلوا" : ''), $st['skipped'] ? 'warn' : 'ok');
        foreach (array_slice($st['errors'], 0, 5) as $e) flash($e, 'warn');
        redirect(url('crm', ['client' => $cid]));
    }
    if ($a === 'bulk') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $how = in_str('assign');
        $n = 0;
        foreach ($ids as $lid) {
            $l = q_one('SELECT * FROM leads WHERE id = ?', [$lid]);
            if (!$l || !lead_visible($l)) continue;
            if ($how === 'rr') { $u = next_assignee((int)$l['client_id']); if ($u) { lead_assign($lid, $u); $n++; } }
            elseif ($how === 'none') { lead_assign($lid, null); $n++; }
            elseif ((int)$how > 0) { try { lead_assign($lid, (int)$how); $n++; } catch (InvalidArgumentException $e) { flash($e->getMessage(), 'err'); break; } }
            elseif ($stg = in_int('stage')) { lead_set_stage($lid, $stg); $n++; }
        }
        flash("اتحدّث $n ليد ✅");
        redirect((string)($_SERVER['HTTP_REFERER'] ?? url('crm')));
    }
}
$view = in_str('view', 'list');
[$w, $p] = crm_filters_sql();
$stats = crm_stats($w, $p);
$stages = stages();
$clients = client_opts();
$owners = array_column(q_all("SELECT id, name FROM users WHERE active = 1 AND role IN ('owner','admin','buyer','sales') ORDER BY name"), 'name', 'id');
$campaigns = campaigns_for(filter_client_ids());
$base = "FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id JOIN clients c ON c.id = l.client_id LEFT JOIN users u ON u.id = l.owner_user_id LEFT JOIN campaigns cp ON cp.id = l.campaign_id WHERE $w";
layout_start('Lead CRM'); ?>
<div class="tabs">
  <?php foreach (['list' => 'القائمة', 'board' => 'لوحة المراحل', 'overdue' => 'متابعات متأخرة'] as $k => $l): ?><a class="<?= $view === $k ? 'on' : '' ?>" href="<?= h(url('crm', array_merge($_GET, ['r' => null, 'view' => $k, 'page' => null]))) ?>"><?= $l ?></a><?php endforeach; ?>
  <?php if (can('crm.edit')): ?><a class="<?= $view === 'add' ? 'on' : '' ?>" href="<?= h(url('crm', ['view' => 'add', 'client' => in_int('client')])) ?>">+ إضافة / استيراد</a><?php endif; ?>
</div>
<?php if ($view !== 'add'): ?>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="crm"><input type="hidden" name="view" value="<?= h($view) ?>">
  <div style="min-width:200px"><label>بحث</label><input type="search" name="q" value="<?= h(in_str('q')) ?>" placeholder="اسم، تليفون، إيميل"></div>
  <div><label>العميل</label><select name="client"><?= select_opts($clients, in_int('client'), true) ?></select></div>
  <?php if ($view !== 'board'): ?><div><label>المرحلة</label><select name="stage"><?= select_opts(array_column($stages, 'name', 'id'), in_int('stage'), true) ?></select></div><?php endif; ?>
  <?php if (can('crm.view_all')): ?><div><label>المسؤول</label><select name="owner"><?= select_opts(['none' => 'غير مسند'] + $owners, in_str('owner'), true) ?></select></div><?php endif; ?>
  <div><label>الحملة</label><select name="campaign"><?= select_opts(array_column($campaigns, 'name', 'id'), in_int('campaign'), true) ?></select></div>
  <div><label>المصدر</label><select name="source"><?= select_opts(['meta_form' => 'Meta Lead Ads', 'import' => 'استيراد', 'manual' => 'يدوي'], in_str('source'), true) ?></select></div>
  <div><label>من</label><input type="date" name="from" value="<?= h(in_str('from')) ?>"></div><div><label>إلى</label><input type="date" name="to" value="<?= h(in_str('to')) ?>"></div>
  <div><label>المكرر</label><select name="dups"><?= select_opts(['' => 'إخفاء المكرر', '1' => 'إظهار المكرر'], in_str('dups')) ?></select></div>
</form>
<div class="kpis" style="margin-bottom:16px">
  <?= kpi('إجمالي الليدز', number_format($stats['total'])) ?>
  <?= kpi('Contact Rate', fmt_pct($stats['contact_rate'], 1), null, false, 'ليدز اتواصلنا معاهم ÷ الإجمالي') ?>
  <?= kpi('Qualified Rate', fmt_pct($stats['qualified_rate'], 1), null, false, 'ليدز مؤهلة ÷ الإجمالي') ?>
  <?= kpi('Appointment Rate', fmt_pct($stats['appointment_rate'], 1)) ?>
  <?= kpi('Won', number_format($stats['won']) . ' <small class="muted">(' . fmt_pct($stats['win_rate'], 1) . ')</small>', null, false, 'نسبة البيع من الإجمالي') ?>
  <?= kpi('Close Rate', fmt_pct($stats['close_rate'], 1), null, false, 'Won ÷ (Won + Lost)') ?>
</div>
<?php endif; ?>

<?php if ($view === 'board'):
  $cols = [];
  foreach ($stages as $s) $cols[$s['id']] = ['s' => $s, 'n' => (int)q_val("SELECT COUNT(*) $base AND l.stage_id = ?", array_merge($p, [$s['id']])),
      'rows' => q_all("SELECT l.*, c.name cname, u.name uname $base AND l.stage_id = ? ORDER BY COALESCE(l.next_follow_up_at, l.received_at) LIMIT 40", array_merge($p, [$s['id']]))]; ?>
<div class="kanban">
<?php foreach ($cols as $col): ?>
  <div class="kcol"><h4><span class="status-dot" style="background:<?= h($col['s']['color']) ?>"></span><?= h($col['s']['name']) ?> <span class="badge"><?= $col['n'] ?></span></h4>
  <?php foreach ($col['rows'] as $l): $late = $l['next_follow_up_at'] && $l['next_follow_up_at'] < now(); ?>
    <a class="kcard" href="<?= h(url('lead', ['id' => $l['id']])) ?>"><b><?= h($l['full_name'] ?: $l['phone'] ?: '#' . $l['id']) ?></b>
      <div class="muted small"><?= h($l['cname']) ?><?= $l['uname'] ? ' · ' . h($l['uname']) : '' ?></div>
      <?php if ($l['next_follow_up_at']): ?><span class="badge <?= $late ? 'b-bad' : 'b-info' ?> ltr"><?= h(fmt_dt($l['next_follow_up_at'], 'm-d H:i')) ?></span><?php endif; ?>
      <?php if ($l['deal_value']): ?><span class="badge b-ok"><?= number_format((float)$l['deal_value']) ?></span><?php endif; ?></a>
  <?php endforeach; if ($col['n'] > 40) echo '<div class="muted small">+' . ($col['n'] - 40) . ' كمان — استخدم القائمة</div>'; ?>
  </div>
<?php endforeach; ?>
</div>

<?php elseif ($view === 'add'): $cid = in_int('client') ?: (array_key_first($clients) ?? null);
  $ccamps = $cid ? q_all('SELECT cp.id, cp.name FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE a.client_id = ? ORDER BY cp.name', [$cid]) : []; ?>
<?php if (!$clients): echo '<div class="card">' . empty_state('مفيش عملاء') . '</div>'; else: ?>
<form class="row" style="margin-bottom:12px"><input type="hidden" name="r" value="crm"><input type="hidden" name="view" value="add"><span class="muted small">العميل:</span><select name="client" onchange="this.form.submit()" style="max-width:280px"><?= select_opts($clients, $cid) ?></select></form>
<div class="grid g2">
<div class="card"><h2>إضافة ليد يدوي</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="create"><input type="hidden" name="client_id" value="<?= (int)$cid ?>">
    <div class="fgrid">
      <div><label class="f">الاسم</label><input type="text" name="full_name"></div>
      <div><label class="f">التليفون</label><input type="text" name="phone" dir="ltr" inputmode="tel"></div>
      <div><label class="f">الإيميل</label><input type="email" name="email" dir="ltr"></div>
      <div><label class="f">المدينة</label><input type="text" name="city"></div>
      <div><label class="f">المنتج / المشروع</label><input type="text" name="product"></div>
      <div><label class="f">الحملة</label><select name="campaign_id"><?= select_opts(array_column($ccamps, 'name', 'id'), '', true, '—') ?></select></div>
      <div><label class="f">UTM Source</label><input type="text" name="utm_source" dir="ltr"></div>
      <div><label class="f">UTM Campaign</label><input type="text" name="utm_campaign" dir="ltr"></div>
      <?php if (can('crm.view_all')): ?><div><label class="f">المسؤول</label><select name="owner"><?= select_opts(array_column(assignable_users((int)$cid), 'name', 'id'), '', true, 'غير مسند') ?></select></div><?php endif; ?>
    </div>
    <button class="btn" style="margin-top:12px">إضافة</button></form></div>
<div class="card"><h2><?= icon('upload') ?> استيراد ليدز (CSV / Excel)</h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="import"><input type="hidden" name="client_id" value="<?= (int)$cid ?>">
    <label class="f">الملف</label><input type="file" name="file" accept=".csv,.xlsx,.tsv,.txt" required>
    <label class="check" style="margin-top:10px"><input type="checkbox" name="auto_assign" value="1"> وزّع الليدز الجديدة على فريق المبيعات بالتساوي</label>
    <p class="hint">بنتعرف على ملف Meta Lead Ads (full_name, phone_number, email, campaign_name, ad_name, form_name, created_time) وأي شيت فيه الاسم والتليفون. الليدز المكررة بتتعامل حسب <a href="<?= h(url('settings')) ?>">قواعد التكرار</a>. أعمدة اختيارية: stage، owner (إيميل المسؤول)، UTM.</p>
    <button class="btn" style="margin-top:8px">استيراد</button></form></div>
</div>
<?php endif; ?>

<?php else:
  $pg = paginate("SELECT COUNT(*) $base", $p, 30);
  $order = $view === 'overdue' ? ' ORDER BY l.next_follow_up_at ASC' : sort_sql(['received' => 'l.received_at', 'name' => 'l.full_name', 'stage' => 's.position', 'follow' => 'l.next_follow_up_at'], 'received');
  $rows = q_all("SELECT l.*, s.name sname, s.color, c.name cname, u.name uname, cp.name cpname $base $order LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p); ?>
<form method="post" id="bulkF"><?= csrf_field() ?><input type="hidden" name="a" value="bulk">
<?php if (can('crm.edit') && can('crm.view_all') && $rows): ?>
<div class="row small" style="margin-bottom:8px"><span class="muted">على المحدد:</span>
  <select name="assign" style="max-width:200px"><option value="">إسناد…</option><option value="rr">توزيع بالتساوي</option><option value="none">إلغاء الإسناد</option><?= select_opts($owners, '') ?></select>
  <select name="stage" style="max-width:170px"><?= select_opts(array_column($stages, 'name', 'id'), '', true, 'نقل لمرحلة…') ?></select>
  <button class="btn sm">تطبيق</button><span class="sp"></span>
  <a class="btn sm ghost" href="<?= h(url('export', array_merge($_GET, ['r' => null, 'kind' => 'leads', 'format' => 'csv']))) ?>"><?= icon('download') ?> CSV</a>
  <a class="btn sm ghost" href="<?= h(url('export', array_merge($_GET, ['r' => null, 'kind' => 'leads', 'format' => 'xlsx']))) ?>"><?= icon('download') ?> Excel</a></div>
<?php endif; ?>
<div class="tbl"><table class="t cards"><thead><tr><th style="width:28px"></th><th><?= sort_link('name', 'الليد') ?></th><th>العميل</th><th><?= sort_link('stage', 'المرحلة') ?></th><th>المسؤول</th><th>الحملة / المصدر</th><th><?= sort_link('received', 'وصل') ?></th><th><?= sort_link('follow', 'المتابعة الجاية') ?></th></tr></thead><tbody>
<?php foreach ($rows as $l): $late = $l['next_follow_up_at'] && $l['next_follow_up_at'] < now(); ?>
  <tr><td><input type="checkbox" name="ids[]" value="<?= (int)$l['id'] ?>"></td>
    <td class="main-cell"><a href="<?= h(url('lead', ['id' => $l['id']])) ?>"><?= h($l['full_name'] ?: 'بدون اسم') ?></a> <?= $l['duplicate_of'] ? '<span class="badge b-warn">مكرر</span>' : '' ?><div class="small muted ltr"><?= h($l['phone'] ?: $l['email']) ?></div></td>
    <td data-l="العميل" class="small"><?= h($l['cname']) ?></td>
    <td data-l="المرحلة"><span class="badge" style="border-color:<?= h($l['color']) ?>;color:<?= h($l['color']) ?>"><?= h($l['sname']) ?></span></td>
    <td data-l="المسؤول" class="small"><?= h($l['uname'] ?: '—') ?></td>
    <td data-l="الحملة" class="small"><?= h($l['cpname'] ?: ($l['utm_campaign'] ?: '—')) ?><div class="muted"><?= h($l['source']) ?></div></td>
    <td data-l="وصل" class="small ltr"><?= h(fmt_dt($l['received_at'], 'm-d H:i')) ?></td>
    <td data-l="المتابعة" class="small"><?= $l['next_follow_up_at'] ? '<span class="badge ' . ($late ? 'b-bad' : 'b-info') . ' ltr">' . h(fmt_dt($l['next_follow_up_at'], 'm-d H:i')) . '</span>' : '—' ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php if (!$rows) echo empty_state($view === 'overdue' ? 'مفيش متابعات متأخرة 👌' : 'مفيش ليدز', $view === 'overdue' ? '' : ' ضيف ليدز يدوي أو استوردها، أو اربط Meta Lead Ads.'); ?></div>
</form>
<?= pager_html($pg) ?>
<?php endif; layout_end();
