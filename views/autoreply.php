<?php
if (is_post()) {
    $a = in_str('a');
    if ($a === 'map') { require_perm('clients.manage'); ar_map_page(in_str('page_id'), in_int('client_id') ?: null); flash('اتربطت الصفحة بالعميل ✅'); }
    if ($a === 'config') {
        require_perm('integrations.manage');
        $path = in_str('db_path'); $u = in_str('url');
        if ($path !== '' && !preg_match('/\.sqlite$/', $path)) throw new InvalidArgumentException('المسار لازم يكون لملف .sqlite');
        set_setting('autoreply_db', $path ?: null); set_setting('autoreply_url', $u ?: null); ar_reset();
        audit('autoreply.config'); flash(ar_db() ? 'اتوصلت بأداة الرد ✅' : 'مش قادر أفتح قاعدة بيانات الأداة على المسار ده', ar_db() ? 'ok' : 'err');
    }
    redirect(url('autoreply'));
}
$st = ar_status();
layout_start('Facebook Auto Reply'); ?>
<?php if (!$st['connected']): ?>
<div class="card"><?= empty_state('أداة الرد التلقائي مش متوصلة', ' المنصة بتقرا بيانات أداة Gano Auto Reply من نفس السيرفر (قراءة فقط). لو الأداة على نفس الاستضافة في مجلد autoreply هتتوصل لوحدها.') ?>
  <?php if (can('integrations.manage')): ?><form method="post" style="max-width:520px;margin:auto"><?= csrf_field() ?><input type="hidden" name="a" value="config">
    <label class="f">مسار قاعدة بيانات الأداة</label><input type="text" name="db_path" dir="ltr" value="<?= h(setting('autoreply_db') ?? '') ?>" placeholder="<?= h(ar_db_path()) ?>">
    <label class="f">رابط لوحة الأداة</label><input type="text" name="url" dir="ltr" value="<?= h(setting('autoreply_url') ?? '') ?>" placeholder="https://ganoadsai.online/autoreply/">
    <button class="btn" style="margin-top:10px">حفظ</button></form><?php endif; ?></div>
<?php else:
  $pages = ar_visible_pages();
  $pid = in_str('page');
  $ids = $pid !== '' && in_array($pid, array_column($pages, 'id'), true) ? [$pid] : array_column($pages, 'id');
  [$log, $total] = ar_log($ids, ['status' => in_str('status'), 'q' => in_str('q'), 'page' => in_int('page_n', 1)]);
  $pg = ['total' => $total, 'page' => in_int('page_n', 1), 'pages' => max(1, (int)ceil($total / 30))]; ?>
<div class="kpis" style="margin-bottom:14px">
  <?= kpi('الصفحات المفعّلة', $st['active_pages'] . ' / ' . $st['pages']) ?>
  <?= kpi('قواعد الرد النشطة', (string)$st['rules']) ?>
  <?= kpi('الخلفية (Worker)', $st['worker_ok'] ? '<span style="color:var(--ok)">شغال</span>' : '<span style="color:var(--bad)">واقف</span>', null, false, 'آخر نبضة: ' . ($st['worker_seen'] ?? '—')) ?>
  <?= kpi('آخر Webhook', $st['last_webhook'] ? h(fmt_dt($st['last_webhook'], 'm-d H:i')) : '—') ?>
