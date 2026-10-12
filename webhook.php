<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-LiteSpeed-Cache-Control: no-cache');

// 1) Verification handshake from Meta
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $token = (string)setting('verify_token', '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $token !== '' && hash_equals($token, (string)($_GET['hub_verify_token'] ?? ''))) {
        set_setting('webhook_ok', now());
        header('Content-Type: text/plain');
        echo (string)($_GET['hub_challenge'] ?? '');
        exit;
    }
    http_response_code(403);
    exit('Forbidden');
}

// 2) Event delivery — verify signature
$raw = (string)file_get_contents('php://input');
$secret = (string)setting('app_secret', '');
$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
    http_response_code(403);
    exit('Bad signature');
}

// Answer Meta immediately, then work in the background
ignore_user_abort(true);
@set_time_limit(120);
http_response_code(200);
header('Content-Type: text/plain');
echo 'EVENT_RECEIVED';
if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); }
elseif (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
else { @ob_end_flush(); @flush(); }

set_setting('last_webhook', now());

$data = json_decode($raw, true);
if (!is_array($data) || ($data['object'] ?? '') !== 'page') exit;

foreach ($data['entry'] ?? [] as $entry) {
    $pageId = (string)($entry['id'] ?? '');
    $page = get_page($pageId);
    if (!$page || (int)$page['active'] !== 1) continue;

    foreach ($entry['changes'] ?? [] as $ch) {
        if (($ch['field'] ?? '') !== 'feed') continue;
        $v = $ch['value'] ?? [];
        if (($v['item'] ?? '') !== 'comment' || ($v['verb'] ?? '') !== 'add') continue;

        $postId    = (string)($v['post_id'] ?? '');
        $commentId = (string)($v['comment_id'] ?? '');
        $parentId  = (string)($v['parent_id'] ?? $postId);
        $fromId    = (string)($v['from']['id'] ?? '');
        if ($postId === '' || $commentId === '') continue;
        if ($parentId !== $postId) continue;   // only top-level comments, not replies
        if ($fromId === $pageId) continue;      // never answer the page itself

        try {
            process_comment(
                $page, $postId, $commentId,
                (string)($v['from']['name'] ?? ''),
                (string)($v['message'] ?? ''),
                isset($v['created_time']) ? (int)$v['created_time'] : null,
                $fromId
            );
        } catch (Throwable $e) {
            error_log('[autoreply] ' . $e->getMessage());
        }
    }
}
