<?php
$f = perf_filters();
$dim = array_key_exists(in_str('dim'), lq_dimensions()) ? in_str('dim') : 'campaign';
$res = lead_quality($f['clients'], $dim, $f['from'], $f['to']);
$t = $res['total'];
layout_start('Lead Quality Analyzer'); ?>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="leadquality">
  <div><label>العميل</label><select name="client"><?= select_opts(client_opts(), $f['client'], true, 'كل العملاء') ?></select></div>
  <div><label>قارن حسب</label><select name="dim"><?= select_opts(lq_dimensions(), $dim) ?></select></div>
  <div><label>ليدز وصلت من</label><input type="date" name="from" value="<?= h($f['from']) ?>"></div><div><label>إلى</label><input type="date" name="to" value="<?= h($f['to']) ?>"></div>
</form>
<?php if (!$t || !$t['total'] && !$res['rows']): echo '<div class="card">' . empty_state('مفيش ليدز في الفترة دي', ' الليدز بتيجي من Meta Lead Ads أو الاستيراد أو الإضافة اليدوية في الـ CRM.') . '</div>'; else: ?>
<div class="kpis" style="margin-bottom:12px">
  <?= kpi('Total Leads (CRM)', number_format($t['total']), null, false, 'ليدز فريدة (من غير المكرر)') ?>
  <?= kpi('Contact Rate', fmt_pct($t['contact_rate'], 1)) ?>
  <?= kpi('Qualified Lead Rate', fmt_pct($t['qualified_rate'], 1)) ?>
  <?= kpi('Appointment Rate', fmt_pct($t['appointment_rate'], 1)) ?>
  <?= kpi('Sales Conversion Rate', fmt_pct($t['sales_rate'], 1)) ?>
  <?php foreach ($t['spend_by_currency'] as $cur => $sp): ?>
    <?= kpi("Spend ($cur)", fmt_money($sp, $cur, 0)) ?>
  <?php endforeach; if ($t['currency']): ?>
    <?= kpi('Cost per Qualified Lead', fmt_money($t['cost_per_qualified'], $t['currency'])) ?>
    <?= kpi('Cost per Sale', fmt_money($t['cost_per_sale'], $t['currency'])) ?>
    <?= kpi('Revenue', isset($t['revenue'][$t['currency']]) ? fmt_money($t['revenue'][$t['currency']], $t['currency'], 0) : fmt_num(null)) ?>
    <?= kpi('ROAS (من الـ CRM)', $t['roas'] === null ? fmt_num(null) : number_format($t['roas'], 2) . 'x', null, false, 'قيمة المبيعات المسجلة في الـ CRM ÷ الصرف') ?>
  <?php endif; ?>
  <?= kpi('زمن أول تواصل (وسيط)', $t['ttc_median'] === null ? fmt_num(null) : number_format($t['ttc_median'], 1) . ' ساعة', null, true, 'من وصول الليد لأول مكالمة/رسالة') ?>
  <?= kpi('لسه محدش كلمهم', number_format($t['never_contacted'])) ?>
</div>
<?php if (count($t['spend_by_currency']) > 1): ?><p class="muted small">التكلفة لكل ليد/بيع والـ ROAS بيتحسبوا لكل صف على حدة لأن الصرف بعملات مختلفة.</p><?php endif; ?>
<?php if ($t['won_without_value']): ?><div class="flash warn"><?= (int)$t['won_without_value'] ?> بيع (Won) من غير قيمة — سجّل قيمة البيع عشان الـ Revenue والـ ROAS يبقوا دقيقين.</div><?php endif; ?>
<?php if ($res['duplicates']): ?><p class="muted small"><?= (int)$res['duplicates'] ?> ليد مكرر مش محسوب في الأرقام.</p><?php endif; ?>

