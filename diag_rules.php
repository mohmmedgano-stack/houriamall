<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
foreach (db()->query("SELECT r.id, r.wait_next, r.post_id, (SELECT status || ' ' || COALESCE(done,0) FROM jobs j WHERE j.rule_id = r.id ORDER BY j.id DESC LIMIT 1) job FROM rules r WHERE r.id IN (113,119,123,248,254,256,264,275)") as $r) echo implode(' | ', $r), "\n";
echo 'worker: ', setting('worker_seen'), ' err=', setting('worker_error'), "\n";
