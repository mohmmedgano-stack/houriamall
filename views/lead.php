<?php
$id = (int)in_int('id');
$lead = q_one('SELECT * FROM leads WHERE id = ?', [$id]);
if (!$lead || !lead_visible($lead)) throw new Forbidden('lead');
$editable = can('crm.edit');
if (is_post()) {
    if (!$editable) throw new Forbidden('crm.edit');
    $a = in_str('a');
    switch ($a) {
        case 'stage':
            lead_set_stage($id, (int)in_int('stage_id'), ['deal_value' => in_str('deal_value'), 'lost_reason' => in_str('lost_reason')]);
            flash('اتغيرت المرحلة ✅'); break;
        case 'log':
            $next = in_str('next_follow_up'); $appt = in_str('appointment_at');
            lead_log($id, in_str('type', 'note'), in_str('outcome') ?: null, in_str('body'), $next === '' && in_str('clear_follow') !== '1' ? null : str_replace('T', ' ', $next), $appt ? str_replace('T', ' ', $appt) : null);
            flash('اتسجل ✅'); break;
        case 'assign':
            if (!can('crm.view_all') && !is_admin()) throw new Forbidden('assign');
            lead_assign($id, in_str('owner') === '' ? null : (int)in_str('owner')); flash('اتحدّث المسؤول ✅'); break;
        case 'edit':
            $f = ['full_name' => in_str('full_name') ?: null, 'phone' => in_str('phone') ?: null, 'email' => in_str('email') ?: null, 'city' => in_str('city') ?: null, 'product' => in_str('product') ?: null, 'updated_at' => now()];
            if ($f['email'] && !norm_email($f['email'])) throw new InvalidArgumentException('الإيميل مش صحيح');
            $f['phone_norm'] = norm_phone($f['phone']); $f['email_norm'] = norm_email($f['email']);
            $cp = in_int('campaign_id');
            $f['campaign_id'] = $cp && campaign_client_id($cp) === (int)$lead['client_id'] ? $cp : null;
            db_update('leads', $id, $f);
            lead_activity($id, 'edit', null, 'اتعدلت بيانات الليد');
            audit('lead.update', 'lead', $id); flash('اتحفظ ✅'); break;
        case 'not_duplicate':
            db_update('leads', $id, ['duplicate_of' => null, 'updated_at' => now()]); lead_activity($id, 'edit', null, 'اتشال تعليم التكرار'); flash('اتشال تعليم التكرار'); break;
        case 'delete':
            if (!is_admin()) throw new Forbidden('delete');
            q('UPDATE leads SET duplicate_of = NULL WHERE duplicate_of = ?', [$id]);
            q('DELETE FROM leads WHERE id = ?', [$id]);   // activities & assignments cascade
            audit('lead.delete', 'lead', $id, ['client_id' => $lead['client_id']]);   // no personal data in the log
            flash('اتمسح الليد وكل بياناته نهائياً'); redirect(url('crm'));
    }
    redirect(url('lead', ['id' => $id]));
}
$st = stage((int)$lead['stage_id']);
$acts = q_all('SELECT a.*, u.name uname FROM lead_activities a LEFT JOIN users u ON u.id = a.user_id WHERE a.lead_id = ? ORDER BY a.id DESC', [$id]);
$client = q_one('SELECT * FROM clients WHERE id = ?', [$lead['client_id']]);
$camps = q_all('SELECT cp.id, cp.name FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE a.client_id = ? ORDER BY cp.name', [$lead['client_id']]);
$cpName = $lead['campaign_id'] ? q_val('SELECT name FROM campaigns WHERE id = ?', [$lead['campaign_id']]) : null;
$adName = $lead['ad_id'] ? q_val('SELECT name FROM ads WHERE id = ?', [$lead['ad_id']]) : null;
$dups = q_all('SELECT id, full_name, received_at, source FROM leads WHERE duplicate_of = ? ORDER BY received_at', [$id]);
$owner = $lead['owner_user_id'] ? q_val('SELECT name FROM users WHERE id = ?', [$lead['owner_user_id']]) : null;
layout_start($lead['full_name'] ?: 'ليد #' . $id); ?>
<div class="row" style="margin-bottom:12px">
  <span class="badge" style="border-color:<?= h($st['color']) ?>;color:<?= h($st['color']) ?>;font-size:13px"><?= h($st['name']) ?></span>
  <?php if ($lead['duplicate_of']): ?><span class="badge b-warn">مكرر لـ <a href="<?= h(url('lead', ['id' => $lead['duplicate_of']])) ?>">#<?= (int)$lead['duplicate_of'] ?></a></span><?php endif; ?>
  <span class="muted small"><?= h($client['name']) ?> · المسؤول: <?= h($owner ?: 'غير مسند') ?> · وصل <?= h(fmt_dt($lead['received_at'])) ?></span>
  <?php if ($lead['next_follow_up_at']): ?><span class="badge <?= $lead['next_follow_up_at'] < now() ? 'b-bad' : 'b-info' ?>">المتابعة: <span class="ltr"><?= h(fmt_dt($lead['next_follow_up_at'])) ?></span></span><?php endif; ?>