<?php $low = array_filter($res['rows'], fn($r) => $r['low_quality']); if ($low): ?>
<div class="card" style="margin-bottom:16px;border-color:var(--warn)"><h2>⚠️ مصادر بتجيب ليدز كتير جودتها قليلة</h2>
  <?php foreach ($low as $r): ?><div class="small">• <b><?= h($r['label']) ?></b>: <?= $r['total'] ?> ليد، المؤهل <?= fmt_pct($r['qualified_rate'], 0) ?> بس مقابل <?= fmt_pct($t['qualified_rate'], 0) ?> في المتوسط. راجع الاستهداف وأسئلة الفورم قبل ما تزوّد صرفها — عدد الليدز لوحده مش مقياس كفاية.</div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card"><h2>حسب <?= h(lq_dimensions()[$dim]) ?></h2>
  <div class="tbl"><table class="t cards"><thead><tr><th><?= h(lq_dimensions()[$dim]) ?></th><th class="n">ليدز CRM</th><th class="n" title="الليدز اللي سجلتها المنصة">ليدز المنصة</th><th class="n">Contact</th><th class="n">Qualified</th><th class="n">Appointments</th><th class="n">Won</th>
    <th class="n">Spend</th><th class="n">CPL (CRM)</th><th class="n">Cost / Qualified</th><th class="n">Cost / Sale</th><th class="n">Revenue</th><th class="n">ROAS</th><th class="n">أول تواصل</th></tr></thead><tbody>
  <?php foreach ($res['rows'] as $r): $cur = $r['currency']; ?>
    <tr><td class="main-cell"><?= h($r['label']) ?> <?= $r['low_quality'] ? '<span class="badge b-warn">جودة قليلة</span>' : '' ?></td>
      <td class="n" data-l="ليدز CRM"><?= number_format($r['total']) ?></td><td class="n" data-l="ليدز المنصة"><?= fmt_num($r['platform_leads']) ?></td>
      <td class="n" data-l="Contact"><?= fmt_pct($r['contact_rate'], 0) ?></td><td class="n" data-l="Qualified"><?= $r['qualified'] ?> <span class="muted small">(<?= fmt_pct($r['qualified_rate'], 0) ?>)</span></td>
      <td class="n" data-l="Appointments"><?= $r['appointments'] ?></td><td class="n" data-l="Won"><?= $r['won'] ?> <span class="muted small">(<?= fmt_pct($r['sales_rate'], 1) ?>)</span></td>
      <td class="n" data-l="Spend"><?= $cur ? fmt_money($r['spend'], $cur, 0) : ($r['spend_by_currency'] ? '<span class="small">' . h(implode(' + ', array_map(fn($c, $v) => number_format($v) . " $c", array_keys($r['spend_by_currency']), $r['spend_by_currency']))) . '</span>' : fmt_num(null)) ?></td>
      <td class="n" data-l="CPL"><?= $cur ? fmt_money($r['cpl_crm'], $cur) : fmt_num(null) ?></td><td class="n" data-l="Cost/Qualified"><?= $cur ? fmt_money($r['cost_per_qualified'], $cur) : fmt_num(null) ?></td>
      <td class="n" data-l="Cost/Sale"><?= $cur ? fmt_money($r['cost_per_sale'], $cur) : fmt_num(null) ?></td>
      <td class="n" data-l="Revenue"><?= $r['revenue'] ? h(implode(' + ', array_map(fn($c, $v) => number_format($v) . " $c", array_keys($r['revenue']), $r['revenue']))) : '—' ?></td>
      <td class="n" data-l="ROAS"><?= $r['roas'] === null ? fmt_num(null) : number_format($r['roas'], 2) . 'x' ?></td>
      <td class="n" data-l="أول تواصل"><?= $r['ttc_median'] === null ? '—' : number_format($r['ttc_median'], 1) . ' س' ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="muted small" style="margin-top:10px"><b>قاعدة الإسناد:</b> الليد والبيع بيتحسبوا للحملة/الإعلان المسجّل على الليد نفسه (من فورم Meta أو ملف الاستيراد أو اللي اخترته يدوي). الليدز من غير حملة بتظهر "غير منسوب". الـ Revenue بيدخل في الـ ROAS بس لو عملة البيع هي نفس عملة الحساب الإعلاني. الصرف هنا للفترة المختارة، والليدز اللي وصلت في نفس الفترة.</p>
</div>

<?php if ($t['lost_reasons']): ?>
<div class="grid g2" style="margin-top:16px">
  <div class="card"><h2>أسباب الخسارة</h2>
    <?php $mx = max($t['lost_reasons']); foreach ($t['lost_reasons'] as $why => $n): ?><div class="row small" style="margin:5px 0"><span style="width:140px"><?= h($why) ?></span><div class="bar bad" style="flex:1"><i style="width:<?= round($n / $mx * 100) ?>%"></i></div><b style="width:36px;text-align:end"><?= $n ?></b></div><?php endforeach; ?></div>
  <div class="card"><h2>القمع</h2>
    <?php $fn = [['ليدز', $t['total']], ['اتواصلنا', $t['contacted']], ['مؤهلين', $t['qualified']], ['مواعيد', $t['appointments']], ['بيع', $t['won']]]; $mx = max(1, $t['total']);
    foreach ($fn as [$l, $n]): ?><div class="row small" style="margin:6px 0"><span style="width:80px"><?= $l ?></span><div class="bar" style="flex:1;height:14px"><i style="width:<?= round($n / $mx * 100) ?>%"></i></div><b style="width:60px;text-align:end"><?= number_format($n) ?></b></div><?php endforeach; ?></div>
</div>
<?php endif; endif; layout_end();
