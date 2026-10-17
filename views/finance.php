<?php
if (is_post()) {
    require_perm('finance.manage');
    $a = in_str('a');
    switch ($a) {
        case 'service': service_save(in_int('id'), $_POST); flash('اتحفظت الخدمة ✅'); break;
        case 'invoice': invoice_create($_POST); flash('اتعملت الفاتورة ✅'); break;
        case 'pay': invoice_pay((int)in_int('id'), (float)(in_num('amount') ?? 0), in_date('date')); flash('اتسجل الدفع ✅'); break;
        case 'void':
            $inv = q_one('SELECT * FROM client_invoices WHERE id = ?', [(int)in_int('id')]);
            if (!$inv || !can_client((int)$inv['client_id'])) throw new Forbidden('invoice');
            if ((float)$inv['paid_amount'] > 0) throw new InvalidArgumentException('مينفعش تلغي فاتورة عليها مدفوعات');
            db_update('client_invoices', (int)$inv['id'], ['status' => 'void', 'updated_at' => now()]); audit('invoice.void', 'invoice', $inv['id']); flash('اتلغت الفاتورة'); break;
        case 'generate': $n = invoices_generate_monthly(); flash($n ? "اتعمل $n فاتورة للشهر ده ✅" : 'مفيش فواتير جديدة — كل الاشتراكات الشهرية ليها فواتير الشهر ده'); break;
        case 'expense': expense_save($_POST); flash('اتسجل المصروف ✅'); break;
        case 'expense_delete':
            $e = q_one('SELECT * FROM expenses WHERE id = ?', [(int)in_int('id')]);
            if (!$e || ($e['client_id'] && !can_client((int)$e['client_id'])) || (!$e['client_id'] && !is_admin())) throw new Forbidden('expense');
            q('DELETE FROM expenses WHERE id = ?', [$e['id']]); audit('expense.delete', 'expense', $e['id']); flash('اتمسح المصروف'); break;
    }
    redirect((string)($_SERVER['HTTP_REFERER'] ?? url('finance')));
}
$tab = in_str('tab', 'overview');
$ids = filter_client_ids();
$from = in_date('from') ?? (new DateTimeImmutable(today()))->modify('first day of this month')->format('Y-m-d');
$to = in_date('to') ?? today();
$cnames = client_opts();
layout_start('Client & Profit Tracker'); ?>
<div class="tabs"><?php foreach (['overview' => 'الملخص', 'invoices' => 'الفواتير والمدفوعات', 'services' => 'الخدمات والأتعاب', 'expenses' => 'المصاريف'] as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= h(url('finance', ['tab' => $k, 'client' => in_int('client')])) ?>"><?= $l ?></a><?php endforeach; ?></div>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="finance"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <div><label>العميل</label><select name="client"><?= select_opts($cnames, in_int('client'), true, 'كل العملاء') ?></select></div>
  <?php if ($tab === 'overview' || $tab === 'expenses'): ?><div><label>من</label><input type="date" name="from" value="<?= h($from) ?>"></div><div><label>إلى</label><input type="date" name="to" value="<?= h($to) ?>"></div><?php endif; ?>
</form>

<?php if ($tab === 'overview'):
  $sum = finance_summary($ids, $from, $to); $byc = finance_by_client($ids, $from, $to); $mon = finance_monthly($ids, 12);
  $adSpend = totals_by_currency(perf_rows($ids, $from, $to)); ?>
<?php if (!$sum) echo '<div class="card">' . empty_state('مفيش فواتير أو مصاريف في الفترة دي', ' ابدأ بإضافة خدمات العملاء (الأتعاب الشهرية) من تبويب الخدمات.') . '</div>'; ?>
<?php foreach ($sum as $cur => $s): ?>
  <div class="kpis" style="margin-bottom:12px">
    <?= kpi("الإيرادات (فواتير) $cur", fmt_money($s['invoiced'], $cur, 0), null, false, 'أتعابك اللي اتفوترت في الفترة — مش الصرف الإعلاني') ?>
    <?= kpi('المحصّل', fmt_money($s['collected'], $cur, 0)) ?>
    <?= kpi('المصاريف', fmt_money($s['expenses'], $cur, 0)) ?>
    <?= kpi('صافي الربح', fmt_money($s['profit'], $cur, 0), null, false, 'الإيرادات − المصاريف') ?>
    <?= kpi('الربح النقدي', fmt_money($s['cash_profit'], $cur, 0), null, false, 'المحصّل − المصاريف') ?>
    <?= kpi('هامش الربح', fmt_pct($s['margin'], 1)) ?>
    <?= kpi('مستحقات متأخرة/مفتوحة', fmt_money($s['outstanding'], $cur, 0)) ?>
  </div>
<?php endforeach; ?>
<?php if ($adSpend): ?><p class="muted small">للمعلومية فقط — الصرف الإعلاني للعملاء في نفس الفترة (مش داخل في إيراداتك ولا أرباحك): <?= implode(' · ', array_map(fn($c, $g) => number_format((float)$g['m']['spend']) . ' ' . h($c), array_keys($adSpend), $adSpend)) ?></p><?php endif; ?>
<div class="grid g2" style="margin-top:12px">
  <?php foreach ($mon as $cur => $ms): ?><div class="card"><h2>آخر 12 شهر <span class="badge ltr"><?= h($cur) ?></span></h2>
    <?= chart_canvas(['type' => 'bar', 'labels' => array_keys($ms), 'series' => [['name' => 'إيرادات', 'data' => array_column($ms, 'rev'), 'axis' => 'y'], ['name' => 'مصاريف', 'data' => array_column($ms, 'exp'), 'axis' => 'y'],
        ['name' => 'ربح', 'data' => array_map(fn($x) => $x['rev'] - $x['exp'], array_values($ms)), 'axis' => 'y', 'type' => 'line']]]) ?>
    <?php $yr = array_filter($ms, fn($k) => str_starts_with($k, local_date('Y')), ARRAY_FILTER_USE_KEY); ?>
    <p class="small">السنة دي: إيرادات <?= number_format(array_sum(array_column($yr, 'rev'))) ?> · مصاريف <?= number_format(array_sum(array_column($yr, 'exp'))) ?> · ربح <b><?= number_format(array_sum(array_column($yr, 'rev')) - array_sum(array_column($yr, 'exp'))) ?></b> <?= h($cur) ?></p></div><?php endforeach; ?>
  <div class="card"><h2>ربحية كل عميل</h2>
    <?php if (!$byc): echo '<p class="muted small">مفيش بيانات.</p>'; else: ?>
    <div class="tbl"><table class="t cards"><thead><tr><th>العميل</th><th class="n">مفوتر</th><th class="n">محصّل</th><th class="n">مصاريف العميل</th><th class="n">الربح</th><th class="n">الهامش</th></tr></thead><tbody>
    <?php foreach ($byc as $r): ?><tr><td class="main-cell"><?= h($cnames[$r['client_id']] ?? '') ?></td><td class="n" data-l="مفوتر"><?= fmt_money($r['invoiced'], $r['currency'], 0) ?></td><td class="n" data-l="محصّل"><?= fmt_money($r['collected'], $r['currency'], 0) ?></td>
      <td class="n" data-l="مصاريف"><?= fmt_money($r['expenses'], $r['currency'], 0) ?></td><td class="n" data-l="الربح"><b><?= fmt_money($r['profit'], $r['currency'], 0) ?></b></td><td class="n" data-l="الهامش"><?= fmt_pct($r['margin'], 0) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><p class="muted small">المصاريف العامة (من غير عميل) مش متوزعة على العملاء وبتظهر في الملخص فوق بس.</p><?php endif; ?></div>
</div>

<?php elseif ($tab === 'invoices'):
  $rows = $ids ? q_all('SELECT i.*, c.name cname FROM client_invoices i JOIN clients c ON c.id = i.client_id WHERE i.client_id IN (' . in_list($ids) . ") ORDER BY CASE WHEN i.status IN ('unpaid','partial') THEN 0 ELSE 1 END, i.due_date DESC LIMIT 200") : []; ?>
<div class="grid g2">
<div class="card"><h2>الفواتير<span class="sp"></span><?php if (can('finance.manage')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="generate"><button class="btn sm ghost">اعمل فواتير الشهر للاشتراكات</button></form><?php endif; ?></h2>
  <?php if (!$rows) echo empty_state('مفيش فواتير'); ?>
  <?php foreach ($rows as $i): $st = invoice_status($i); $rem = (float)$i['amount'] - (float)$i['paid_amount']; ?>
    <div style="padding:9px 0;border-bottom:1px solid var(--line)"><div class="row"><b class="ltr"><?= h($i['number']) ?></b><span><?= h($i['cname']) ?></span>
      <span class="badge <?= ['paid' => 'b-ok', 'overdue' => 'b-bad', 'partial' => 'b-warn', 'unpaid' => 'b-info', 'void' => ''][$st] ?>"><?= ['paid' => 'مدفوعة', 'overdue' => 'متأخرة', 'partial' => 'مدفوعة جزئياً', 'unpaid' => 'غير مدفوعة', 'void' => 'ملغية'][$st] ?></span>
      <span class="sp"></span><b><?= fmt_money($i['amount'], $i['currency'], 0) ?></b></div>
      <div class="small muted"><?= h($i['description']) ?> · إصدار <?= h($i['issue_date']) ?> · استحقاق <?= h($i['due_date']) ?><?= $i['paid_amount'] > 0 ? ' · اتدفع ' . number_format((float)$i['paid_amount']) . ($i['paid_at'] ? ' في ' . h($i['paid_at']) : '') : '' ?></div>
      <?php if (can('finance.manage') && in_array($st, ['unpaid', 'partial', 'overdue'], true)): ?><div class="row" style="margin-top:6px">
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="pay"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="number" step="0.01" name="amount" value="<?= h((string)round($rem, 2)) ?>" style="width:120px"><input type="date" name="date" value="<?= h(today()) ?>" style="width:150px"><button class="btn sm">تسجيل دفع</button></form>
        <?php if ($i['paid_amount'] == 0): ?><form method="post" data-confirm="تلغي الفاتورة؟"><?= csrf_field() ?><input type="hidden" name="a" value="void"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn sm ghost">إلغاء</button></form><?php endif; ?></div><?php endif; ?>
    </div><?php endforeach; ?></div>
<?php if (can('finance.manage') && $cnames): $fc = in_int('client') ?: array_key_first($cnames); ?>
<div class="card"><h2>فاتورة جديدة</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="invoice">
    <div class="fgrid"><div><label class="f">العميل</label><select name="client_id"><?= select_opts($cnames, $fc) ?></select></div>
      <div><label class="f">البيان</label><input type="text" name="description" placeholder="إدارة حملات أكتوبر"></div>
      <div><label class="f">المبلغ</label><input type="number" step="0.01" name="amount" required></div>
      <div><label class="f">العملة</label><select name="currency"><?= select_opts(array_combine(currencies(), currencies()), 'EGP') ?></select></div>
      <div><label class="f">تاريخ الإصدار</label><input type="date" name="issue_date" value="<?= h(today()) ?>"></div>
      <div><label class="f">الاستحقاق</label><input type="date" name="due_date" value="<?= h((new DateTimeImmutable(today()))->modify('+7 days')->format('Y-m-d')) ?>"></div></div>
    <button class="btn" style="margin-top:12px">إنشاء</button></form></div><?php endif; ?>
</div>

<?php elseif ($tab === 'services'):
  $rows = $ids ? q_all('SELECT s.*, c.name cname FROM client_services s JOIN clients c ON c.id = s.client_id WHERE s.client_id IN (' . in_list($ids) . ') ORDER BY s.active DESC, c.name') : [];
  $edit = in_int('edit') ? q_one('SELECT * FROM client_services WHERE id = ?', [in_int('edit')]) : null; if ($edit && !can_client((int)$edit['client_id'])) $edit = null; ?>
<div class="grid g2">
<div class="card"><h2>الخدمات المقدمة لكل عميل</h2>
  <?php if (!$rows) echo empty_state('مفيش خدمات', ' سجّل الأتعاب الشهرية أو المشروعات الثابتة لكل عميل.'); ?>
  <?php foreach ($rows as $s): ?><div class="row" style="padding:8px 0;border-bottom:1px solid var(--line)"><span class="status-dot <?= $s['active'] ? 'ok' : '' ?>"></span><b><?= h($s['name']) ?></b><span class="muted small"><?= h($s['cname']) ?></span><span class="sp"></span>
    <span><?= fmt_money($s['amount'], $s['currency'], 0) ?> <span class="muted small"><?= $s['fee_type'] === 'monthly' ? '/ شهر — يوم ' . (int)$s['billing_day'] : 'مبلغ ثابت' ?></span></span>
    <?php if (can('finance.manage')): ?><a class="btn sm ghost" href="<?= h(url('finance', ['tab' => 'services', 'edit' => $s['id']])) ?>">تعديل</a><?php endif; ?></div><?php endforeach; ?></div>
<?php if (can('finance.manage') && $cnames): ?>
<div class="card"><h2><?= $edit ? 'تعديل الخدمة' : 'خدمة جديدة' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="service"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="fgrid"><div><label class="f">العميل</label><select name="client_id"><?= select_opts($cnames, $edit['client_id'] ?? in_int('client')) ?></select></div>
      <div><label class="f">الخدمة</label><input type="text" name="name" required value="<?= h($edit['name'] ?? 'إدارة الحملات الإعلانية') ?>"></div>
      <div><label class="f">النوع</label><select name="fee_type"><?= select_opts(['monthly' => 'أتعاب شهرية', 'fixed' => 'مشروع بمبلغ ثابت'], $edit['fee_type'] ?? 'monthly') ?></select></div>
      <div><label class="f">المبلغ</label><input type="number" step="0.01" name="amount" required value="<?= h($edit['amount'] ?? '') ?>"></div>
      <div><label class="f">العملة</label><select name="currency"><?= select_opts(array_combine(currencies(), currencies()), $edit['currency'] ?? 'EGP') ?></select></div>
      <div><label class="f">يوم الفوترة</label><input type="number" min="1" max="28" name="billing_day" value="<?= (int)($edit['billing_day'] ?? 1) ?>"></div>
      <div><label class="f">من</label><input type="date" name="start_date" required value="<?= h($edit['start_date'] ?? today()) ?>"></div>
      <div><label class="f">إلى (اختياري)</label><input type="date" name="end_date" value="<?= h($edit['end_date'] ?? '') ?>"></div></div>
    <label class="check" style="margin-top:10px"><input type="checkbox" name="active" value="1" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>> نشطة</label>
    <div><button class="btn" style="margin-top:12px">حفظ</button></div></form></div><?php endif; ?>
</div>

<?php else:
  $demo = mode() === 'demo' ? 1 : 0;
  $rows = q_all('SELECT e.*, c.name cname FROM expenses e LEFT JOIN clients c ON c.id = e.client_id WHERE (e.client_id IN (' . in_list($ids) . ') OR (e.client_id IS NULL AND e.is_demo = ?' . (is_admin() ? '' : ' AND 0') . ')) AND e.date BETWEEN ? AND ? ORDER BY e.date DESC', [$demo, $from, $to]); ?>
<div class="grid g2">
<div class="card"><h2>المصاريف</h2>
  <?php if (!$rows) echo empty_state('مفيش مصاريف في الفترة دي'); ?>
  <?php foreach ($rows as $e): ?><div class="row small" style="padding:7px 0;border-bottom:1px solid var(--line)"><span class="badge"><?= h(expense_categories()[$e['category']] ?? $e['category']) ?></span><b><?= h($e['vendor'] ?: '') ?></b><span class="muted"><?= h($e['cname'] ?: 'عام') ?> · <?= h($e['date']) ?></span><span class="sp"></span><b><?= fmt_money($e['amount'], $e['currency'], 0) ?></b>
    <?php if (can('finance.manage')): ?><form method="post" data-confirm="تمسح المصروف؟"><?= csrf_field() ?><input type="hidden" name="a" value="expense_delete"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><button class="btn sm ghost">×</button></form><?php endif; ?></div><?php endforeach; ?></div>
<?php if (can('finance.manage')): ?>
<div class="card"><h2>مصروف جديد</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="expense">
    <div class="fgrid"><div><label class="f">النوع</label><select name="category"><?= select_opts(expense_categories(), 'tools') ?></select></div>
      <div><label class="f">الجهة</label><input type="text" name="vendor" placeholder="Canva، Hostinger، Veo…"></div>
      <div><label class="f">المبلغ</label><input type="number" step="0.01" name="amount" required></div>
      <div><label class="f">العملة</label><select name="currency"><?= select_opts(array_combine(currencies(), currencies()), 'EGP') ?></select></div>
      <div><label class="f">التاريخ</label><input type="date" name="date" value="<?= h(today()) ?>"></div>
      <div><label class="f">خاص بعميل؟</label><select name="client_id"><?= select_opts($cnames, '', true, 'مصروف عام') ?></select></div></div>
    <label class="f">ملاحظات</label><input type="text" name="notes">
    <button class="btn" style="margin-top:12px">تسجيل</button></form></div><?php endif; ?>
</div>
<?php endif; layout_end();
