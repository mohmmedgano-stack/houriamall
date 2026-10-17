<?php
if (is_post()) {
    require_perm('creatives.manage');
    if (in_str('a') === 'update') { creative_update((int)in_int('id'), $_POST + ['in_library' => $_POST['in_library'] ?? '']); flash('اتحفظ ✅'); }
    if (in_str('a') === 'idea') {
        $cid = (int)in_int('client_id');
        if (!can_client($cid)) throw new Forbidden('client');
        db_insert('creative_ideas', ['client_id' => $cid, 'title' => mb_substr(in_str('title'), 0, 200), 'kind' => 'creative', 'inputs_json' => json_encode(['from' => 'creative_analyzer', 'basis' => in_str('basis')], JSON_UNESCAPED_UNICODE),
            'output_json' => json_encode(['idea' => in_str('idea')], JSON_UNESCAPED_UNICODE), 'status' => 'idea', 'source' => 'pattern', 'created_by' => uid(), 'created_at' => now()]);
        flash('اتحفظت الفكرة في Ad Copy & Hooks ✅');
    }
    redirect((string)($_SERVER['HTTP_REFERER'] ?? url('creatives')));
}
$f = perf_filters();
$lib = in_str('lib') === '1';
$items = creative_flag_samples(creative_performance($f['clients'], $f['from'], $f['to'], ['campaign' => $f['campaign'], 'goal' => $f['goal']]));
if ($lib) $items = array_values(array_filter($items, fn($i) => (int)$i['asset']['in_library'] === 1));
if ($h = in_str('hook')) $items = array_values(array_filter($items, fn($i) => $i['asset']['hook_type'] === $h));
$sort = in_str('sort', 'kpi');
usort($items, function ($a, $b) use ($sort) {
    if ($sort === 'spend') return ($b['m']['spend'] ?? 0) <=> ($a['m']['spend'] ?? 0);
    if ($a['small'] !== $b['small']) return $a['small'] <=> $b['small'];   // reliable samples first
    if ($a['goal'] !== $b['goal']) return strcmp($a['goal'], $b['goal']);
    $x = $a['m'][$a['kpi']]; $y = $b['m'][$b['kpi']];
    if ($x === null || $y === null) return ($x === null) <=> ($y === null);
    return metric_lower_better($a['kpi']) ? $x <=> $y : $y <=> $x;
});
$hooks = array_values(array_unique(array_filter(array_map(fn($i) => $i['asset']['hook_type'], $items))));
$patternsHook = creative_patterns($items, 'hook_type'); $patternsAngle = creative_patterns($items, 'angle');
$editId = in_int('edit');
$campaigns = campaigns_for($f['clients']);
layout_start('Creative Analyzer'); ?>
<form class="filters" data-autosubmit><input type="hidden" name="r" value="creatives">
  <div><label>العميل</label><select name="client"><?= select_opts(client_opts(), $f['client'], true, 'كل العملاء') ?></select></div>
  <div><label>الحملة</label><select name="campaign"><?= select_opts(array_column($campaigns, 'name', 'id'), $f['campaign'], true) ?></select></div>
  <div><label>الهدف</label><select name="goal"><?= select_opts(goal_labels(), $f['goal'], true) ?></select></div>
  <div><label>نوع الـ Hook</label><select name="hook"><?= select_opts(array_combine($hooks, $hooks) ?: [], in_str('hook'), true) ?></select></div>
  <div><label>من</label><input type="date" name="from" value="<?= h($f['from']) ?>"></div><div><label>إلى</label><input type="date" name="to" value="<?= h($f['to']) ?>"></div>
  <div><label>العرض</label><select name="lib"><?= select_opts(['' => 'كل الكريتيفات', '1' => 'مكتبة الناجحين'], $lib ? '1' : '') ?></select></div>
  <div><label>الترتيب</label><select name="sort"><?= select_opts(['kpi' => 'حسب مقياس الهدف', 'spend' => 'حسب الصرف'], $sort) ?></select></div>
</form>
<p class="muted small">المقارنة بتتم بمقياس هدف كل حملة (CPL للليدز، ROAS للمبيعات، Link CPC للترافيك…). الكريتيفات اللي عينتها صغيرة بتتعلّم ومش بتتحط في الأول.</p>

