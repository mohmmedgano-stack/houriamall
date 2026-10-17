<?php
$f = perf_filters();
$dims = audience_dimensions($f['clients']);
$dim = array_key_exists(in_str('dim'), $dims) ? in_str('dim') : (isset($dims['bd:age']) ? 'bd:age' : 'adset');
$opt = ['account' => $f['account'], 'campaign' => $f['campaign'], 'goal' => $f['goal']];
$res = audience_analyze($f['clients'], $dim, $f['from'], $f['to'], $opt);
if (is_post() && in_str('a') === 'save') {
    require_perm('audience.view');
    $cid = $f['client'] ?: ($f['clients'][0] ?? null);
    if (!$cid) throw new InvalidArgumentException('اختار عميل');
    $id = db_insert('audience_analyses', ['client_id' => $cid, 'name' => in_str('name') ?: ($dims[$dim] . ' ' . $f['from'] . '→' . $f['to']),
        'params_json' => json_encode(['dim' => $dim, 'from' => $f['from'], 'to' => $f['to']] + $opt), 'result_json' => json_encode($res, JSON_UNESCAPED_UNICODE), 'created_by' => uid(), 'created_at' => now()]);
    flash('اتحفظ التحليل ✅'); redirect(url('audience', array_merge($_GET, ['r' => null])));
}
$saved = $f['clients'] ? q_all('SELECT aa.id, aa.name, aa.params_json, aa.created_at, c.name cname FROM audience_analyses aa JOIN clients c ON c.id = aa.client_id WHERE aa.client_id IN (' . in_list($f['clients']) . ') ORDER BY aa.id DESC LIMIT 10') : [];
$campaigns = campaigns_for($f['clients']);
layout_start('Audience Tester'); ?>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="audience">
  <div><label>العميل</label><select name="client"><?= select_opts(client_opts(), $f['client'], true, 'كل العملاء') ?></select></div>
  <div><label>الحملة</label><select name="campaign"><?= select_opts(array_column($campaigns, 'name', 'id'), $f['campaign'], true) ?></select></div>
  <div><label>الهدف</label><select name="goal"><?= select_opts(goal_labels(), $f['goal'], true) ?></select></div>
  <div style="min-width:220px"><label>قارن حسب</label><select name="dim"><?= select_opts($dims, $dim) ?></select></div>
  <div><label>من</label><input type="date" name="from" value="<?= h($f['from']) ?>"></div><div><label>إلى</label><input type="date" name="to" value="<?= h($f['to']) ?>"></div>
</form>
<?php if (count($dims) <= 2): ?><p class="muted small">عشان تقارن بالعمر/النوع/المنطقة/الـ Placement، استورد تقرير فيه Breakdown من Ads Manager أو اربط Meta API. مش بنخمّن بيانات ديموغرافية مش موجودة.</p><?php endif; ?>
<?php if (!$res) echo '<div class="card">' . empty_state('مفيش بيانات للتقسيم ده في الفترة دي') . '</div>';
foreach ($res as $cur => $r): $max = max(array_map(fn($s) => (float)($s['m'][$r['cost']] ?? 0), $r['segments']) ?: [1]); ?>
<div class="grid g2" style="margin-bottom:16px">
  <div class="card"><h2>الملاحظات والاقتراحات <span class="badge ltr"><?= h($cur) ?></span></h2>
    <?php foreach ($r['insights'] as [$t, $msg]): ?><div class="flash <?= ['good' => 'ok', 'bad' => 'err', 'idea' => 'warn'][$t] ?? '' ?>" style="font-weight:500<?= $t === 'info' ? ';background:var(--panel2);color:var(--ink2)' : '' ?>"><?= h($msg) ?></div><?php endforeach; ?>
  </div>
  <div class="card"><h2><?= h(metric_label($r['cost'])) ?> لكل شريحة</h2>
    <?= chart_canvas(['type' => 'bar', 'labels' => array_map(fn($s) => mb_substr($s['label'], 0, 22), $r['segments']), 'series' => [
        ['name' => metric_label($r['cost']) . " ($cur)", 'data' => array_map(fn($s) => $s['m'][$r['cost']] === null ? null : round((float)$s['m'][$r['cost']], 2), $r['segments']), 'axis' => 'y'],
        ['name' => 'Spend share %', 'data' => array_map(fn($s) => $s['share'] === null ? null : round($s['share'] * 100, 1), $r['segments']), 'axis' => 'y1', 'type' => 'line']]], 260) ?></div>
