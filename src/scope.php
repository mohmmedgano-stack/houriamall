<?php
declare(strict_types=1);

/** 'live' or 'demo' — demo data is kept on is_demo clients and never mixed into live views. */
function mode(): string { return ($_SESSION['mode'] ?? 'live') === 'demo' ? 'demo' : 'live'; }
function set_mode(string $m): void { $_SESSION['mode'] = $m === 'demo' ? 'demo' : 'live'; unset($GLOBALS['__scope']); }

/** Client ids the current user may see in the current mode. */
function scope_client_ids(?array $u = null): array {
    $u ??= current_user();
    if (!$u) return [];
    $key = $u['id'] . '|' . mode();
    if (isset($GLOBALS['__scope'][$key])) return $GLOBALS['__scope'][$key];
    $demo = mode() === 'demo' ? 1 : 0;
    if (is_admin($u)) $ids = q_col("SELECT id FROM clients WHERE is_demo = ? AND status <> 'archived' ORDER BY name", [$demo]);
    else $ids = q_col("SELECT c.id FROM clients c JOIN user_clients uc ON uc.client_id = c.id WHERE uc.user_id = ? AND c.is_demo = ? AND c.status <> 'archived' ORDER BY c.name", [$u['id'], $demo]);
    return $GLOBALS['__scope'][$key] = array_map('intval', $ids);
}
function scope_clients(): array {
    $ids = scope_client_ids();
    return $ids ? q_all('SELECT * FROM clients WHERE id IN (' . in_list($ids) . ') ORDER BY name') : [];
}
function can_client(?int $clientId): bool { return $clientId !== null && in_array($clientId, scope_client_ids(), true); }
function require_client(?int $clientId): array {
    if (!can_client($clientId)) throw new Forbidden('client');
    return q_one('SELECT * FROM clients WHERE id = ?', [$clientId]);
}
/** Optional ?client= filter, restricted to scope. Returns the list of client ids to query. */
function filter_client_ids(): array {
    $c = in_int('client');
    $all = scope_client_ids();
    return ($c && in_array($c, $all, true)) ? [$c] : $all;
}

function account_client_id(int $accountId): ?int { $v = q_val('SELECT client_id FROM ad_accounts WHERE id = ?', [$accountId]); return $v === null ? null : (int)$v; }
function campaign_client_id(int $campaignId): ?int {
    $v = q_val('SELECT a.client_id FROM campaigns c JOIN ad_accounts a ON a.id = c.ad_account_id WHERE c.id = ?', [$campaignId]);
    return $v === null ? null : (int)$v;
}
function lead_visible(array $lead): bool {
    if (!can_client((int)$lead['client_id'])) return false;
    if (can('crm.view_all') || is_admin()) return true;
    return (int)($lead['owner_user_id'] ?? 0) === uid();
}
