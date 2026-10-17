<?php
if (is_post()) {
    require_perm('budgets.manage');
    $a = in_str('a');
    if ($a === 'save') {
        $bid = in_int('id');
        if ($bid && !can_client((int)q_val('SELECT client_id FROM budgets WHERE id = ?', [$bid]))) throw new Forbidden('budget');
        $bid = budget_save($bid, $_POST);
        flash('اتحفظت الميزانية ✅');
        redirect(url('budgets', ['view' => $bid]));
    }
    if ($a === 'delete') {
        $bid = (int)in_int('id');
        if (!can_client((int)q_val('SELECT client_id FROM budgets WHERE id = ?', [$bid]))) throw new Forbidden('budget');
        q('DELETE FROM budgets WHERE id = ?', [$bid]); audit('budget.delete', 'budget', $bid);
        flash('اتمسحت الميزانية'); redirect(url('budgets'));
    }
}
$ids = filter_client_ids();
$list = budgets_for($ids);
$show = in_str('period', 'current');
if ($show === 'current') $list = array_values(array_filter($list, fn($b) => $b['period_end'] >= today()));
$view = in_int('view') ? q_one('SELECT b.*, c.name client_name FROM budgets b JOIN clients c ON c.id = b.client_id WHERE b.id = ?', [in_int('view')]) : null;
if ($view && !can_client((int)$view['client_id'])) $view = null;
$edit = in_int('edit') ? q_one('SELECT * FROM budgets WHERE id = ?', [in_int('edit')]) : null;
if ($edit && !can_client((int)$edit['client_id'])) $edit = null;
$formClient = $edit['client_id'] ?? (in_int('client') ?: ($ids[0] ?? null));
layout_start('Budget Planner'); ?>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="budgets">
  <div><label>العميل</label><select name="client"><?= select_opts(client_opts(), in_int('client'), true, 'كل العملاء') ?></select></div>
  <div><label>الفترة</label><select name="period"><?= select_opts(['current' => 'الحالية والقادمة', 'all' => 'الكل'], $show) ?></select></div>
</form>

