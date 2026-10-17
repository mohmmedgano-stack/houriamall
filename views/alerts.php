<?php
if (is_post()) {
    $a = in_str('a');
    if (in_array($a, ['ack', 'resolve', 'reopen'], true)) {
        $al = q_one('SELECT * FROM alerts WHERE id = ?', [(int)in_int('id')]);
        if (!$al || !can_client((int)$al['client_id'])) throw new Forbidden('alert');
        $st = ['ack' => 'ack', 'resolve' => 'resolved', 'reopen' => 'open'][$a];
        db_update('alerts', (int)$al['id'], ['status' => $st, 'acked_by' => uid(), 'acked_at' => now()]);
        audit('alert.' . $a, 'alert', $al['id']);
        redirect((string)($_SERVER['HTTP_REFERER'] ?? url('alerts')));
    }
    require_perm('alerts.manage');
    if ($a === 'rule_save') {
        $rid = in_int('id');
        if ($rid && ($cid = q_val('SELECT client_id FROM alert_rules WHERE id = ?', [$rid])) !== null && !can_client((int)$cid)) throw new Forbidden('rule');
        alert_rule_save($rid, $_POST); flash('اتحفظت القاعدة ✅'); redirect(url('alerts', ['tab' => 'rules']));
    }
    $rule = q_one('SELECT * FROM alert_rules WHERE id = ?', [(int)in_int('id')]);
    if (!$rule || ($rule['client_id'] && !can_client((int)$rule['client_id'])) || (!$rule['client_id'] && !is_admin())) throw new Forbidden('rule');
    if ($a === 'rule_toggle') { db_update('alert_rules', (int)$rule['id'], ['active' => $rule['active'] ? 0 : 1, 'updated_at' => now()]); flash($rule['active'] ? 'اتوقفت القاعدة' : 'اتفعّلت القاعدة'); }
    if ($a === 'rule_delete') { q('DELETE FROM alert_rules WHERE id = ?', [$rule['id']]); audit('alert_rule.delete', 'alert_rule', $rule['id']); flash('اتمسحت القاعدة'); }
    if ($a === 'rule_run') {
        $log = evaluate_rule($rule);
        $c = array_count_values(array_column($log, 1));
        flash('اتفحصت ' . count($log) . ' حملة: ' . ($c['fired'] ?? 0) . ' تنبيه جديد، ' . ($c['cooldown'] ?? 0) . ' في فترة الانتظار، ' . ($c['below_min'] ?? 0) . ' بيانات قليلة، ' . ($c['no_data'] ?? 0) . ' مفيش بيانات، ' . ($c['ok'] ?? 0) . ' تمام');
    }
    if ($a === 'defaults') { $cid = (int)in_int('client_id'); if (!can_client($cid)) throw new Forbidden('client'); default_alert_rules($cid); flash('اتضافت القواعد الأساسية ✅'); }
    redirect(url('alerts', ['tab' => 'rules']));
}
$tab = in_str('tab', 'alerts');
$ids = filter_client_ids();
layout_start('Campaign Watchdog'); ?>
<div class="tabs">
  <a class="<?= $tab === 'alerts' ? 'on' : '' ?>" href="<?= h(url('alerts')) ?>">التنبيهات</a>
  <a class="<?= $tab === 'rules' ? 'on' : '' ?>" href="<?= h(url('alerts', ['tab' => 'rules'])) ?>">قواعد المراقبة</a>
