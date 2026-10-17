<?php
// prints one id per detail page from the smoke-test database (demo data)
require __DIR__ . '/../src/bootstrap.php';
$one = fn($sql) => ($v = q_val($sql)) === null ? null : (int)$v;
echo json_encode([
  'client' => $one('SELECT id FROM clients LIMIT 1'),
  'campaign' => $one('SELECT id FROM campaigns LIMIT 1'),
  'lead' => $one('SELECT id FROM leads LIMIT 1'),
  'report' => $one('SELECT id FROM reports LIMIT 1'),
]);
