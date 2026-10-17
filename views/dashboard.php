<?php
$f = perf_filters();
$opt = ['account' => $f['account'], 'campaign' => $f['campaign'], 'goal' => $f['goal']];
$rows = perf_rows($f['clients'], $f['from'], $f['to'], $opt);
[$pf, $pt] = previous_period($f['from'], $f['to']);
$prevRows = $f['compare'] ? perf_rows($f['clients'], $pf, $pt, $opt) : [];
$tot = totals_by_currency($rows);
$prevTot = totals_by_currency($prevRows);
$items = entity_table($rows, $f['level']);
$accounts = accounts_for($f['clients']);
$campaigns = campaigns_for($f['clients']);
if ($f['account']) $campaigns = array_filter($campaigns, fn($c) => (int)$c['ad_account_id'] === $f['account']);
$series = daily_series($rows, $f['from'], $f['to']);
$hasLeads = any_metric($tot + $prevTot, ['leads']); $hasSales = any_metric($tot + $prevTot, ['purchases', 'purchase_value']);
$hasVideo = any_metric($tot, ['thruplays', 'video_3s']);
$periodRows = count(array_filter($rows, fn($r) => $r['date_start'] !== $r['date_end']));

layout_start('Media Buying Dashboard'); ?>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="dashboard">
  <div><label>العميل</label><select name="client"><?= select_opts(client_opts(), $f['client'], true, 'كل العملاء') ?></select></div>
  <div><label>الحساب</label><select name="account"><?= select_opts(array_column($accounts, 'name', 'id'), $f['account'], true) ?></select></div>
  <div><label>الحملة</label><select name="campaign"><?= select_opts(array_column($campaigns, 'name', 'id'), $f['campaign'], true) ?></select></div>
  <div><label>الهدف الإعلاني</label><select name="goal"><?= select_opts(goal_labels(), $f['goal'], true) ?></select></div>
  <div><label>من</label><input type="date" name="from" value="<?= h($f['from']) ?>"></div>
  <div><label>إلى</label><input type="date" name="to" value="<?= h($f['to']) ?>"></div>
  <div><label>المستوى</label><select name="level"><?= select_opts(['campaign' => 'Campaign', 'adset' => 'Ad Set', 'ad' => 'Ad'], $f['level']) ?></select></div>
  <div><label>مقارنة</label><select name="compare"><?= select_opts(['1' => 'بالفترة السابقة', '0' => 'بدون'], $f['compare'] ? '1' : '0') ?></select></div>
  <noscript><button class="btn">تطبيق</button></noscript>
</form>
<?= date_presets('dashboard') ?>
<p class="muted small" style="margin:8px 0 14px">الفترة: <span class="ltr"><?= h($f['from']) ?> → <?= h($f['to']) ?></span><?php if ($f['compare']): ?> · المقارنة بـ <span class="ltr"><?= h($pf) ?> → <?= h($pt) ?></span><?php endif; ?></p>

<?php if (!$rows): ?>
  <div class="card"><?= has_perf_data($f['clients'])
      ? empty_state('مفيش بيانات في الفترة أو الفلاتر دي', ' غيّر الفترة أو شيل بعض الفلاتر.')
      : empty_state('لسه مفيش بيانات أداء', ' استورد تقرير من Ads Manager (CSV أو Excel) أو اربط حساب Meta.', can('analytics.import') ? '<a class="btn" href="' . h(url('import')) . '">' . icon('upload') . ' استيراد بيانات</a>' : '') ?></div>
<?php else: ?>

<?php foreach ($tot as $cur => $g): $prev = $prevTot[$cur]['m'] ?? null; ?>
  <div class="row" style="margin:6px 0 8px"><h2 style="font-size:15px;margin:0">الإجمالي <span class="badge ltr"><?= h($cur) ?></span></h2>
    <?php if (count($tot) > 1): ?><span class="muted small">الأرقام بعملات مختلفة بتتعرض منفصلة ومش بتتجمع.</span><?php endif; ?></div>
  <?= kpi_grid($g['m'], $f['compare'] ? $prev : null, $cur, $hasSales, $hasLeads, $hasVideo) ?>
  <div style="height:14px"></div>
<?php endforeach; ?>
<?php if (!any_metric($tot, ['reach'])): ?><p class="muted small">Reach و Frequency بيظهروا N/A لأن الفترة فيها أكتر من يوم أو أكتر من حملة، والـ Reach مش بيتجمع (نفس الشخص بيتعد مرة واحدة بس). اختار يوم واحد وحملة واحدة، أو استورد تقرير الفترة كاملة من غير تقسيم أيام.</p><?php endif; ?>
<?php if ($periodRows): ?><p class="muted small"><?= $periodRows ?> صف من البيانات بيغطي فترة كاملة مش يوم واحد، فمش ظاهر في الرسم اليومي لكنه داخل في الإجماليات.</p><?php endif; ?>