<?php if (!$items): ?><div class="card"><?= empty_state('مفيش بيانات كريتيف في الفترة دي', ' الكريتيفات بتظهر من بيانات مستوى الإعلان (Ad level) — استورد تقرير Ads فيه Body/Title أو اربط Meta.') ?></div>
<?php else: ?>
<div class="grid g2" style="margin-bottom:16px">
<?php foreach ([['hook_type', 'أنواع الـ Hook الأفضل', $patternsHook], ['angle', 'الزوايا التسويقية الأفضل', $patternsAngle]] as [$fld, $title, $pats]): ?>
  <div class="card"><h2><?= h($title) ?></h2>
  <?php if (!$pats) echo '<p class="muted small">صنّف الكريتيفات (Hook / زاوية) عشان تظهر الأنماط هنا.</p>';
  foreach ($pats as $k => $list): [$goal, $cur] = explode('|', $k); ?>
    <div class="small muted" style="margin-top:6px"><?= h(goal_labels()[$goal]) ?> · <?= h(metric_label($list[0]['kpi'])) ?> <span class="ltr">(<?= h($cur) ?>)</span></div>
    <?php foreach (array_slice($list, 0, 3) as $i => $p): ?><div class="row small" style="padding:3px 0"><span class="badge <?= $i === 0 ? 'b-ok' : '' ?>"><?= h($p['value']) ?></span><span class="muted"><?= $p['n'] ?> كريتيف</span><span class="sp"></span><b><?= fmt_metric($p['kpi'], $p['m'][$p['kpi']], $cur) ?></b></div><?php endforeach; ?>
  <?php endforeach; ?></div>
<?php endforeach; ?>
</div>

<?php // idea suggestions from the best patterns — clearly labelled as proposals
$best = [];
$seenCombo = [];
foreach ($patternsHook as $k => $list) {
    [$goal, $cur] = explode('|', $k); $combo = $list[0]['value'] . '|' . ($patternsAngle[$k][0]['value'] ?? '');
    if (isset($seenCombo[$combo]) || count($best) >= 3) continue;   // one set of ideas per winning hook/angle combo
    $seenCombo[$combo] = true; $best[] = [$goal, $list[0]['value'], $patternsAngle[$k][0]['value'] ?? null];
}
if ($best && can('creatives.manage')): $cid = $f['client'] ?: (int)$items[0]['client_id']; ?>
<div class="card" style="margin-bottom:16px"><h2>أفكار مقترحة من الأنماط الناجحة <span class="badge b-warn">مقترحات للمراجعة</span></h2>
  <p class="muted small">الأفكار دي مبنية على أداء سابق في الفترة المختارة، ومش ضمان إن النتيجة هتتكرر. جرّبها كاختبار A/B مع الكريتيف الحالي.</p>
  <?php foreach ($best as [$goal, $hook, $angle]):
      $ideas = ["نسخة جديدة بنفس Hook «{$hook}»" . ($angle ? " وزاوية «{$angle}»" : '') . ' لكن بفتحة مختلفة في أول 3 ثواني (وش متكلم بدل منتج).',
                "اختبر «{$hook}» على جمهور مختلف عن اللي اشتغل عليه، عشان تعرف هل النجاح من الكريتيف ولا من الجمهور."];
      if ($angle) $ideas[] = "نفس زاوية «{$angle}» في فورمات تاني (لو كان فيديو جرّب كاروسيل أو UGC قصير).";
      foreach ($ideas as $idea): ?>
    <form method="post" class="row" style="padding:6px 0;border-bottom:1px dashed var(--line)"><?= csrf_field() ?><input type="hidden" name="a" value="idea"><input type="hidden" name="client_id" value="<?= (int)$cid ?>">
      <input type="hidden" name="title" value="<?= h(mb_substr($idea, 0, 80)) ?>"><input type="hidden" name="idea" value="<?= h($idea) ?>"><input type="hidden" name="basis" value="<?= h(goal_labels()[$goal] . ' / ' . $hook . ' / ' . $angle) ?>">
      <span class="badge b-acc"><?= h(goal_labels()[$goal]) ?></span><span style="flex:1"><?= h($idea) ?></span><button class="btn sm ghost">احفظ الفكرة</button></form>
  <?php endforeach; endforeach; ?>
</div>
<?php endif; ?>

