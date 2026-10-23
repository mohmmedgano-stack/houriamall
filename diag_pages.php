<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
$u = setting('user_token');
$db = []; foreach (db()->query('SELECT id,name FROM pages') as $r) $db[$r['id']] = $r['name'];
echo "db: ", count($db), "\n";
$a = graph('GET', 'me/accounts', ['fields' => 'id,name,tasks', 'limit' => 100], $u);
echo "me/accounts: ", gerr($a) ?: count($a['data'] ?? []), "\n";
foreach ($a['data'] ?? [] as $p) if (!isset($db[$p['id']])) echo "  NEW in accounts: $p[id] $p[name]\n";
$b = graph('GET', 'me/businesses', ['fields' => 'id,name'], $u);
foreach ($b['data'] ?? [] as $bz) foreach (['owned_pages', 'client_pages'] as $e) {
  $r = graph('GET', $bz['id'] . "/$e", ['fields' => 'id,name,access_token', 'limit' => 100], $u);
  if (gerr($r)) { echo "  $bz[name] $e: ", gerr($r), "\n"; continue; }
  foreach ($r['data'] ?? [] as $p) if (!isset($db[$p['id']])) echo "  NOT IN DB via $bz[name]/$e: $p[id] $p[name] token=", empty($p['access_token']) ? 'no' : 'yes', "\n";
}
echo "tokens_refreshed: ", date('c', (int)setting('tokens_refreshed')), " problem=", setting('token_problem'), "\n";
