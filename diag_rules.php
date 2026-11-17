<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
$pg = fetch_one(db()->query("SELECT * FROM pages WHERE id = '263552803503245'"));
$posts = [];
foreach (['posts', 'feed', 'published_posts'] as $edge) {
  $r = graph('GET', $pg['id'] . "/$edge", ['fields' => 'id,message,created_time', 'limit' => 100], $pg['token']);
  echo "$edge: ", gerr($r) ?: count($r['data'] ?? []), "\n";
  foreach ($r['data'] ?? [] as $p) $posts[$p['id']] = $p;
}
$v = graph('GET', $pg['id'] . '/video_reels', ['fields' => 'id,description,created_time,post_id', 'limit' => 50], $pg['token']);
echo "reels: ", gerr($v) ?: count($v['data'] ?? []), "\n";
foreach ($v['data'] ?? [] as $p) $posts['reel:' . $p['id']] = ['id' => 'reel:' . $p['id'] . ' post=' . ($p['post_id'] ?? ''), 'message' => $p['description'] ?? '', 'created_time' => $p['created_time'] ?? ''];
foreach (db()->query("SELECT id, wait_text, created_at FROM rules WHERE wait_next = 1") as $r) {
  echo "rule $r[id] (", $r['created_at'], "): ";
  $hits = [];
  foreach ($posts as $p) if (text_has_phrase((string)($p['message'] ?? ''), $r['wait_text'])) $hits[] = $p['id'] . '@' . ($p['created_time'] ?? '');
  echo $hits ? implode(', ', $hits) : 'no post yet', "\n";
}