<?php if ($view): $s = budget_status($view); $el = max(0, min(2, (float)(in_num('elasticity') ?? 0.3))); $sc = budget_scenarios($view, $s, $el); $cur = $view['currency']; ?>
<div class="card" style="margin-bottom:16px">
  <h2><?= h($view['name']) ?> <span class="muted small"><?= h($view['client_name']) ?> · <span class="ltr"><?= h($view['period_start']) ?> → <?= h($view['period_end']) ?></span></span><span class="sp"></span>
    <?php if (can('budgets.manage')): ?><a class="btn sm ghost" href="<?= h(url('budgets', ['edit' => $view['id']])) ?>#form">تعديل</a><?php endif; ?></h2>
  <?php foreach ($s['alerts'] as [$sev, $msg]): ?><div class="flash <?= $sev === 'critical' ? 'err' : 'warn' ?>"><?= h($msg) ?></div><?php endforeach; ?>
  <?php if (!$s['has_data']): ?><div class="flash warn">مفيش بيانات صرف للفترة دي لسه — الأرقام الفعلية صفر لحد ما تستورد أو تزامن البيانات.</div><?php endif; ?>
  <h3 class="small muted" style="margin:0 0 6px">فعلي (من بيانات الأداء)</h3>
  <div class="kpis">
    <?= kpi('المخطط', fmt_money($view['amount'], $cur, 0)) ?>
    <?= kpi('المصروف فعلياً', fmt_money($s['spent'], $cur, 0), null, false, '', '') ?>
    <?= kpi('نسبة الاستهلاك', fmt_pct($s['pct_used'], 1)) ?>
    <?= kpi('المتبقي', fmt_money($s['remaining'], $cur, 0)) ?>
    <?= kpi('متوسط الصرف اليومي', fmt_money($s['avg_daily'], $cur, 0)) ?>
    <?= kpi('الأيام', $s['elapsed_days'] . ' / ' . $s['total_days']) ?>
    <?= kpi(metric_label($s['result_key']) . ' فعلية', fmt_num($s['results'])) ?>
    <?= kpi('التكلفة الفعلية لكل نتيجة', fmt_money($s['cost_per_result'], $cur)) ?>
  </div>
  <div class="bar <?= ($s['pct_used'] ?? 0) > 1 ? 'bad' : (($s['pct_used'] ?? 0) * 100 >= $view['alert_pct'] ? 'warn' : '') ?>" style="margin:14px 0 4px"><i style="width:<?= min(100, round(($s['pct_used'] ?? 0) * 100)) ?>%"></i></div>
  <div class="muted small">المفروض يكون اتصرف لحد النهارده حسب الخطة: <?= fmt_money($s['planned_by_now'], $cur, 0) ?> — معدل الصرف <?= $s['pace'] === null ? 'N/A' : number_format($s['pace'] * 100) . '% من الخطة' ?></div>
  <h3 class="small muted" style="margin:16px 0 6px">توقعات (تقدير، مش بيانات فعلية)</h3>
  <div class="kpis">
    <?= kpi('الصرف المتوقع لنهاية الفترة', fmt_money($s['projected_spend'], $cur, 0), null, false, 'المصروف + متوسط الصرف اليومي × الأيام الباقية') ?>
    <?= kpi('الصرف اليومي المطلوب', fmt_money($s['needed_daily'], $cur, 0), null, false, 'المتبقي ÷ الأيام الباقية عشان تخلص الميزانية بالظبط') ?>
    <?= kpi(metric_label($s['result_key']) . ' متوقعة', fmt_num($s['projected_results']), null, false, 'بافتراض إن التكلفة لكل نتيجة تفضل زي ما هي') ?>
    <?= kpi('المستهدف', fmt_num($s['target_results']), null, false, 'النتائج المستهدفة، أو الميزانية ÷ التكلفة المستهدفة') ?>
  </div>
  <?php if ($sc): ?>
  <h3 class="small muted" style="margin:18px 0 6px">سيناريوهات تغيير الميزانية اليومية من النهارده</h3>
  <form class="row small" style="margin-bottom:8px"><input type="hidden" name="r" value="budgets"><input type="hidden" name="view" value="<?= (int)$view['id'] ?>">
    افتراض المرونة (Elasticity): <input type="number" step="0.05" min="0" max="2" name="elasticity" value="<?= h((string)$el) ?>" style="width:90px"><button class="btn sm ghost">إعادة حساب</button>
    <span class="muted">كل ما يزيد الرقم، التكلفة لكل نتيجة بتزيد أسرع مع زيادة الصرف. 0 = زيادة خطية (نادراً ما بتحصل).</span></form>
  <div class="tbl"><table class="t cards"><thead><tr><th>التغيير</th><th class="n">صرف يومي</th><th class="n">إجمالي الصرف المتوقع</th><th class="n">تكلفة/نتيجة متوقعة</th><th class="n">نتائج متوقعة</th><th></th></tr></thead><tbody>
  <?php foreach ($sc as $x): ?><tr><td class="main-cell"><?= ($x['change'] > 0 ? '+' : '') . round($x['change'] * 100) ?>%</td><td class="n" data-l="صرف يومي"><?= fmt_money($x['daily'], $cur, 0) ?></td>
    <td class="n" data-l="إجمالي"><?= fmt_money($x['total_spend'], $cur, 0) ?></td><td class="n" data-l="تكلفة/نتيجة"><?= fmt_money($x['cpr'], $cur) ?></td><td class="n" data-l="نتائج"><?= fmt_num($x['results']) ?></td>
    <td><?= $x['over_budget'] ? '<span class="badge b-bad">يتخطى الميزانية</span>' : '' ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>الميزانيات</h2>
  <?php if (!$list): echo empty_state('مفيش ميزانيات', ' ضيف ميزانية شهرية للعميل أو لحساب أو لحملة.'); else: ?>
  <div class="tbl"><table class="t cards"><thead><tr><th>الميزانية</th><th>العميل</th><th>النطاق</th><th>الفترة</th><th class="n">المخطط</th><th class="n">المصروف</th><th style="min-width:140px">الاستهلاك</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $b): $s = budget_status($b); $pct = $s['pct_used'] ?? 0; ?>
    <tr><td class="main-cell"><a href="<?= h(url('budgets', ['view' => $b['id']])) ?>"><?= h($b['name']) ?></a> <?= $s['alerts'] ? '<span class="badge b-warn">' . count($s['alerts']) . ' تنبيه</span>' : '' ?></td>
      <td data-l="العميل"><?= h($b['client_name']) ?></td>
      <td data-l="النطاق" class="small"><?= h($b['campaign_name'] ?: ($b['account_name'] ?: 'العميل كله')) ?></td>
      <td data-l="الفترة" class="ltr small"><?= h($b['period_start']) ?> → <?= h($b['period_end']) ?></td>
      <td class="n" data-l="المخطط"><?= fmt_money($b['amount'], $b['currency'], 0) ?></td><td class="n" data-l="المصروف"><?= fmt_money($s['spent'], $b['currency'], 0) ?></td>
      <td data-l="الاستهلاك"><div class="bar <?= $pct > 1 ? 'bad' : ($pct * 100 >= $b['alert_pct'] ? 'warn' : '') ?>"><i style="width:<?= min(100, round($pct * 100)) ?>%"></i></div><span class="small"><?= round($pct * 100) ?>%</span></td>
      <td><?php if (can('budgets.manage')): ?><form method="post" data-confirm="تمسح الميزانية دي؟"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn sm ghost">حذف</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>

