<?php
if (is_post()) {
    require_perm('reports.manage');
    if (in_str('a') === 'generate') {
        $cid = (int)in_int('client_id');
        if (!can_client($cid)) throw new Forbidden('client');
        $type = in_str('type');
        [$df, $dt] = report_default_period($type);
        $from = in_date('from') ?: $df; $to = in_date('to') ?: $dt;
        $data = report_build($type, $cid, $from, $to, in_int('campaign_id') ?: null);
        $summary = null; $ai = false;
        if (in_str('ai') === '1' && function_exists('ai_report_summary')) {
            [$summary, $err] = ai_report_summary($data);
            if ($err) flash('الملخص بالـ AI مااشتغلش (' . $err . ') — استخدمت الملخص المحسوب من الأرقام.', 'warn'); else $ai = true;
        }
        $id = report_save($data, $summary, $ai);
        redirect(url('report', ['id' => $id]));
    }
    if (in_str('a') === 'delete') {
        $r = q_one('SELECT * FROM reports WHERE id = ?', [(int)in_int('id')]);
        if (!$r || !can_client((int)$r['client_id'])) throw new Forbidden('report');
        q('DELETE FROM reports WHERE id = ?', [$r['id']]); audit('report.delete', 'report', $r['id']); flash('اتمسح التقرير');
        redirect(url('reports'));
    }
}
$ids = filter_client_ids();
$list = $ids ? q_all('SELECT r.id, r.title, r.type, r.created_at, r.ai_used, c.name cname, u.name uname FROM reports r JOIN clients c ON c.id = r.client_id LEFT JOIN users u ON u.id = r.created_by
    WHERE r.client_id IN (' . in_list($ids) . ') ORDER BY r.id DESC LIMIT 60') : [];
$fc = in_int('client') ?: ($ids[0] ?? null);
$type = array_key_exists(in_str('type'), report_types()) ? in_str('type') : 'weekly';
[$df, $dt] = report_default_period($type);
$camps = $fc ? q_all('SELECT cp.id, cp.name FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE a.client_id = ? ORDER BY cp.name', [$fc]) : [];
layout_start('Auto Report Generator'); ?>
<div class="grid g2">
<?php if (can('reports.manage') && $fc): ?>
<div class="card"><h2>تقرير جديد</h2>
  <form class="filters" style="margin:0"><input type="hidden" name="r" value="reports">
    <div><label>العميل</label><select name="client" onchange="this.form.submit()"><?= select_opts(client_opts(), $fc) ?></select></div>
    <div><label>النوع</label><select name="type" onchange="this.form.submit()"><?= select_opts(report_types(), $type) ?></select></div></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="generate"><input type="hidden" name="client_id" value="<?= (int)$fc ?>"><input type="hidden" name="type" value="<?= h($type) ?>">
    <div class="fgrid">
      <div><label class="f">من</label><input type="date" name="from" value="<?= h($df) ?>"></div><div><label class="f">إلى</label><input type="date" name="to" value="<?= h($dt) ?>"></div>
      <?php if ($type === 'campaign'): ?><div><label class="f">الحملة *</label><select name="campaign_id" required><?= select_opts(array_column($camps, 'name', 'id'), in_int('campaign')) ?></select></div><?php endif; ?>
    </div>
    <?php if (function_exists('ai_available') && ai_available()): ?><label class="check" style="margin-top:10px"><input type="checkbox" name="ai" value="1"> اكتب الملخص التنفيذي بالـ AI (من أرقام التقرير بس)</label>
    <?php else: ?><p class="hint">الملخص بيتحسب من الأرقام مباشرة. تقدر تفعّل كتابته بالـ AI من صفحة التكاملات.</p><?php endif; ?>
    <div><button class="btn" style="margin-top:12px">إنشاء التقرير</button></div>
    <p class="hint">التقرير فيه: ملخص تنفيذي، الصرف والنتائج والتكلفة، مقارنة بالفترة السابقة، أفضل وأضعف الحملات حسب الهدف، جودة الليدز من الـ CRM، الميزانية، المشكلات، والتوصيات. بيتصدّر PDF و Excel و CSV بالعربي، وبيحط شعار العميل لو موجود. للتقارير الأوتوماتيك كل أسبوع استخدم Automation Hub.</p>
  </form></div>
<?php endif; ?>
<div class="card"><h2>التقارير المحفوظة</h2>
  <?php if (!$list): echo empty_state('مفيش تقارير لسه'); else: ?>
  <?php foreach ($list as $r): ?><div class="row" style="padding:7px 0;border-bottom:1px solid var(--line)">
    <a href="<?= h(url('report', ['id' => $r['id']])) ?>" style="flex:1"><?= h($r['title']) ?></a><?= $r['ai_used'] ? '<span class="badge b-info">AI</span>' : '' ?>
    <span class="muted small"><?= h($r['uname'] ?: 'أوتوماتيك') ?> · <?= h(fmt_dt($r['created_at'], 'm-d H:i')) ?></span></div><?php endforeach; endif; ?>
</div></div>
<?php layout_end();