</div>
<div class="grid g2">
<div class="stack">
  <?php if ($editable): ?>
  <div class="card"><h2>تسجيل متابعة</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="log">
      <div class="fgrid">
        <div><label class="f">النوع</label><select name="type"><?= select_opts(activity_types(), 'call') ?></select></div>
        <div><label class="f">النتيجة (للمكالمات)</label><select name="outcome"><?= select_opts(call_outcomes(), '', true, '—') ?></select></div>
        <div><label class="f">المتابعة الجاية</label><input type="datetime-local" name="next_follow_up"></div>
        <div><label class="f">معاد (لو اتحدد)</label><input type="datetime-local" name="appointment_at"></div>
      </div>
      <label class="f">ملاحظات</label><textarea name="body" style="min-height:70px" placeholder="اتكلمنا عن…"></textarea>
      <label class="check small"><input type="checkbox" name="clear_follow" value="1"> شيل معاد المتابعة الحالي</label>
      <div><button class="btn" style="margin-top:10px">تسجيل</button></div></form></div>
  <div class="card"><h2>المرحلة</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="stage">
      <div class="fgrid"><div><label class="f">المرحلة</label><select name="stage_id"><?= select_opts(array_column(stages(), 'name', 'id'), $lead['stage_id']) ?></select></div>
        <div><label class="f">قيمة البيع (لو Won)</label><input type="number" step="0.01" min="0" name="deal_value" value="<?= h($lead['deal_value'] ?? '') ?>"><div class="hint">بعملة <?= h($lead['deal_currency'] ?: $client['currency']) ?> — بتدخل في ROAS الحقيقي وربحية العميل.</div></div>
        <div><label class="f">سبب الخسارة (لو Lost)</label><input type="text" name="lost_reason" list="lostReasons" value="<?= h($lead['lost_reason'] ?? '') ?>">
          <datalist id="lostReasons"><option>السعر</option><option>مش مهتم</option><option>مش بيرد</option><option>اشترى من منافس</option><option>بيانات غلط</option><option>خارج المنطقة</option><option>مش مؤهل</option></datalist></div></div>
      <button class="btn" style="margin-top:10px">حفظ</button></form></div>
  <?php if (can('crm.view_all') || is_admin()): ?>
  <div class="card"><h2>المسؤول</h2><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="assign">
    <select name="owner" style="flex:1"><?= select_opts(array_column(assignable_users((int)$lead['client_id']), 'name', 'id'), $lead['owner_user_id'], true, 'غير مسند') ?></select><button class="btn">حفظ</button></form></div>
  <?php endif; endif; ?>
  <div class="card"><h2>البيانات</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="edit">
      <div class="fgrid">
        <div><label class="f">الاسم</label><input type="text" name="full_name" value="<?= h($lead['full_name']) ?>" <?= $editable ? '' : 'readonly' ?>></div>
        <div><label class="f">التليفون</label><input type="text" name="phone" value="<?= h($lead['phone']) ?>" dir="ltr" <?= $editable ? '' : 'readonly' ?>></div>
        <div><label class="f">الإيميل</label><input type="email" name="email" value="<?= h($lead['email']) ?>" dir="ltr" <?= $editable ? '' : 'readonly' ?>></div>
        <div><label class="f">المدينة</label><input type="text" name="city" value="<?= h($lead['city']) ?>" <?= $editable ? '' : 'readonly' ?>></div>
        <div><label class="f">المنتج</label><input type="text" name="product" value="<?= h($lead['product']) ?>" <?= $editable ? '' : 'readonly' ?>></div>
        <div><label class="f">الحملة</label><select name="campaign_id" <?= $editable ? '' : 'disabled' ?>><?= select_opts(array_column($camps, 'name', 'id'), $lead['campaign_id'], true, '—') ?></select></div>
      </div>
      <?php if ($editable): ?><button class="btn ghost" style="margin-top:10px">حفظ البيانات</button><?php endif; ?></form>
    <?php if ($lead['phone']): $wa = norm_phone($lead['phone']); ?><div class="row" style="margin-top:10px"><a class="btn sm ghost" href="tel:<?= h($lead['phone']) ?>">اتصال</a><?php if ($wa): ?><a class="btn sm ghost" href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener">واتساب</a><?php endif; ?></div><?php endif; ?>
    <hr><div class="small muted">
      المصدر: <b><?= h($lead['source']) ?></b><?= $lead['form_name'] ? ' · الفورم: ' . h($lead['form_name']) : '' ?><br>
      الحملة: <?= $cpName ? '<a href="' . h(url('campaign', ['id' => $lead['campaign_id']])) . '">' . h($cpName) . '</a>' : '—' ?><?= $adName ? ' · الإعلان: ' . h($adName) : '' ?><br>
      UTM: <span class="ltr"><?= h(implode(' / ', array_filter([$lead['utm_source'], $lead['utm_medium'], $lead['utm_campaign'], $lead['utm_content'], $lead['utm_term']])) ?: '—') ?></span><br>
      أول تواصل: <?= h(fmt_dt($lead['first_contact_at'])) ?><?= $lead['first_contact_at'] ? ' (بعد ' . round((strtotime($lead['first_contact_at']) - strtotime($lead['received_at'])) / 3600, 1) . ' ساعة)' : '' ?></div>
    <?php if ($lead['extra_json']): ?><details class="box" style="margin-top:10px"><summary class="small">إجابات الفورم</summary><dl class="small"><?php foreach (json_decode($lead['extra_json'], true) ?: [] as $k => $v) echo '<dt><b>' . h($k) . '</b></dt><dd>' . h(is_array($v) ? implode(', ', $v) : $v) . '</dd>'; ?></dl></details><?php endif; ?>
  </div>
