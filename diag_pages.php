<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
refresh_page_tokens(true);
$st = db()->prepare('SELECT id,name,active FROM pages WHERE id = ?'); $st->execute(['2164468943795484']);
echo json_encode(fetch_one($st), JSON_UNESCAPED_UNICODE), " problem=", setting('token_problem'), "\n";
