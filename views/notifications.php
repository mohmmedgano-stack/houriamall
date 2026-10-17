<?php
if (is_post()) {
    if (in_str('a') === 'read_all') q('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), uid()]);
    if ($nid = in_int('open')) {
        $n = q_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$nid, uid()]);
        if ($n) { q('UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ?', [now(), $nid]);
            if ($n['link'] && str_starts_with($n['link'], '?r=')) redirect(base_path() . '/' . $n['link']); }
    }
    redirect(url('notifications'));
}
$p = paginate('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [uid()], 30);
$rows = q_all("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT {$p['per']} OFFSET {$p['offset']}", [uid()]);
layout_start('الإشعارات'); ?>
<div class="row" style="margin-bottom:12px"><span class="sp"></span>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="read_all"><button class="btn sm ghost">تعليم الكل كمقروء</button></form></div>
<div class="card" style="padding:6px 16px">
<?php if (!$rows) echo empty_state('مفيش إشعارات'); ?>
<ul class="timeline"><?php foreach ($rows as $n): ?>
  <li><span class="dotc" style="background:<?= $n['read_at'] ? 'var(--line2)' : ($n['severity'] === 'critical' ? 'var(--bad)' : 'var(--acc)') ?>"></span>
    <div style="flex:1"><b><?= h($n['title']) ?></b><div class="muted small"><?= h($n['body']) ?></div><div class="muted small ltr"><?= h(fmt_dt($n['created_at'])) ?></div></div>
    <?php if ($n['link']): ?><form method="post"><?= csrf_field() ?><button class="btn sm ghost" name="open" value="<?= (int)$n['id'] ?>">فتح</button></form><?php endif; ?></li>
<?php endforeach; ?></ul></div>
<?= pager_html($p) ?>
<?php layout_end();