</div>
<p class="muted small">المراقبة دي بتنبهك بس — مش بتوقف حملات ولا بتغير ميزانيات أوتوماتيك.</p>
<?php if ($tab === 'alerts'):
  $status = in_str('status', 'open');
  $w = 'a.client_id IN (' . in_list($ids) . ')'; $p = [];
  if ($status !== 'all') { $w .= ' AND a.status = ?'; $p[] = $status; }
  if ($sv = in_str('severity')) { $w .= ' AND a.severity = ?'; $p[] = $sv; }
  $pg = paginate("SELECT COUNT(*) FROM alerts a WHERE $w", $p, 30);
  $rows = q_all("SELECT a.*, c.name cname, cp.name cpname, u.name uname FROM alerts a LEFT JOIN clients c ON c.id = a.client_id LEFT JOIN campaigns cp ON cp.id = a.campaign_id LEFT JOIN users u ON u.id = a.acked_by
      WHERE $w ORDER BY CASE a.status WHEN 'open' THEN 0 ELSE 1 END, a.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
  $open = in_int('view'); ?>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="alerts">
  <div><label>العميل</label><select name="client"><?= select_opts(client_opts(), in_int('client'), true) ?></select></div>
  <div><label>الحالة</label><select name="status"><?= select_opts(['open' => 'مفتوحة', 'ack' => 'اتشافت', 'resolved' => 'اتحلت', 'all' => 'الكل'], $status) ?></select></div>
  <div><label>الخطورة</label><select name="severity"><?= select_opts(['critical' => 'حرج', 'warning' => 'تحذير', 'info' => 'معلومة'], in_str('severity'), true) ?></select></div>
</form>
<?php if (!$rows) echo '<div class="card">' . empty_state('مفيش تنبيهات', $status === 'open' ? ' كله تمام. التنبيهات بتظهر هنا لما قاعدة مراقبة تتحقق.' : '') . '</div>'; ?>
<div class="stack">
<?php foreach ($rows as $a): $ev = json_decode((string)$a['evidence_json'], true) ?: []; ?>
  <div class="card" id="a<?= (int)$a['id'] ?>" style="<?= $open === (int)$a['id'] ? 'border-color:var(--acc)' : '' ?>">
    <div class="row"><?= sev_badge($a['severity']) ?><b><?= h($a['title']) ?></b><span class="badge"><?= $a['kind'] === 'budget' ? 'ميزانية' : 'قاعدة' ?></span>
      <span class="sp"></span><span class="muted small"><?= h($a['cname']) ?> · <span class="ltr"><?= h(fmt_dt($a['created_at'])) ?></span></span></div>
    <p style="margin:8px 0"><?= h($a['message']) ?></p>
    <?php if ($ev): ?><details><summary class="small muted">البيانات اللي أدت للتنبيه</summary>
      <div class="kpis" style="margin-top:8px"><?php foreach ($ev as $k => $v) { if (is_array($v)) $v = implode(' → ', $v); echo kpi(metric_defs()[$k][0] ?? $k, is_numeric($v) ? (in_array($k, PCT_METRICS, true) || $k === 'link_ctr' ? fmt_pct((float)$v) : number_format((float)$v, (float)$v < 100 ? 2 : 0)) : h((string)$v)); } ?></div></details><?php endif; ?>
    <div class="row" style="margin-top:10px">
      <?php if ($a['campaign_id']): ?><a class="btn sm ghost" href="<?= h(url('campaign', ['id' => $a['campaign_id']])) ?>">افتح الحملة</a><?php endif; ?>
      <?php foreach ($a['status'] === 'open' ? ['ack' => 'شفته', 'resolve' => 'اتحل'] : ['reopen' => 'إعادة فتح'] as $act => $l): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn sm <?= $act === 'resolve' ? '' : 'ghost' ?>"><?= $l ?></button></form>
      <?php endforeach; ?>
      <?php if ($a['status'] !== 'open'): ?><span class="muted small"><?= $a['status'] === 'ack' ? 'اتشاف' : 'اتحل' ?> بواسطة <?= h($a['uname']) ?></span><?php endif; ?>
    </div></div>
<?php endforeach; ?>
</div><?= pager_html($pg) ?>

<?php else:
  $rules = q_all('SELECT r.*, c.name cname, cp.name cpname, a.name aname FROM alert_rules r LEFT JOIN clients c ON c.id = r.client_id LEFT JOIN campaigns cp ON cp.id = r.campaign_id LEFT JOIN ad_accounts a ON a.id = r.ad_account_id
      WHERE r.client_id IN (' . in_list($ids) . ')' . (is_admin() ? ' OR r.client_id IS NULL' : '') . ' ORDER BY r.active DESC, c.name, r.name');
  $edit = in_int('edit') ? q_one('SELECT * FROM alert_rules WHERE id = ?', [in_int('edit')]) : null;
  $fc = $edit['client_id'] ?? (in_int('client') ?: ($ids[0] ?? null));
  $camps = $fc ? q_all('SELECT cp.id, cp.name FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE a.client_id = ? ORDER BY cp.name', [$fc]) : []; ?>
<div class="grid g2">
<div class="card"><h2>القواعد</h2>
  <?php if (!$rules) echo empty_state('مفيش قواعد مراقبة', ' ضيف قاعدة أو استخدم القواعد الأساسية.'); ?>
  <?php foreach ($rules as $r): $wm = watch_metrics()[$r['metric']]; $thr = in_array($r['metric'], PCT_METRICS, true) ? ($r['threshold'] * 100) . '%' : rtrim(rtrim(number_format((float)$r['threshold'], 4, '.', ''), '0'), '.'); ?>
    <div style="padding:10px 0;border-bottom:1px solid var(--line)">
      <div class="row"><span class="status-dot <?= $r['active'] ? 'ok' : '' ?>"></span><b><?= h($r['name']) ?></b><?= sev_badge($r['severity']) ?><span class="sp"></span><span class="muted small"><?= h($r['cpname'] ?: ($r['aname'] ?: ($r['cname'] ?: 'كل العملاء'))) ?></span></div>
      <div class="small muted"><?= h($wm[0]) ?> <?= $r['metric'] === 'delivery' ? '' : ($r['operator'] === 'lt' ? '&lt;' : '&gt;') . ' ' . h($thr) ?> · آخر <?= (int)$r['window_days'] ?> يوم · حد أدنى: صرف <?= (float)$r['min_spend'] ?>، ظهور <?= number_format((int)$r['min_impressions']) ?><?= $r['min_results'] > 0 ? '، نتايج ' . (float)$r['min_results'] : '' ?> · انتظار <?= (int)$r['cooldown_hours'] ?> س · <?= $r['channel'] === 'email' ? 'إيميل + داخل المنصة' : 'داخل المنصة' ?>
        <?= $r['last_evaluated_at'] ? ' · آخر فحص ' . h(fmt_dt($r['last_evaluated_at'], 'm-d H:i')) : '' ?></div>
      <?php if (can('alerts.manage')): ?><div class="row" style="margin-top:6px">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="rule_run"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost">افحص دلوقتي</button></form>
        <a class="btn sm ghost" href="<?= h(url('alerts', ['tab' => 'rules', 'edit' => $r['id']])) ?>#rf">تعديل</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="rule_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost"><?= $r['active'] ? 'إيقاف' : 'تفعيل' ?></button></form>
        <form method="post" data-confirm="تمسح القاعدة؟"><?= csrf_field() ?><input type="hidden" name="a" value="rule_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost">حذف</button></form></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (can('alerts.manage') && $fc): ?><form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="a" value="defaults"><input type="hidden" name="client_id" value="<?= (int)$fc ?>"><button class="btn sm ghost">ضيف القواعد الأساسية لـ <?= h(client_opts()[$fc] ?? '') ?></button></form><?php endif; ?>
</div>
<?php if (can('alerts.manage')): ?>
<div class="card" id="rf"><h2><?= $edit ? 'تعديل القاعدة' : 'قاعدة جديدة' ?></h2>
  <form class="row" style="margin-bottom:4px"><input type="hidden" name="r" value="alerts"><input type="hidden" name="tab" value="rules"><span class="muted small">العميل:</span><select name="client" onchange="this.form.submit()" style="max-width:240px"><?= select_opts(client_opts(), $fc) ?></select></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="rule_save"><input type="hidden" name="client_id" value="<?= (int)$fc ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="fgrid">
      <div><label class="f">اسم القاعدة</label><input type="text" name="name" value="<?= h($edit['name'] ?? '') ?>" placeholder="مثلاً: CPL عالي"></div>
      <div><label class="f">الحملة</label><select name="campaign_id"><?= select_opts(array_column($camps, 'name', 'id'), $edit['campaign_id'] ?? '', true, 'كل حملات العميل') ?></select></div>
      <div><label class="f">المؤشر</label><select name="metric"><?= select_opts(array_map(fn($m) => $m[0], watch_metrics()), $edit['metric'] ?? 'cpl') ?></select></div>
      <div><label class="f">الشرط</label><select name="operator"><?= select_opts(['gt' => 'أعلى من', 'lt' => 'أقل من'], $edit['operator'] ?? 'gt') ?></select></div>
      <div><label class="f">الحد</label><input type="text" name="threshold" value="<?= h(isset($edit) && $edit ? (in_array($edit['metric'], PCT_METRICS, true) ? $edit['threshold'] * 100 : $edit['threshold']) : '') ?>" dir="ltr" placeholder="مثلاً 150 أو 1.2 للنسب"><div class="hint">النسب تتكتب كنسبة مئوية (1.2 = 1.2%).</div></div>
      <div><label class="f">فترة التقييم (أيام)</label><input type="number" name="window_days" min="1" max="90" value="<?= (int)($edit['window_days'] ?? 3) ?>"></div>
      <div><label class="f">أقل صرف قبل التنبيه</label><input type="number" step="0.01" name="min_spend" value="<?= h($edit['min_spend'] ?? 300) ?>"></div>
      <div><label class="f">أقل ظهور</label><input type="number" name="min_impressions" value="<?= h($edit['min_impressions'] ?? 3000) ?>"></div>
      <div><label class="f">أقل نتايج</label><input type="number" step="1" name="min_results" value="<?= h($edit['min_results'] ?? 0) ?>"></div>
      <div><label class="f">الخطورة</label><select name="severity"><?= select_opts(['info' => 'معلومة', 'warning' => 'تحذير', 'critical' => 'حرج'], $edit['severity'] ?? 'warning') ?></select></div>
      <div><label class="f">وسيلة الإشعار</label><select name="channel"><?= select_opts(['in_app' => 'داخل المنصة', 'email' => 'إيميل + داخل المنصة'], $edit['channel'] ?? 'in_app') ?></select><?php if (!setting('mail_from')): ?><div class="hint">الإيميل محتاج "إيميل المرسل" من التكاملات.</div><?php endif; ?></div>
      <div><label class="f">الانتظار قبل التكرار (ساعات)</label><input type="number" name="cooldown_hours" min="1" value="<?= (int)($edit['cooldown_hours'] ?? 24) ?>"></div>
    </div>
    <label class="check" style="margin-top:10px"><input type="checkbox" name="active" value="1" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>> مفعّلة</label>
    <div><button class="btn" style="margin-top:12px">حفظ</button></div></form></div>
<?php endif; ?>
</div>
<?php endif; layout_end();
