<?php
$out = null; $inputs = null; $source = null;
if (is_post()) {
    $a = in_str('a');
    if ($a === 'generate') {
        $cid = (int)in_int('client_id');
        if (!can_client($cid)) throw new Forbidden('client');
        $inputs = copy_inputs($_POST);
        [$out, $source, $err] = copy_generate($inputs, in_str('ai') === '1');
        if ($err) flash('الـ AI مااشتغلش (' . $err . ') — اتعمل باستخدام القوالب.', 'warn');
        $id = idea_save($cid, $inputs, $out, $source, in_str('title'));
        redirect(url('copy', ['idea' => $id]));
    }
    if ($a === 'update') { idea_update((int)in_int('id'), $_POST); flash('اتحفظ ✅'); redirect(url('copy', ['idea' => in_int('id')])); }
    if ($a === 'delete') {
        $i = q_one('SELECT * FROM creative_ideas WHERE id = ?', [(int)in_int('id')]);
        if (!$i || !can_client((int)$i['client_id'])) throw new Forbidden('idea');
        q('DELETE FROM creative_ideas WHERE id = ?', [$i['id']]); flash('اتمسح'); redirect(url('copy'));
    }
}
$ids = filter_client_ids();
$idea = in_int('idea') ? q_one('SELECT * FROM creative_ideas WHERE id = ?', [in_int('idea')]) : null;
if ($idea && !can_client((int)$idea['client_id'])) $idea = null;
$list = $ids ? q_all('SELECT i.id, i.title, i.status, i.source, i.kind, i.created_at, c.name cname FROM creative_ideas i JOIN clients c ON c.id = i.client_id WHERE i.client_id IN (' . in_list($ids) . ') ORDER BY i.id DESC LIMIT 40') : [];
$pat = idea_patterns($ids);
$prev = $idea ? (json_decode((string)$idea['inputs_json'], true) ?: []) : [];
layout_start('Ad Copy & Hook Generator'); ?>
<div class="grid g2">
<div class="stack">
<div class="card"><h2>المدخلات</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="generate">
    <div class="fgrid">
      <div><label class="f">العميل</label><select name="client_id"><?= select_opts(client_opts(), $idea['client_id'] ?? in_int('client')) ?></select></div>
      <div><label class="f">المنتج / المشروع *</label><input type="text" name="product" required value="<?= h($prev['product'] ?? '') ?>"></div>
      <div><label class="f">الجمهور المستهدف</label><input type="text" name="audience" value="<?= h($prev['audience'] ?? '') ?>" placeholder="مثلاً: موظفين 28-45 في القاهرة"></div>
      <div><label class="f">السعر</label><input type="text" name="price" value="<?= h($prev['price'] ?? '') ?>"></div>
      <div><label class="f">العرض</label><input type="text" name="offer" value="<?= h($prev['offer'] ?? '') ?>" placeholder="لو مفيش عرض سيبه فاضي"></div>
      <div><label class="f">الهدف الإعلاني</label><select name="objective"><?= select_opts(['leads' => 'Leads', 'sales' => 'Sales', 'messages' => 'Messages', 'traffic' => 'Traffic', 'awareness' => 'Awareness', 'engagement' => 'Engagement'], $prev['objective'] ?? 'leads') ?></select></div>
      <div><label class="f">المنصة</label><select name="platform"><?= select_opts(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'google' => 'Google'], $prev['platform'] ?? 'facebook') ?></select></div>
      <div><label class="f">اللغة واللهجة</label><select name="lang"><?= select_opts(['eg' => 'عامية مصرية', 'msa' => 'عربي فصحى', 'en' => 'English'], $prev['lang'] ?? 'eg') ?></select></div>
      <div><label class="f">نوع المحتوى</label><select name="content"><?= select_opts(['all' => 'الكل', 'video' => 'فيديو / Reels', 'image' => 'صورة', 'ugc' => 'UGC'], $prev['content'] ?? 'all') ?></select></div>
    </div>
    <label class="f">وصف المنتج</label><textarea name="description" style="min-height:70px"><?= h($prev['description'] ?? '') ?></textarea>
    <label class="f">المميزات التنافسية (كل ميزة في سطر)</label><textarea name="usps" style="min-height:80px"><?= h(implode("\n", $prev['usps'] ?? [])) ?></textarea>
    <label class="f">اسم الفكرة (اختياري)</label><input type="text" name="title">
    <?php if (function_exists('ai_available') && ai_available()): ?><label class="check" style="margin-top:10px"><input type="checkbox" name="ai" value="1" checked> استخدم الـ AI (بنفس القواعد: مفيش اختراع مميزات أو وعود)</label>
    <?php else: ?><p class="hint">بيشتغل بقوالب جاهزة من غير AI. تقدر تربط مفتاح AI من التكاملات لنتايج أكتر تنوعاً.</p><?php endif; ?>
    <button class="btn" style="margin-top:12px">ولّد الأفكار</button>
    <p class="hint">مش هنضيف أي ميزة أو رقم أو ضمان مكتبتوش — راجع النص قبل النشر.</p></form></div>
