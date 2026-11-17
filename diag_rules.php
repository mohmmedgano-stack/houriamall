<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
foreach (db()->query("SELECT r.id, p.name pg, r.wait_next, r.wait_text, r.post_id, r.post_label, r.active, r.created_at FROM rules r LEFT JOIN pages p ON p.id = r.page_id WHERE r.created_at > datetime('now','-30 days') AND (r.wait_next = 1 OR r.post_label LIKE '⏰%') ORDER BY r.id") as $r)
  echo implode(' | ', array_map(fn($x) => mb_substr((string)$x, 0, 50), $r)), "\n";