</div>
<div class="card" style="margin-bottom:16px">
  <h2><?= h($dims[$dim]) ?> <span class="badge ltr"><?= h($cur) ?></span></h2>
  <div class="tbl"><table class="t cards"><thead><tr><th>الشريحة</th><th class="n">Spend</th><th class="n">% من الصرف</th><th class="n">Impressions</th><th class="n">Link CTR</th><th class="n"><?= h(metric_label($r['conv'])) ?></th><th class="n"><?= h(metric_label($r['cost'])) ?></th><th class="n">CVR</th><th>الثقة</th></tr></thead><tbody>
  <?php foreach ($r['segments'] as $s): $m = $s['m']; ?>
    <tr><td class="main-cell"><?= h($s['label']) ?> <?= $s['small'] ? '<span class="badge" title="صرف أقل من 5% أو أقل من 5 نتايج">عينة صغيرة</span>' : '' ?></td>
      <td class="n" data-l="Spend"><?= fmt_money($m['spend'], $cur, 0) ?></td><td class="n" data-l="% صرف"><?= fmt_pct($s['share'], 1) ?></td>
      <td class="n" data-l="Impressions"><?= fmt_num($m['impressions']) ?></td><td class="n" data-l="Link CTR"><?= fmt_pct($m['link_ctr']) ?></td>
      <td class="n" data-l="<?= h(metric_label($r['conv'])) ?>"><?= fmt_num($m[$r['conv']]) ?></td><td class="n" data-l="<?= h(metric_label($r['cost'])) ?>"><b><?= fmt_money($m[$r['cost']], $cur) ?></b></td>
      <td class="n" data-l="CVR"><?= fmt_pct(safe_div($m[$r['conv']], $m['link_clicks'])) ?></td>
      <td data-l="الثقة" class="small"><?= $s['z'] === null ? '—' : (abs($s['z']) >= 1.96 ? '<span class="badge b-ok">فرق واضح</span>' : '<span class="badge">غير حاسم</span>') ?></td></tr>
  <?php endforeach; ?>
  </tbody><tfoot><tr><td>الإجمالي</td><td class="n"><?= fmt_money($r['total']['spend'], $cur, 0) ?></td><td class="n">100%</td><td class="n"><?= fmt_num($r['total']['impressions']) ?></td><td class="n"><?= fmt_pct($r['total']['link_ctr']) ?></td><td class="n"><?= fmt_num($r['total'][$r['conv']]) ?></td><td class="n"><?= fmt_money($r['total'][$r['cost']], $cur) ?></td><td class="n"><?= fmt_pct(safe_div($r['total'][$r['conv']], $r['total']['link_clicks'])) ?></td><td></td></tr></tfoot></table></div>
  <p class="muted small">"الثقة" = اختبار فرق النسب (Two-proportion z-test) بين معدل التحويل في الشريحة وباقي الشرائح. "فرق واضح" يعني |z| ≥ 1.96 (تقريباً 95%).</p>
</div>
<?php endforeach; ?>
<div class="grid g2">
<?php if ($res): ?><div class="card"><h2>حفظ التحليل</h2><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="text" name="name" placeholder="اسم التحليل" style="flex:1"><button class="btn">حفظ</button></form></div><?php endif; ?>
<?php if ($saved): ?><div class="card"><h2>تحليلات محفوظة</h2><?php foreach ($saved as $s): $p = json_decode($s['params_json'], true); ?>
  <div class="row small" style="padding:4px 0"><a href="<?= h(url('audience', ['dim' => $p['dim'], 'from' => $p['from'], 'to' => $p['to'], 'campaign' => $p['campaign'] ?? null])) ?>"><?= h($s['name']) ?></a><span class="muted"><?= h($s['cname']) ?> · <?= h(fmt_dt($s['created_at'], 'Y-m-d')) ?></span></div><?php endforeach; ?></div><?php endif; ?>
</div>
<?php layout_end();