<?php if ($pat): ?><div class="card"><h2>الأنماط اللي نجحت في اختباراتك</h2>
  <?php foreach ($pat as $k => $vals): ?><div class="small muted"><?= $k === 'angle' ? 'الزوايا' : 'الـ Hooks' ?></div>
    <?php foreach ($vals as $v => $c): ?><div class="row small"><span class="badge"><?= h($v) ?></span><span class="badge b-ok">نجح <?= (int)($c['winner'] ?? 0) ?></span><span class="badge b-bad">فشل <?= (int)($c['loser'] ?? 0) ?></span></div><?php endforeach; endforeach; ?>
  <p class="hint">بيتحسب من الأفكار اللي سجلت نتيجة اختبارها ومربوطة بكريتيف متصنّف.</p></div><?php endif; ?>
<div class="card"><h2>الأفكار المحفوظة</h2>
  <?php if (!$list) echo empty_state('مفيش أفكار محفوظة'); ?>
  <?php foreach ($list as $l): ?><div class="row small" style="padding:6px 0;border-bottom:1px solid var(--line)"><a href="<?= h(url('copy', ['idea' => $l['id']])) ?>" style="flex:1"><?= h($l['title']) ?></a>
    <span class="badge <?= ['winner' => 'b-ok', 'loser' => 'b-bad', 'testing' => 'b-warn'][$l['status']] ?? '' ?>"><?= h(['idea' => 'فكرة', 'testing' => 'بيتختبر', 'winner' => 'نجح', 'loser' => 'فشل', 'archived' => 'أرشيف'][$l['status']] ?? $l['status']) ?></span>
    <?= $l['source'] === 'ai' ? '<span class="badge b-info">AI</span>' : '' ?><span class="muted"><?= h($l['cname']) ?></span></div><?php endforeach; ?></div>
</div>

<div class="stack">
<?php if ($idea): $o = json_decode((string)$idea['output_json'], true) ?: [];
  $crs = q_all('SELECT id, name, title FROM creative_assets WHERE client_id = ? ORDER BY id DESC LIMIT 200', [$idea['client_id']]); ?>