<?php if (can('budgets.manage') && $formClient):
  $accs = q_all('SELECT * FROM ad_accounts WHERE client_id = ?', [$formClient]);
  $camps = q_all('SELECT cp.id, cp.name FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE a.client_id = ? ORDER BY cp.name', [$formClient]);
  $ccur = q_val('SELECT currency FROM clients WHERE id = ?', [$formClient]);
  $mStart = (new DateTimeImmutable(today()))->modify('first day of this month')->format('Y-m-d'); $mEnd = (new DateTimeImmutable(today()))->modify('last day of this month')->format('Y-m-d'); ?>
<div class="card" id="form" style="margin-top:16px"><h2><?= $edit ? 'تعديل الميزانية' : 'ميزانية جديدة' ?></h2>
  <form class="row" style="margin-bottom:6px"><input type="hidden" name="r" value="budgets"><span class="muted small">العميل:</span><select name="client" onchange="this.form.submit()" style="max-width:260px"><?= select_opts(client_opts(), $formClient) ?></select></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="client_id" value="<?= (int)$formClient ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="fgrid">
      <div><label class="f">الاسم *</label><input type="text" name="name" required value="<?= h($edit['name'] ?? 'ميزانية ' . local_date('F Y')) ?>"></div>
      <div><label class="f">النطاق: حساب</label><select name="ad_account_id"><?= select_opts(array_column($accs, 'name', 'id'), $edit['ad_account_id'] ?? '', true, 'كل الحسابات') ?></select></div>
      <div><label class="f">أو حملة</label><select name="campaign_id"><?= select_opts(array_column($camps, 'name', 'id'), $edit['campaign_id'] ?? '', true, '—') ?></select></div>
      <div><label class="f">من *</label><input type="date" name="period_start" required value="<?= h($edit['period_start'] ?? $mStart) ?>"></div>
      <div><label class="f">إلى *</label><input type="date" name="period_end" required value="<?= h($edit['period_end'] ?? $mEnd) ?>"></div>
      <div><label class="f">المبلغ *</label><input type="number" step="0.01" min="0" name="amount" required value="<?= h($edit['amount'] ?? '') ?>"></div>
      <div><label class="f">العملة</label><select name="currency"><?= select_opts(array_combine(currencies(), currencies()), $edit['currency'] ?? $ccur) ?></select><div class="hint">لو اخترت حساب أو حملة، عملة الحساب بتتطبق.</div></div>
      <div><label class="f">المستهدف</label><select name="target_metric"><?= select_opts(['cpl' => 'CPL (ليدز)', 'cpa' => 'CPA (مبيعات)'], $edit['target_metric'] ?? 'cpl', true, '—') ?></select></div>
      <div><label class="f">التكلفة المستهدفة</label><input type="number" step="0.01" name="target_cost" value="<?= h($edit['target_cost'] ?? '') ?>"></div>
      <div><label class="f">النتائج المستهدفة</label><input type="number" step="1" name="target_results" value="<?= h($edit['target_results'] ?? '') ?>"></div>
      <div><label class="f">نبّهني عند استهلاك %</label><input type="number" step="1" name="alert_pct" value="<?= h($edit['alert_pct'] ?? 90) ?>"></div>
    </div>
    <label class="f">ملاحظات</label><textarea name="notes" style="min-height:50px"><?= h($edit['notes'] ?? '') ?></textarea>
    <button class="btn" style="margin-top:12px">حفظ</button></form></div>
<?php endif; layout_end();
