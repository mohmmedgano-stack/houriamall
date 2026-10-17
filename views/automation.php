<?php
if (is_post()) {
    require_perm('automation.manage');
    $a = in_str('a');
    if ($a === 'template') {
        $t = wf_templates()[in_str('key')] ?? null;
        if (!$t) throw new InvalidArgumentException('Template غير معروف');
        $id = wf_save(null, $t + ['client_id' => in_int('client_id'), 'active' => 1, 'max_retries' => 3, 'trigger_config' => $t['trigger_config'] ?? []]);
        flash('اتضاف الـ Workflow ✅ راجعه وفعّله/عدّله زي ما تحب'); redirect(url('automation', ['wf' => $id]));
    }
    if ($a === 'save') {
        $id = in_int('id');
        if ($id) { $w = q_one('SELECT * FROM automation_workflows WHERE id = ?', [$id]); if (!$w || ($w['client_id'] && !can_client((int)$w['client_id'])) || (!$w['client_id'] && !is_admin())) throw new Forbidden('wf'); }
        $in = $_POST;
        $in['conditions'] = array_values(array_filter((array)($_POST['conditions'] ?? []), fn($c) => !empty($c['field'])));
        $in['actions'] = array_values(array_filter((array)($_POST['actions'] ?? []), fn($c) => !empty($c['type'])));
        $in['approve_external_set'] = 1;
        $id = wf_save($id, $in); flash('اتحفظ ✅'); redirect(url('automation', ['wf' => $id]));
    }
    $w = q_one('SELECT * FROM automation_workflows WHERE id = ?', [(int)in_int('id')]);
    if (!$w || ($w['client_id'] && !can_client((int)$w['client_id'])) || (!$w['client_id'] && !is_admin())) throw new Forbidden('wf');
    if ($a === 'toggle') { db_update('automation_workflows', (int)$w['id'], ['active' => $w['active'] ? 0 : 1, 'updated_at' => now()]); audit('workflow.toggle', 'workflow', $w['id']); }
    if ($a === 'delete') { q('DELETE FROM automation_workflows WHERE id = ?', [$w['id']]); audit('workflow.delete', 'workflow', $w['id']); flash('اتمسح'); redirect(url('automation')); }
    if ($a === 'run_now') {
        $cid = $w['client_id'] ? (int)$w['client_id'] : (int)in_int('client_id');
        if (!$cid || !can_client($cid)) throw new InvalidArgumentException('اختار عميل للتشغيل اليدوي');
        $ex = wf_execute($w, ['client_id' => $cid, 'event_type' => 'manual', 'ref' => 'manual'], 'manual:' . uid() . ':' . microtime(true));
        flash('اتشغّل: ' . $ex['status'] . ($ex['error'] ? ' — ' . $ex['error'] : ''), $ex['status'] === 'success' ? 'ok' : 'warn');
    }
    redirect(url('automation', ['wf' => $w['id']]));
}
$ids = scope_client_ids();
$wfs = q_all('SELECT w.*, c.name cname, (SELECT COUNT(*) FROM workflow_executions x WHERE x.workflow_id = w.id) runs,
    (SELECT COUNT(*) FROM workflow_executions x WHERE x.workflow_id = w.id AND x.status = \'failed\') fails FROM automation_workflows w LEFT JOIN clients c ON c.id = w.client_id
    WHERE w.client_id IN (' . in_list($ids) . ')' . (is_admin() ? ' OR w.client_id IS NULL' : '') . ' ORDER BY w.active DESC, w.name');
$sel = in_int('wf') ? q_one('SELECT * FROM automation_workflows WHERE id = ?', [in_int('wf')]) : null;
if ($sel && (($sel['client_id'] && !can_client((int)$sel['client_id'])) || (!$sel['client_id'] && !is_admin()))) $sel = null;
$editing = in_str('edit') === '1' || in_str('new') === '1';
layout_start('Marketing Automation Hub'); ?>
<p class="muted small">الـ Workflows بتشتغل في الخلفية كل دقيقة. كل تشغيل بيتسجل، وبيعيد المحاولة لو حصل خطأ، ومستحيل يتنفذ مرتين لنفس الحدث. الأتمتة هنا مش بتعدّل حملات ولا ميزانيات، ومش بتبعت رسايل للعملاء — والـ Webhook الخارجي محتاج موافقة مدير.</p>
<div class="grid g2">
<div class="stack">
<div class="card"><h2>الـ Workflows<span class="sp"></span><?php if (can('automation.manage')): ?><a class="btn sm" href="<?= h(url('automation', ['new' => 1])) ?>"><?= icon('plus') ?> جديد</a><?php endif; ?></h2>
  <?php if (!$wfs) echo empty_state('مفيش Workflows', ' ابدأ من القوالب الجاهزة تحت.'); ?>
  <?php foreach ($wfs as $w): ?><div class="row" style="padding:8px 0;border-bottom:1px solid var(--line)">
    <span class="status-dot <?= $w['active'] ? 'ok' : '' ?>"></span><a href="<?= h(url('automation', ['wf' => $w['id']])) ?>"><b><?= h($w['name']) ?></b></a>
    <span class="badge"><?= h(event_types()[$w['trigger_type']] ?? $w['trigger_type']) ?></span><span class="sp"></span>
    <span class="muted small"><?= h($w['cname'] ?: 'كل العملاء') ?> · <?= (int)$w['runs'] ?> تشغيل<?= $w['fails'] ? ' · <span style="color:var(--bad)">' . (int)$w['fails'] . ' فشل</span>' : '' ?></span></div><?php endforeach; ?>
</div>
<?php if (can('automation.manage')): $fc = in_int('client') ?: ($ids[0] ?? null); ?>
<div class="card"><h2>قوالب جاهزة</h2>
  <form class="row" style="margin-bottom:8px"><input type="hidden" name="r" value="automation"><span class="muted small">للعميل:</span><select name="client" onchange="this.form.submit()" style="max-width:240px"><?= select_opts(client_opts(), $fc) ?></select></form>
  <?php foreach (wf_templates() as $k => $t): ?><form method="post" class="row" style="padding:6px 0;border-bottom:1px dashed var(--line)"><?= csrf_field() ?><input type="hidden" name="a" value="template"><input type="hidden" name="key" value="<?= h($k) ?>"><input type="hidden" name="client_id" value="<?= (int)$fc ?>">
    <span style="flex:1"><b><?= h($t['name']) ?></b><br><span class="small muted"><?= h(event_types()[$t['trigger_type']]) ?> ← <?= h(implode(' ← ', array_map(fn($a) => wf_action_types()[$a['type']], $t['actions']))) ?></span></span><button class="btn sm ghost">إضافة</button></form><?php endforeach; ?>
</div><?php endif; ?>
</div>

<div class="stack">
<?php if ($editing && can('automation.manage')):
  $w = in_str('new') === '1' ? null : $sel;
  $cfg = $w ? (json_decode($w['trigger_config_json'], true) ?: []) : ['freq' => 'weekly', 'hour' => 9, 'weekday' => 4, 'monthday' => 1];
  $conds = $w ? (json_decode($w['conditions_json'], true) ?: []) : []; $acts = $w ? (json_decode($w['actions_json'], true) ?: []) : [];
  while (count($conds) < 3) $conds[] = ['field' => '', 'op' => 'eq', 'value' => '']; while (count($acts) < 4) $acts[] = ['type' => '', 'params' => []];
  $wc = $w['client_id'] ?? (in_int('client') ?: ($ids[0] ?? null)); ?>
<div class="card"><h2><?= $w ? 'تعديل: ' . h($w['name']) : 'Workflow جديد' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="save"><?php if ($w): ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><?php endif; ?>
    <div class="fgrid">
      <div><label class="f">الاسم</label><input type="text" name="name" required value="<?= h($w['name'] ?? '') ?>"></div>
      <div><label class="f">العميل</label><select name="client_id"><?= (is_admin() ? '<option value="">كل العملاء</option>' : '') . select_opts(client_opts(), $wc) ?></select></div>
      <div><label class="f">Trigger</label><select name="trigger_type"><?= select_opts(event_types(), $w['trigger_type'] ?? 'lead.created') ?></select></div>
      <div><label class="f">أقصى عدد محاولات</label><input type="number" name="max_retries" min="0" max="10" value="<?= (int)($w['max_retries'] ?? 3) ?>"></div>
    </div>
    <details class="box" style="margin-top:10px" <?= ($w['trigger_type'] ?? '') === 'schedule' ? 'open' : '' ?>><summary class="small">إعدادات الجدول (لـ Trigger "جدول زمني")</summary>
      <div class="fgrid"><div><label class="f">التكرار</label><select name="trigger_config[freq]"><?= select_opts(['daily' => 'يومي', 'weekly' => 'أسبوعي', 'monthly' => 'شهري'], $cfg['freq'] ?? 'weekly') ?></select></div>
        <div><label class="f">الساعة</label><input type="number" min="0" max="23" name="trigger_config[hour]" value="<?= (int)($cfg['hour'] ?? 9) ?>"></div>
        <div><label class="f">يوم الأسبوع</label><select name="trigger_config[weekday]"><?= select_opts(['6' => 'السبت', '0' => 'الأحد', '1' => 'الاتنين', '2' => 'التلات', '3' => 'الأربع', '4' => 'الخميس', '5' => 'الجمعة'], (string)($cfg['weekday'] ?? 4)) ?></select></div>
        <div><label class="f">يوم الشهر</label><input type="number" min="1" max="28" name="trigger_config[monthday]" value="<?= (int)($cfg['monthday'] ?? 1) ?>"></div></div></details>
    <label class="f">الشروط (كلها لازم تتحقق)</label>
    <?php foreach ($conds as $i => $c): ?><div class="row" style="margin-bottom:6px;flex-wrap:nowrap">
      <select name="conditions[<?= $i ?>][field]"><?= select_opts(wf_condition_fields(), $c['field'], true, '—') ?></select>
      <select name="conditions[<?= $i ?>][op]" style="max-width:110px"><?= select_opts(wf_ops(), $c['op']) ?></select>
      <input type="text" name="conditions[<?= $i ?>][value]" value="<?= h($c['value']) ?>" placeholder="القيمة"></div><?php endforeach; ?>
    <label class="f">الـ Actions (بالترتيب)</label>
    <?php $sales = array_column(q_all("SELECT id, name FROM users WHERE active = 1 ORDER BY name"), 'name', 'id');
    foreach ($acts as $i => $ac): $pp = $ac['params'] ?? []; ?>
      <div class="card flat" style="padding:10px;margin-bottom:8px"><select name="actions[<?= $i ?>][type]"><?= select_opts(wf_action_types(), $ac['type'], true, '— بدون —') ?></select>
        <div class="fgrid">
          <div><label class="f">النص</label><input type="text" name="actions[<?= $i ?>][params][text]" value="<?= h($pp['text'] ?? '') ?>"></div>
          <div><label class="f">ساعات</label><input type="number" step="0.25" name="actions[<?= $i ?>][params][hours]" value="<?= h($pp['hours'] ?? '') ?>"></div>
          <div><label class="f">الدور</label><select name="actions[<?= $i ?>][params][role]"><?= select_opts(['admin' => 'المديرين', 'buyer' => 'ميديا باير', 'sales' => 'مبيعات'], $pp['role'] ?? 'admin') ?></select></div>
          <div><label class="f">مستخدم</label><select name="actions[<?= $i ?>][params][user_id]"><?= select_opts($sales, $pp['user_id'] ?? '', true, '—') ?></select></div>
          <div><label class="f">نوع التقرير</label><select name="actions[<?= $i ?>][params][type]"><?= select_opts(array_diff_key(report_types(), ['campaign' => 1]), $pp['type'] ?? 'weekly') ?></select></div>
          <div><label class="f">Webhook URL</label><input type="url" name="actions[<?= $i ?>][params][url]" value="<?= h($pp['url'] ?? '') ?>" dir="ltr" placeholder="https://"></div>
        </div><div class="hint">كل Action بيستخدم الحقول اللي تخصه بس.</div></div>
    <?php endforeach; ?>
    <?php if (is_admin()): $ap = !empty($cfg['external_approved']); ?><label class="check"><input type="checkbox" name="approve_external" value="1" <?= $ap ? 'checked' : '' ?>> أوافق إن الـ Workflow ده يبعت بيانات لرابط خارجي (Webhook)</label><?php endif; ?>
    <label class="check" style="display:flex;margin-top:8px"><input type="checkbox" name="active" value="1" <?= ($w['active'] ?? 1) ? 'checked' : '' ?>> مفعّل</label>
    <div class="row" style="margin-top:12px"><button class="btn">حفظ</button><a class="btn ghost" href="<?= h(url('automation', $w ? ['wf' => $w['id']] : [])) ?>">إلغاء</a></div>
  </form></div>
<?php elseif ($sel):
  $cfg = json_decode($sel['trigger_config_json'], true) ?: [];
  $p = paginate('SELECT COUNT(*) FROM workflow_executions WHERE workflow_id = ?', [$sel['id']], 20);
  $runs = q_all("SELECT * FROM workflow_executions WHERE workflow_id = ? ORDER BY id DESC LIMIT {$p['per']} OFFSET {$p['offset']}", [$sel['id']]); ?>
<div class="card"><h2><?= h($sel['name']) ?> <span class="status-dot <?= $sel['active'] ? 'ok' : '' ?>"></span></h2>
  <p class="small"><b>Trigger:</b> <?= h(event_types()[$sel['trigger_type']] ?? $sel['trigger_type']) ?>
    <?php if ($sel['trigger_type'] === 'schedule'): ?> — <?= h(['daily' => 'يومي', 'weekly' => 'أسبوعي', 'monthly' => 'شهري'][$cfg['freq']] ?? '') ?> الساعة <?= (int)$cfg['hour'] ?><?php endif; ?><br>
    <b>الشروط:</b> <?php $cs = json_decode($sel['conditions_json'], true) ?: []; echo $cs ? h(implode(' و ', array_map(fn($c) => wf_condition_fields()[$c['field']] . ' ' . wf_ops()[$c['op']] . ' ' . $c['value'], $cs))) : 'بدون'; ?><br>
    <b>الـ Actions:</b> <?= h(implode(' ← ', array_map(fn($a) => wf_action_types()[$a['type']] ?? $a['type'], json_decode($sel['actions_json'], true) ?: []))) ?><br>
    <b>محاولات إعادة:</b> <?= (int)$sel['max_retries'] ?><?= !empty($cfg['external_approved']) ? ' · <span class="badge b-warn">Webhook خارجي متوافق عليه</span>' : '' ?></p>
  <?php if (can('automation.manage')): ?><div class="row">
    <a class="btn sm ghost" href="<?= h(url('automation', ['wf' => $sel['id'], 'edit' => 1])) ?>">تعديل</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="toggle"><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><button class="btn sm ghost"><?= $sel['active'] ? 'تعطيل' : 'تفعيل' ?></button></form>
    <?php if ($sel['trigger_type'] === 'schedule'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="run_now"><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
      <?php if (!$sel['client_id']): ?><select name="client_id" style="max-width:180px"><?= select_opts(client_opts(), '') ?></select><?php endif; ?><button class="btn sm ghost">شغّل دلوقتي</button></form><?php endif; ?>
    <form method="post" data-confirm="تمسح الـ Workflow وسجله؟"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><button class="btn sm ghost">حذف</button></form></div><?php endif; ?>
</div>
<div class="card"><h2>سجل التنفيذ</h2>
  <?php if (!$runs) echo empty_state('لسه مااتشغلش', ' بيشتغل أول ما الـ Trigger يحصل.'); ?>
  <?php foreach ($runs as $x): $st = ['success' => 'b-ok', 'failed' => 'b-bad', 'retrying' => 'b-warn', 'skipped' => '', 'running' => 'b-info'][$x['status']] ?? ''; ?>
    <details style="padding:7px 0;border-bottom:1px solid var(--line)"><summary class="row small" style="display:flex"><span class="badge <?= $st ?>"><?= h($x['status']) ?></span><span class="ltr muted"><?= h($x['dedupe_key']) ?></span><span class="sp"></span><span class="muted">محاولات <?= (int)$x['attempts'] ?> · <?= h(fmt_dt($x['started_at'], 'm-d H:i')) ?></span></summary>
      <ul class="small" style="margin:6px 0"><?php foreach (json_decode((string)$x['log_json'], true) ?: [] as $l) echo '<li>' . ($l['ok'] ? '✔' : '✘') . ' <b>' . h(wf_action_types()[$l['step']] ?? $l['step']) . '</b>: ' . h($l['msg']) . '</li>'; ?></ul>
      <?php if ($x['error']): ?><div class="small" style="color:var(--bad)"><?= h($x['error']) ?><?= $x['next_retry_at'] ? ' — المحاولة الجاية ' . h(fmt_dt($x['next_retry_at'], 'H:i')) : '' ?></div><?php endif; ?></details>
  <?php endforeach; ?><?= pager_html($p) ?></div>
<?php else: ?>
<div class="card"><?= empty_state('اختار Workflow من القايمة', ' أو ابدأ بقالب جاهز.') ?></div>
<?php endif; ?>
</div></div>
<?php layout_end();