<div class="card"><h2><?= h($idea['title']) ?> <?= $idea['source'] === 'ai' ? '<span class="badge b-info">AI</span>' : '<span class="badge">قوالب</span>' ?></h2>
  <form method="post" class="fgrid"><?= csrf_field() ?><input type="hidden" name="a" value="update"><input type="hidden" name="id" value="<?= (int)$idea['id'] ?>">
    <div><label class="f">الحالة</label><select name="status"><?= select_opts(['idea' => 'فكرة', 'testing' => 'بيتختبر', 'winner' => 'نجح', 'loser' => 'فشل', 'archived' => 'أرشيف'], $idea['status']) ?></select></div>
    <div><label class="f">مربوطة بكريتيف فعلي</label><select name="creative_asset_id"><?= select_opts(array_map(fn($c) => $c['name'] ?: $c['title'] ?: '#' . $c['id'], array_column($crs, null, 'id')), $idea['creative_asset_id'], true, '—') ?></select></div>
    <div style="grid-column:1/-1"><label class="f">نتيجة الاختبار</label><textarea name="test_notes" style="min-height:50px" placeholder="مثلاً: Hook B جاب CTR 1.9% مقابل 1.1%"><?= h($idea['test_notes']) ?></textarea></div>
    <div><button class="btn sm">حفظ</button></div></form>
  <?php if (isset($o['idea'])): ?><p class="out"><?= h($o['idea']) ?></p><?php endif; ?>
  <?php $sections = ['hooks' => 'Hooks', 'primary_text' => 'Primary Text', 'headlines' => 'Headlines', 'descriptions' => 'Descriptions', 'ctas' => 'CTAs', 'ugc' => 'أفكار UGC'];
  foreach ($sections as $k => $lbl): if (empty($o[$k])) continue; ?>
    <h3 style="margin:16px 0 6px;font-size:14px"><?= h($lbl) ?></h3>
    <?php foreach ($o[$k] as $x): ?><div class="out small" style="margin-bottom:6px"><?= h(is_array($x) ? implode(' — ', $x) : $x) ?></div><?php endforeach; endforeach; ?>
  <?php if (!empty($o['angles'])): ?><h3 style="margin:16px 0 6px;font-size:14px">زوايا تسويقية</h3><?php foreach ($o['angles'] as $x) echo '<div class="small" style="margin:3px 0"><b>' . h($x['name']) . ':</b> ' . h($x['idea']) . '</div>'; endif; ?>
  <?php if (!empty($o['reels'])): ?><h3 style="margin:16px 0 6px;font-size:14px">أفكار Reels</h3><?php foreach ($o['reels'] as $x) echo '<div class="out small" style="margin-bottom:6px"><b>' . h($x['title']) . '</b><br>' . h(implode(' ← ', array_filter($x['beats']))) . '</div>'; endif; ?>
  <?php if (!empty($o['scripts'])): ?><h3 style="margin:16px 0 6px;font-size:14px">Video Scripts</h3><?php foreach ($o['scripts'] as $x): ?><div class="tbl" style="margin-bottom:8px"><table class="t"><thead><tr><th colspan="3"><?= h($x['title']) ?></th></tr></thead><tbody><?php foreach ($x['scenes'] as $s) echo '<tr><td class="ltr" style="width:70px">' . h($s[0]) . '</td><td>' . h($s[1]) . '</td><td class="muted small">' . h($s[2]) . '</td></tr>'; ?></tbody></table></div><?php endforeach; endif; ?>
  <?php if (!empty($o['ab_tests'])): ?><h3 style="margin:16px 0 6px;font-size:14px">خطة A/B Testing</h3><?php foreach ($o['ab_tests'] as $x) echo '<div class="small" style="padding:6px 0;border-bottom:1px dashed var(--line)"><b>' . h($x['test']) . '</b>: A = ' . h($x['a']) . ' | B = ' . h($x['b']) . '<br><span class="muted">المقياس: ' . h($x['metric']) . ' — ' . h($x['note']) . '</span></div>'; endif; ?>
  <?php foreach ($o['notes'] ?? [] as $n) echo '<p class="hint">' . h($n) . '</p>'; ?>
  <form method="post" data-confirm="تمسح الفكرة؟" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= (int)$idea['id'] ?>"><button class="btn sm ghost">حذف</button></form>
</div>
<?php else: ?><div class="card"><?= empty_state('اكتب بيانات المنتج وولّد الأفكار', ' Hooks، Primary Text، Headlines، CTAs، أفكار Reels و UGC، سكريبتات فيديو، وخطة A/B Testing.') ?></div><?php endif; ?>
</div></div>
<?php layout_end();