</div>
<?php if ($st['token_problem']): ?><div class="flash err">أداة الرد عندها مشكلة في التوكن من <?= h($st['token_problem']) ?> — افتح الأداة وأعد ربط الصفحات.</div><?php endif; ?>
<?php if ($st['worker_error']): ?><div class="flash warn small">آخر خطأ في الأداة: <?= h(mb_substr($st['worker_error'], 0, 200)) ?></div><?php endif; ?>
<p class="muted small">قواعد الرد والإعدادات بتتعدل من <a href="<?= h(ar_url()) ?>" target="_blank" rel="noopener">لوحة الأداة نفسها</a> عشان مفيش تعارض في الكتابة. هنا بتربط كل صفحة بعميل، وبتتابع السجل، وأي فشل بيوصل للـ Automation Hub كحدث "فشل في الرد التلقائي".</p>
<div class="grid g2">
<div class="card"><h2>الصفحات</h2>
  <?php if (!$pages) echo empty_state('مفيش صفحات ظاهرة ليك', is_admin() ? '' : ' الصفحات بتظهر لما تتربط بعميل من عملاءك.'); ?>
  <?php foreach ($pages as $p): $s = $p['stats']; ?>
    <div style="padding:9px 0;border-bottom:1px solid var(--line)">
      <div class="row"><span class="status-dot <?= $p['active'] ? 'ok' : '' ?>"></span><a href="<?= h(url('autoreply', ['page' => $p['id']])) ?>"><b><?= h($p['name']) ?></b></a><span class="muted small"><?= $p['rules'] ?> قاعدة</span><span class="sp"></span>
        <?php if (can('clients.manage')): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="map"><input type="hidden" name="page_id" value="<?= h($p['id']) ?>">
          <select name="client_id" style="max-width:170px" onchange="this.form.submit()"><?= select_opts(client_opts(), $p['client_id'], true, 'مش مربوطة بعميل') ?></select></form>
        <?php else: echo '<span class="small">' . h(client_opts()[$p['client_id']] ?? '') . '</span>'; endif; ?></div>
      <div class="small muted">آخر 7 أيام: <?= (int)($s['total'] ?? 0) ?> كومنت · <?= (int)($s['replied'] ?? 0) ?> رد · <?= (int)($s['dms'] ?? 0) ?> رسالة خاصة · <?= (int)($s['blocked'] ?? 0) ?> محظور<?= ($s['failed'] ?? 0) ? ' · <b style="color:var(--bad)">' . (int)$s['failed'] . ' فشل</b>' : '' ?> · آخر 24 ساعة: <?= (int)($s['d1'] ?? 0) ?></div></div>
  <?php endforeach; ?></div>
<div class="card"><h2>السجل <?= $pid !== '' ? '— ' . h(array_column($pages, 'name', 'id')[$pid] ?? '') : '' ?></h2>
  <form class="filters" data-autosubmit><input type="hidden" name="r" value="autoreply"><input type="hidden" name="page" value="<?= h($pid) ?>">
    <div><label>الحالة</label><select name="status"><?= select_opts(['failed' => 'فشل', 'retry' => 'هيعيد المحاولة', 'blocked' => 'محظور'], in_str('status'), true) ?></select></div>
    <div><label>بحث</label><input type="search" name="q" value="<?= h(in_str('q')) ?>"></div></form>
  <?php if (!$log) echo empty_state('مفيش سجل'); ?>
  <?php foreach ($log as $l): ?><div style="padding:7px 0;border-bottom:1px solid var(--line)" class="small">
    <div class="row"><b><?= h($l['from_name'] ?: '—') ?></b><span class="muted"><?= h($l['rule_name'] ?: '') ?></span><span class="sp"></span><span class="muted ltr"><?= h(fmt_dt($l['created_at'], 'm-d H:i')) ?></span></div>
    <div><?= h(mb_substr((string)$l['message'], 0, 160)) ?></div>
    <div class="row">كومنت <?= ar_status_badge($l['comment_status']) ?> رسالة <?= ar_status_badge($l['dm_status']) ?><?= $l['images_status'] ? ' صور ' . ar_status_badge($l['images_status']) : '' ?><?= (int)$l['attempts'] > 1 ? ' <span class="muted">' . (int)$l['attempts'] . ' محاولات</span>' : '' ?></div>
    <?php if ($l['error']): ?><div class="muted" style="color:var(--warn)"><?= h(mb_substr($l['error'], 0, 200)) ?></div><?php endif; ?></div><?php endforeach; ?>
  <?php if ($total > 30): $q = $_GET; ?><div class="pager"><span class="muted"><?= $total ?> صف</span><?php if ($pg['page'] > 1) { $q['page_n'] = $pg['page'] - 1; echo '<a class="btn sm ghost" href="?' . h(http_build_query($q)) . '">‹ السابق</a>'; } if ($pg['page'] < $pg['pages']) { $q['page_n'] = $pg['page'] + 1; echo '<a class="btn sm ghost" href="?' . h(http_build_query($q)) . '">التالي ›</a>'; } ?></div><?php endif; ?>
</div></div>
<?php endif; layout_end();