<div class="tbl"><table class="t cards" id="crT"><thead><tr><th>الكريتيف</th><th>الهدف</th><th class="n">Spend</th><th class="n">النتايج</th><th class="n">مقياس الهدف</th><th class="n">Link CTR</th><th class="n">Link CPC</th><th class="n">CPM</th><th class="n" data-col="hook" data-label="Hook rate">Hook rate</th><th class="n" data-col="thru" data-label="ThruPlay">ThruPlay rate</th><th></th></tr></thead><tbody>
<?php foreach ($items as $it): $a = $it['asset']; $m = $it['m']; $cur = $it['currency'];
    $res = $it['goal'] === 'sales' ? 'purchases' : ($it['goal'] === 'leads' ? 'leads' : 'results'); ?>
  <tr><td class="main-cell"><div class="row" style="align-items:flex-start;flex-wrap:nowrap">
      <?php if ($a['thumbnail_url']): ?><img class="thumb" src="<?= h($a['thumbnail_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><div class="thumb"><?= h($a['type'] ?: '—') ?></div><?php endif; ?>
      <div><b><?= h($a['name'] ?: 'بدون اسم') ?></b> <?= $a['in_library'] ? '<span class="badge b-ok">مكتبة</span>' : '' ?> <?= $it['small'] ? '<span class="badge" title="صرف أقل من 5% من الهدف أو أقل من 3 نتايج">عينة صغيرة</span>' : '' ?>
        <?php if ($a['title']): ?><div class="small"><b><?= h($a['title']) ?></b><?= $a['cta'] ? ' · <span class="ltr">' . h($a['cta']) . '</span>' : '' ?></div><?php endif; ?>
        <?php if ($a['body']): ?><div class="small muted"><?= h(mb_substr($a['body'], 0, 140)) ?></div><?php endif; ?>
        <div class="small"><?php foreach (['hook_type', 'angle', 'audience_label', 'product'] as $tg) if ($a[$tg]) echo '<span class="badge" style="margin:2px 0 0 4px">' . h($a[$tg]) . '</span>'; ?></div>
        <div class="small muted"><?= h(implode('، ', array_slice($it['campaigns'], 0, 2))) ?></div></div></div></td>
    <td data-l="الهدف"><span class="badge b-acc"><?= h(goal_labels()[$it['goal']]) ?></span></td>
    <td class="n" data-l="Spend"><?= fmt_money($m['spend'], $cur, 0) ?></td><td class="n" data-l="النتايج"><?= fmt_num($m[$res]) ?></td>
    <td class="n" data-l="<?= h(metric_label($it['kpi'])) ?>"><b><?= fmt_metric($it['kpi'], $m[$it['kpi']], $cur) ?></b><div class="small muted"><?= h(metric_label($it['kpi'])) ?></div></td>
    <td class="n" data-l="Link CTR"><?= fmt_pct($m['link_ctr']) ?></td><td class="n" data-l="Link CPC"><?= fmt_money($m['link_cpc'], $cur) ?></td><td class="n" data-l="CPM"><?= fmt_money($m['cpm'], $cur) ?></td>
    <td class="n" data-col="hook" data-l="Hook rate"><?= fmt_pct($m['hook_rate']) ?></td><td class="n" data-col="thru" data-l="ThruPlay"><?= fmt_pct($m['thruplay_rate']) ?></td>
    <td><?php if (can('creatives.manage')): ?><a class="btn sm ghost" href="<?= h(url('creatives', array_merge($_GET, ['r' => null, 'edit' => $a['id']]))) ?>#e<?= (int)$a['id'] ?>">تصنيف</a><?php endif; ?></td></tr>
  <?php if ($editId === (int)$a['id']): ?><tr id="e<?= (int)$a['id'] ?>"><td colspan="11">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="update"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <div class="fgrid"><div><label class="f">نوع الـ Hook</label><input type="text" name="hook_type" value="<?= h($a['hook_type']) ?>" placeholder="سؤال / شهادة عميل / عرض…"></div>
        <div><label class="f">الزاوية التسويقية</label><input type="text" name="angle" value="<?= h($a['angle']) ?>" placeholder="السعر / الثقة / الألم…"></div>
        <div><label class="f">الجمهور</label><input type="text" name="audience_label" value="<?= h($a['audience_label']) ?>"></div>
        <div><label class="f">المنتج</label><input type="text" name="product" value="<?= h($a['product']) ?>"></div>
        <div><label class="f">Tags</label><input type="text" name="tags" value="<?= h($a['tags']) ?>"></div></div>
      <label class="f">ملاحظات</label><textarea name="notes" style="min-height:50px"><?= h($a['notes']) ?></textarea>
      <label class="check" style="margin-top:8px"><input type="checkbox" name="in_library" value="1" <?= $a['in_library'] ? 'checked' : '' ?>> ضيفه لمكتبة الكريتيفات الناجحة</label>
      <div style="margin-top:10px"><button class="btn sm">حفظ</button></div></form></td></tr><?php endif; ?>
<?php endforeach; ?></tbody></table></div>
<?php endif; layout_end();