<div class="grid g2" style="margin-top:6px">
<?php $cur = array_key_first($series); if ($cur): $rk = $hasLeads ? 'leads' : ($hasSales ? 'purchases' : 'results'); $ck = $hasLeads ? 'cpl' : ($hasSales ? 'cpa' : 'cost_per_result'); ?>
  <div class="card"><h2>الصرف والنتائج يومياً <span class="badge ltr"><?= h($cur) ?></span></h2><?= chart_canvas(daily_chart_spec($series[$cur], $cur, $rk)) ?></div>
  <div class="card"><h2>التكلفة يومياً</h2><?= chart_canvas(cost_chart_spec($series[$cur], $cur, $ck)) ?></div>
<?php endif; ?>
</div>

<?php
// best / worst campaigns judged by each goal's own KPI, with a minimum spend so tiny samples don't win
$camps = entity_table($rows, 'campaign');
$byGoal = [];
foreach ($camps as $c) $byGoal[$c['goal'] . '|' . $c['currency']][] = $c;
if ($f['goal']) $byGoal = array_filter($byGoal, fn($k) => str_starts_with($k, $f['goal'] . '|'), ARRAY_FILTER_USE_KEY);
if ($byGoal): ?>
<div class="card" style="margin-top:16px"><h2>أفضل وأسوأ الحملات حسب الهدف</h2>
  <div class="grid g2">
  <?php foreach ($byGoal as $k => $list): [$goal, $cur] = explode('|', $k);
      $spendTot = array_sum(array_map(fn($c) => (float)$c['m']['spend'], $list));
      $minSpend = $spendTot * 0.05;
      $rk = rank_by_goal($list, $goal, $minSpend, max(1, min(3, intdiv(count($list), 2)))); ?>
    <div class="card flat"><b><?= h(goal_labels()[$goal]) ?></b> <span class="badge ltr"><?= h($cur) ?></span> <span class="muted small">مقياس الحكم: <?= h(metric_label($rk['kpi'])) ?></span>
      <?php if (!$rk['best']): ?><p class="muted small">مفيش حملات عندها بيانات كفاية للمقارنة.</p><?php else: ?>
      <table class="t" style="margin-top:8px"><tbody>
        <?php foreach ($rk['best'] as $c): ?><tr><td><span class="badge b-ok">أفضل</span> <a href="<?= h(url('campaign', ['id' => $c['id']])) ?>"><?= h($c['name']) ?></a></td><td class="n"><?= fmt_metric($rk['kpi'], $c['m'][$rk['kpi']], $cur) ?></td><td class="n muted small"><?= fmt_money($c['m']['spend'], $cur, 0) ?></td></tr><?php endforeach; ?>
        <?php foreach ($rk['worst'] as $c): ?><tr><td><span class="badge b-bad">أضعف</span> <a href="<?= h(url('campaign', ['id' => $c['id']])) ?>"><?= h($c['name']) ?></a></td><td class="n"><?= fmt_metric($rk['kpi'], $c['m'][$rk['kpi']], $cur) ?></td><td class="n muted small"><?= fmt_money($c['m']['spend'], $cur, 0) ?></td></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
      <?php if ($rk['excluded']): ?><div class="muted small" style="margin-top:6px"><?= $rk['excluded'] ?> حملة اتستبعدت عشان صرفها أقل من 5% من إجمالي الهدف ده أو ملهاش نتائج.</div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div></div>
<?php endif; ?>

<div class="card" style="margin-top:16px">
  <h2><?= ['campaign' => 'الحملات', 'adset' => 'Ad Sets', 'ad' => 'الإعلانات'][$f['level']] ?> <span class="sp"></span>
    <a class="btn sm ghost" href="<?= h(url('export', array_merge($_GET, ['r' => null, 'kind' => 'perf', 'format' => 'csv']))) ?>"><?= icon('download') ?> CSV</a>
    <a class="btn sm ghost" href="<?= h(url('export', array_merge($_GET, ['r' => null, 'kind' => 'perf', 'format' => 'xlsx']))) ?>"><?= icon('download') ?> Excel</a></h2>
  <?= perf_table($items, $f['level'], 'perfT') ?>
</div>
<?php endif; ?>

<details class="box" style="margin-top:16px"><summary>تعريف كل مؤشر وطريقة حسابه</summary>
  <div class="tbl" style="margin-top:10px"><table class="t"><tbody>
  <?php foreach (metric_defs() as $k => [$label, , , $def]): if (!$def) continue; ?><tr><td style="white-space:nowrap"><b><?= h($label) ?></b></td><td class="small"><?= h($def) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="muted small">أي مؤشر بياناته مش متاحة بيظهر <b>N/A</b> ومش بيتحسب صفر. القسمة على صفر برضه بتطلع N/A.</p>
</details>
<?php layout_end();
