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

    // someone wrote to the page or tapped "see photos" → send the photos we promised
    // 'standby' = messages that reached us while another app/bot (e.g. Meta Business AI) owns the chat
    foreach (array_merge($entry['messaging'] ?? [], $entry['standby'] ?? []) as $ev) {
        if (!empty($ev['message']['is_echo'])) continue;
        $psid = (string)($ev['sender']['id'] ?? '');
        if ($psid === '' || $psid === $pageId) continue;
        $payload = (string)($ev['message']['quick_reply']['payload'] ?? ($ev['postback']['payload'] ?? ''));
        $cid = strpos($payload, 'IMG|') === 0 ? substr($payload, 4) : null;
        try { send_pending_images($page, $psid, $cid ?: null); }
        catch (Throwable $e) { error_log('[autoreply] images: ' . $e->getMessage()); }
    }

    foreach ($entry['changes'] ?? [] as $ch) {
        if (($ch['field'] ?? '') !== 'feed') continue;
        $v = $ch['value'] ?? [];
        // the page just published a post (incl. a scheduled one going live) → start any rule waiting for it
        if (($v['item'] ?? '') !== 'comment' && ($v['verb'] ?? '') === 'add' && (string)($v['from']['id'] ?? '') === $pageId
            && !empty($v['post_id']) && (int)($v['published'] ?? 1) === 1) {
            try { bind_next_rules($pageId, (string)$v['post_id'], isset($v['created_time']) ? (int)$v['created_time'] : time(), mb_substr(trim((string)($v['message'] ?? '')), 0, 60), (string)($v['message'] ?? '')); }
            catch (Throwable $e) { error_log('[autoreply] bind: ' . $e->getMessage()); }
            continue;
        }
        if (($v['item'] ?? '') !== 'comment' || ($v['verb'] ?? '') !== 'add') continue;

        $postId    = (string)($v['post_id'] ?? '');
        $commentId = (string)($v['comment_id'] ?? '');
        $parentId  = (string)($v['parent_id'] ?? $postId);
        $fromId    = (string)($v['from']['id'] ?? '');
        if ($postId === '' || $commentId === '') continue;
        $isReply   = $parentId !== $postId;     // replies are moderated (blocked words) but never auto-answered
        if ($fromId === $pageId) continue;      // never touch the page itself

        try {
            process_comment(
                $page, $postId, $commentId,
                (string)($v['from']['name'] ?? ''),
                (string)($v['message'] ?? ''),
                isset($v['created_time']) ? (int)$v['created_time'] : null,
                $fromId, $isReply
            );
        } catch (Throwable $e) {
            // nothing is lost: the worker's catch-up sweep picks this comment up within ~10 minutes
            error_log('[autoreply] ' . $e->getMessage());
        }
    }
}
