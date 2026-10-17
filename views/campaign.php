<?php
$id = (int)in_int('id');
$cp = q_one('SELECT cp.*, a.client_id, a.currency, a.name account_name, a.id account_id FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE cp.id = ?', [$id]);
if (!$cp || !can_client((int)$cp['client_id'])) throw new Forbidden('campaign');
$f = perf_filters();
$rows = perf_rows([(int)$cp['client_id']], $f['from'], $f['to'], ['campaign' => $id]);
[$pf, $pt] = previous_period($f['from'], $f['to']);
$prevRows = perf_rows([(int)$cp['client_id']], $pf, $pt, ['campaign' => $id]);
$tot = totals_by_currency($rows); $prev = totals_by_currency($prevRows);
$m = $tot[$cp['currency']]['m'] ?? null; $pm = $prev[$cp['currency']]['m'] ?? null;
$goal = goal_of($cp['objective'], $m['result_type'] ?? null);
$series = daily_series($rows, $f['from'], $f['to']);
$leadsCrm = (int)q_val('SELECT COUNT(*) FROM leads WHERE campaign_id = ? AND received_at BETWEEN ? AND ?', [$id, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59']);
$budget = q_one('SELECT * FROM budgets WHERE campaign_id = ? AND period_start <= ? AND period_end >= ? ORDER BY id DESC LIMIT 1', [$id, $f['to'], $f['from']]);
layout_start($cp['name']); ?>
<div class="row" style="margin-bottom:10px">
  <span class="badge b-acc"><?= h(goal_labels()[$goal]) ?></span><span class="badge"><?= h($cp['objective'] ?: 'objective غير معروف') ?></span>
  <span class="badge"><?= h(strtolower((string)($cp['effective_status'] ?: $cp['status'] ?: 'unknown'))) ?></span>
  <span class="muted small"><?= h($cp['account_name']) ?> · <a href="<?= h(url('client', ['id' => $cp['client_id']])) ?>"><?= h(q_val('SELECT name FROM clients WHERE id = ?', [$cp['client_id']])) ?></a></span>
  <span class="sp"></span>
  <?php if (can('audits.manage')): ?><a class="btn sm ghost" href="<?= h(url('auditor', ['campaign' => $id, 'client' => $cp['client_id']])) ?>">مراجعة الإعدادات</a><?php endif; ?>
  <?php if (can('reports.manage')): ?><a class="btn sm ghost" href="<?= h(url('reports', ['client' => $cp['client_id'], 'campaign' => $id, 'type' => 'campaign'])) ?>">تقرير الحملة</a><?php endif; ?>
</div>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="campaign"><input type="hidden" name="id" value="<?= $id ?>">
  <div><label>من</label><input type="date" name="from" value="<?= h($f['from']) ?>"></div><div><label>إلى</label><input type="date" name="to" value="<?= h($f['to']) ?>"></div></form>
<?= date_presets('campaign', ['id' => $id]) ?>
<div style="height:12px"></div>
<?php if (!$m): echo '<div class="card">' . empty_state('مفيش بيانات للحملة في الفترة دي') . '</div>'; else: ?>
<?= kpi_grid($m, $pm, $cp['currency'], $goal === 'sales' || $m['purchases'] !== null, $goal === 'leads' || $m['leads'] !== null, $m['thruplays'] !== null) ?>
<div class="grid g3" style="margin-top:16px">
  <div class="card"><h2>الميزانية</h2>
    <?php if ($budget): $spent = (float)($m['spend'] ?? 0); $pct = $budget['amount'] > 0 ? $spent / $budget['amount'] : null; ?>
      <div class="bar <?= $pct > 1 ? 'bad' : ($pct > 0.9 ? 'warn' : '') ?>"><i style="width:<?= min(100, round(($pct ?? 0) * 100)) ?>%"></i></div>
      <p class="small"><?= fmt_money($spent, $cp['currency'], 0) ?> من <?= fmt_money($budget['amount'], $budget['currency'], 0) ?> (<?= fmt_pct($pct, 0) ?>)</p>
    <?php else: ?><p class="muted small">مفيش ميزانية مخططة للحملة دي. <?php if (can('budgets.manage')): ?><a href="<?= h(url('budgets', ['client' => $cp['client_id']])) ?>">ضيف ميزانية</a><?php endif; ?></p><?php endif; ?>
    <?php if ($cp['daily_budget']): ?><p class="small muted">الميزانية اليومية في المنصة: <?= fmt_money($cp['daily_budget'], $cp['currency'], 0) ?></p><?php endif; ?></div>
  <div class="card"><h2>ليدز في الـ CRM</h2><div class="kpi" style="border:0;padding:0"><div class="v"><?= number_format($leadsCrm) ?></div>
    <div class="muted small">ليدز مربوطة بالحملة دي في الفترة. المنصة سجلت <?= fmt_num($m['leads']) ?>.</div></div>
    <?php if (can('leadquality.view')): ?><a class="small" href="<?= h(url('leadquality', ['client' => $cp['client_id'], 'from' => $f['from'], 'to' => $f['to']])) ?>">جودة الليدز ←</a><?php endif; ?></div>
  <div class="card"><h2>التنبيهات المفتوحة</h2>
    <?php $al = q_all("SELECT * FROM alerts WHERE campaign_id = ? AND status = 'open' ORDER BY id DESC LIMIT 5", [$id]);
    if (!$al) echo '<p class="muted small">مفيش تنبيهات مفتوحة.</p>'; foreach ($al as $a) echo '<div class="small">' . sev_badge($a['severity']) . ' ' . h($a['title']) . '</div>'; ?></div>
</div>
<?php $cur = $cp['currency']; if (!empty($series[$cur])): $rk = $goal === 'sales' ? 'purchases' : ($goal === 'leads' ? 'leads' : 'results'); ?>
<div class="grid g2" style="margin-top:16px">
  <div class="card"><h2>الصرف والنتائج</h2><?= chart_canvas(daily_chart_spec($series[$cur], $cur, $rk)) ?></div>
  <div class="card"><h2>التكلفة</h2><?= chart_canvas(cost_chart_spec($series[$cur], $cur, $goal === 'sales' ? 'cpa' : ($goal === 'leads' ? 'cpl' : 'cost_per_result'))) ?></div>
</div><?php endif; ?>
<div class="card" style="margin-top:16px"><h2>Ad Sets</h2><?= perf_table(entity_table($rows, 'adset'), 'adset', 'adsetT') ?></div>
<div class="card" style="margin-top:16px"><h2>الإعلانات</h2><?= perf_table(entity_table($rows, 'ad'), 'ad', 'adT') ?></div>
<?php endif; layout_end();
