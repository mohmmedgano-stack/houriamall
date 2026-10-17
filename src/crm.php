<?php
declare(strict_types=1);

function dedupe_rules(): array {
    $r = json_decode((string)setting('dedupe_rules', ''), true);
    return array_merge(['phone' => true, 'email' => true, 'window_days' => 90, 'action' => 'flag'], is_array($r) ? $r : []);
}
