<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
$u = setting('user_token');
echo "db pages: ", fetch_col(db()->query('SELECT COUNT(*) FROM pages')), "\n";
foreach (db()->query('SELECT id,name,active FROM pages') as $r) echo "  $r[id] $r[name] a=$r[active]\n";
$d = graph('GET', 'debug_token', ['input_token' => $u], setting('app_id') . '|' . setting('app_secret'));
echo "valid: ", json_encode($d['data']['is_valid'] ?? null), " expires: ", $d['data']['expires_at'] ?? '', "\n";
foreach ($d['data']['granular_scopes'] ?? [] as $g) echo "  ", $g['scope'], ": ", count($g['target_ids'] ?? []), " targets\n";
$a = graph('GET', 'me/accounts', ['fields' => 'id,name', 'limit' => 100], $u);
echo "me/accounts: ", gerr($a) ?: count($a['data'] ?? []), "\n";
$b = graph('GET', 'me/businesses', ['fields' => 'id,name'], $u);
echo "businesses: ", gerr($b) ?: json_encode($b['data'] ?? [], JSON_UNESCAPED_UNICODE), "\n";
refresh_page_tokens(true);
echo "after sync db pages: ", fetch_col(db()->query('SELECT COUNT(*) FROM pages')), " problem=", setting('token_problem'), "\n";
