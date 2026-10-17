<?php
if (is_post()) {
    require_perm('audits.manage');
    $a = in_str('a');
    if ($a === 'create') {
        $id = audit_create((int)in_int('client'), in_int('campaign') ?: null, in_int('template') ?: null, in_str('name'));
        redirect(url('auditor', ['view' => $id]));
    }
    $aud = q_one('SELECT * FROM campaign_audits WHERE id = ?', [(int)in_int('id')]);
    if (!$aud || !can_client((int)$aud['client_id'])) throw new Forbidden('audit');
    if ($a === 'update') {
        $res = json_decode($aud['results_json'], true);
        foreach ($res as $i => &$r) {
            $st = (string)($_POST['status'][$i] ?? $r['status']);
            if (!isset(AUDIT_STATUSES[$st])) continue;
            $note = trim((string)($_POST['note'][$i] ?? $r['note']));
            if ($st !== $r['status'] || $note !== $r['note']) { $r['status'] = $st; $r['note'] = mb_substr($note, 0, 500); $r['source'] = 'manual'; $r['checked_by'] = current_user()['name']; }
        }
        unset($r);
        db_update('campaign_audits', (int)$aud['id'], ['results_json' => json_encode($res, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        audit('audit.update', 'campaign_audit', $aud['id']);
        flash('اتحفظت المراجعة ✅');
    } elseif ($a === 'rerun') {
        $res = json_decode($aud['results_json'], true);
        $auto = audit_auto_checks($aud['campaign_id'] ? (int)$aud['campaign_id'] : null);
        foreach ($res as &$r) {
            // only refresh items that are still automatic or unchecked — never overwrite a human decision
            if (!$r['auto'] || !isset($auto[$r['auto']]) || (($r['source'] ?? '') === 'manual' && $r['status'] !== 'not_checked')) continue;
            [$r['status'], $r['note']] = $auto[$r['auto']];
            $r['source'] = $r['status'] === 'not_checked' ? 'manual' : 'auto';
        }
        unset($r);
        db_update('campaign_audits', (int)$aud['id'], ['results_json' => json_encode($res, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        flash('اتعاد الفحص الآلي (البنود اللي عدلتها يدوي متغيرتش)');
    } elseif ($a === 'delete') {
        q('DELETE FROM campaign_audits WHERE id = ?', [$aud['id']]); flash('اتمسحت'); redirect(url('auditor'));
    }
    redirect(url('auditor', ['view' => $aud['id']]));
}
$ids = filter_client_ids();
$view = in_int('view') ? q_one('SELECT ca.*, c.name client_name, cp.name campaign_name FROM campaign_audits ca JOIN clients c ON c.id = ca.client_id LEFT JOIN campaigns cp ON cp.id = ca.campaign_id WHERE ca.id = ?', [in_int('view')]) : null;
if ($view && !can_client((int)$view['client_id'])) $view = null;
$list = $ids ? q_all('SELECT ca.*, c.name client_name, cp.name campaign_name FROM campaign_audits ca JOIN clients c ON c.id = ca.client_id LEFT JOIN campaigns cp ON cp.id = ca.campaign_id
    WHERE ca.client_id IN (' . in_list($ids) . ') ORDER BY ca.id DESC LIMIT 50') : [];
$formClient = in_int('client') ?: ($ids[0] ?? null);
layout_start('Campaign Auditor'); ?>
<?php if ($view): $res = json_decode($view['results_json'], true); $sum = audit_summary($res); ?>
<div class="card" style="margin-bottom:16px">
  <h2><?= h($view['name']) ?> <span class="muted small"><?= h($view['client_name']) ?><?= $view['campaign_name'] ? ' · ' . h($view['campaign_name']) : '' ?></span></h2>
  <div class="row" style="margin-bottom:12px"><?php foreach ($sum as $s => $n) echo audit_status_badge($s) . ' <b>' . $n . '</b> &nbsp; '; ?></div>
  <?php if ($sum['fail']): ?><div class="flash err"><?= $sum['fail'] ?> بند فشل — راجعهم قبل الإطلاق.</div><?php endif; ?>
  <?php if ($sum['not_checked']): ?><div class="flash warn"><?= $sum['not_checked'] ?> بند "Not Checked": الأداة مش قادرة توصل للإعداد ده من البيانات المتاحة، فلازم تراجعه بنفسك وتغيّر حالته.</div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="update"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
  <div class="tbl"><table class="t cards"><thead><tr><th>البند</th><th>الفئة</th><th>الحالة</th><th>الملاحظة</th><th>المصدر</th></tr></thead><tbody>
  <?php foreach ($res as $i => $r): ?><tr>
    <td class="main-cell"><?= h($r['label']) ?></td><td data-l="الفئة" class="small"><?= h($r['cat']) ?></td>
    <td data-l="الحالة"><?php if (can('audits.manage')): ?><select name="status[<?= $i ?>]" style="min-width:130px"><?= select_opts(AUDIT_STATUSES, $r['status']) ?></select><?php else: echo audit_status_badge($r['status']); endif; ?></td>
    <td data-l="الملاحظة" style="min-width:220px"><?php if (can('audits.manage')): ?><input type="text" name="note[<?= $i ?>]" value="<?= h($r['note']) ?>"><?php else: echo h($r['note']); endif; ?></td>
    <td data-l="المصدر" class="small"><?= ($r['source'] ?? '') === 'auto' ? '<span class="badge b-info">آلي</span>' : '<span class="badge">يدوي' . (isset($r['checked_by']) ? ' · ' . h($r['checked_by']) : '') . '</span>' ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if (can('audits.manage')): ?><div class="row" style="margin-top:12px"><button class="btn">حفظ</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="rerun"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>"><button class="btn ghost">إعادة الفحص الآلي</button></form>
    <form method="post" data-confirm="تمسح المراجعة؟"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>"><button class="btn ghost">حذف</button></form></div>
  <?php else: ?></form><?php endif; ?>
</div>
<?php endif; ?>

<div class="grid g2">
<?php if (can('audits.manage') && $formClient): $camps = q_all('SELECT cp.id, cp.name FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE a.client_id = ? ORDER BY cp.name', [$formClient]); ?>
<div class="card"><h2>مراجعة جديدة قبل الإطلاق</h2>
  <form class="row" style="margin-bottom:4px"><input type="hidden" name="r" value="auditor"><span class="muted small">العميل:</span><select name="client" onchange="this.form.submit()" style="max-width:260px"><?= select_opts(client_opts(), $formClient) ?></select></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="create"><input type="hidden" name="client" value="<?= (int)$formClient ?>">
    <label class="f">اسم المراجعة</label><input type="text" name="name" placeholder="مثلاً: إطلاق حملة الساحل — أكتوبر">
    <label class="f">الحملة (اختياري — للفحص الآلي)</label><select name="campaign"><?= select_opts(array_column($camps, 'name', 'id'), in_int('campaign'), true, 'حملة لسه مااتعملتش') ?></select>
    <div class="hint">البنود اللي البيانات تسمح بفحصها بتتفحص آلي، والباقي بيبدأ "Not Checked" لحد ما تراجعه بنفسك.</div>
    <button class="btn" style="margin-top:12px">ابدأ المراجعة</button></form></div>
<?php endif; ?>
<div class="card"><h2>المراجعات السابقة</h2>
  <?php if (!$list) echo empty_state('مفيش مراجعات'); else foreach ($list as $l): $s = audit_summary(json_decode($l['results_json'], true)); ?>
    <div class="row" style="padding:8px 0;border-bottom:1px solid var(--line)"><a href="<?= h(url('auditor', ['view' => $l['id']])) ?>"><b><?= h($l['name']) ?></b></a>
      <span class="muted small"><?= h($l['client_name']) ?><?= $l['campaign_name'] ? ' · ' . h($l['campaign_name']) : '' ?></span><span class="sp"></span>
      <span class="badge b-ok"><?= $s['pass'] ?></span><span class="badge b-warn"><?= $s['warning'] ?></span><span class="badge b-bad"><?= $s['fail'] ?></span><span class="badge"><?= $s['not_checked'] ?></span></div>
  <?php endforeach; ?></div>
</div>
<?php layout_end();
