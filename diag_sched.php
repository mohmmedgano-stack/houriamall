<?php
// one-off diagnostic (CLI only): what does Facebook return for scheduled posts of each page?
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
foreach (db()->query("SELECT * FROM pages WHERE name LIKE '%حورية%'")->fetchAll() as $pg) {
    echo "PAGE {$pg['name']} {$pg['id']}\n";
    foreach ([
        ['scheduled_posts', ['fields' => 'id,message,scheduled_publish_time', 'limit' => 50]],
        ['promotable_posts', ['fields' => 'id,message,is_published,scheduled_publish_time', 'is_published' => 'false', 'limit' => 50]],
        ['feed', ['fields' => 'id,message,is_published,scheduled_publish_time', 'limit' => 5]],
    ] as [$edge, $p]) {
        $r = graph('GET', $pg['id'] . '/' . $edge, $p, $pg['token']);
        echo " $edge: ", gerr($r) ?: (count($r['data'] ?? []) . ' items ' . mb_substr(json_encode(array_slice($r['data'] ?? [], 0, 3), JSON_UNESCAPED_UNICODE), 0, 600)), "\n";
    }
    $t = graph('GET', 'debug_token', ['input_token' => $pg['token']], app_token());
    echo " scopes: ", implode(',', $t['data']['scopes'] ?? []), "\n";
}