</div>
<div class="stack">
  <div class="card"><h2>السجل الزمني</h2>
    <ul class="timeline"><?php foreach ($acts as $a): ?>
      <li><span class="dotc" style="background:<?= in_array($a['type'], CONTACT_TYPES, true) ? 'var(--acc)' : ($a['type'] === 'stage_change' ? 'var(--info)' : 'var(--line2)') ?>"></span>
        <div style="flex:1"><b><?= h(activity_types()[$a['type']] ?? ['created' => 'إنشاء', 'stage_change' => 'تغيير مرحلة', 'assign' => 'إسناد', 'edit' => 'تعديل', 'duplicate' => 'تكرار', 'automation' => 'أتمتة'][$a['type']] ?? $a['type']) ?></b>
          <?= $a['outcome'] ? ' <span class="badge">' . h(call_outcomes()[$a['outcome']] ?? $a['outcome']) . '</span>' : '' ?>
          <?php if ($a['body']): ?><div class="small" style="white-space:pre-wrap"><?= h($a['body']) ?></div><?php endif; ?>
          <div class="muted small"><?= h($a['uname'] ?: 'النظام') ?> · <span class="ltr"><?= h(fmt_dt($a['created_at'])) ?></span></div></div></li>
    <?php endforeach; ?></ul></div>
  <?php if ($dups): ?><div class="card"><h2>ليدز متعلّمة كمكررة من ده</h2><?php foreach ($dups as $d) echo '<div class="small"><a href="' . h(url('lead', ['id' => $d['id']])) . '">#' . (int)$d['id'] . ' ' . h($d['full_name']) . '</a> · ' . h($d['source']) . ' · ' . h(fmt_dt($d['received_at'])) . '</div>'; ?></div><?php endif; ?>
  <?php if ($editable && $lead['duplicate_of']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="not_duplicate"><button class="btn ghost sm">ده مش مكرر</button></form><?php endif; ?>
  <?php if (is_admin()): ?><details class="box"><summary style="color:var(--bad)">حذف بيانات الليد نهائياً</summary>
    <p class="small muted">لطلبات حذف البيانات الشخصية. بيمسح الليد وكل سجله ومش بيتسجل أي بيانات شخصية في سجل العمليات.</p>
    <form method="post" data-confirm="حذف نهائي؟"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><button class="btn danger sm">حذف نهائي</button></form></details><?php endif; ?>
</div></div>
<?php layout_end();
