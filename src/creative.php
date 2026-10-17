<?php
declare(strict_types=1);

/** Metrics per creative asset (ad-level data rolled up across every ad that uses it). */
function creative_performance(array $clientIds, string $from, string $to, array $opt = []): array {
    $rows = array_values(array_filter(perf_rows($clientIds, $from, $to, $opt + ['levels' => ['ad']]), fn($r) => $r['ad_id'] !== null));
    if (!$rows) return [];
    $adCr = array_column(q_all('SELECT id, creative_asset_id FROM ads WHERE id IN (' . in_list(array_unique(array_column($rows, 'ad_id'))) . ')'), 'creative_asset_id', 'id');
    $groups = aggregate($rows, fn($r) => ($adCr[$r['ad_id']] ?? null) ? $r['currency'] . '|' . $adCr[$r['ad_id']] : null);
    if (!$groups) return [];
    $assets = array_column(q_all('SELECT * FROM creative_assets WHERE id IN (' . in_list(array_map(fn($k) => (int)explode('|', $k)[1], array_keys($groups))) . ')'), null, 'id');
    $out = [];
    foreach ($groups as $k => $g) {
        $aid = (int)explode('|', $k)[1];
        if (!isset($assets[$aid])) continue;
        $f = $g['rows'][0];
        $goal = goal_of($f['objective'], $g['m']['result_type']);
        $campaigns = array_values(array_unique(array_column($g['rows'], 'campaign_name')));
        unset($g['rows']);
        $out[] = $g + ['asset' => $assets[$aid], 'goal' => $goal, 'campaigns' => $campaigns, 'client_id' => (int)$f['client_id']];
    }
    return $out;
}

/** Mark creatives whose sample is too small to judge (less than 5% of the goal's spend or < 3 results). */
function creative_flag_samples(array $items): array {
    $spendByGoal = [];
    foreach ($items as $it) $spendByGoal[$it['goal'] . $it['currency']] = ($spendByGoal[$it['goal'] . $it['currency']] ?? 0) + (float)($it['m']['spend'] ?? 0);
    foreach ($items as &$it) {
        [$k] = goal_kpi($it['goal']);
        $res = $it['goal'] === 'sales' ? $it['m']['purchases'] : ($it['goal'] === 'leads' ? $it['m']['leads'] : $it['m']['results']);
        $it['small'] = ($it['m']['spend'] ?? 0) < 0.05 * ($spendByGoal[$it['goal'] . $it['currency']] ?? 0) || ($res ?? 0) < 3;
        $it['kpi'] = $k;
    }
    return $items;
}

/** Which hooks / angles work best, per goal — the patterns new ideas are built from. */
function creative_patterns(array $items, string $field): array {
    $g = [];
    foreach ($items as $it) {
        $v = trim((string)($it['asset'][$field] ?? ''));
        if ($v === '' || $it['small']) continue;
        $g[$it['goal'] . '|' . $it['currency'] . '|' . $v][] = $it['m'];
    }
    $out = [];
    foreach ($g as $k => $ms) {
        [$goal, $cur, $v] = explode('|', $k, 3);
        $m = derive(sum_metrics($ms));
        [$kpi, $lower] = goal_kpi($goal);
        $out[$goal . '|' . $cur][] = ['value' => $v, 'm' => $m, 'kpi' => $kpi, 'n' => count($ms), 'lower' => $lower];
    }
    foreach ($out as &$list) usort($list, fn($a, $b) => ($a['m'][$a['kpi']] === null) <=> ($b['m'][$b['kpi']] === null) ?: ($a['lower'] ? $a['m'][$a['kpi']] <=> $b['m'][$b['kpi']] : $b['m'][$b['kpi']] <=> $a['m'][$a['kpi']]));
    return $out;
}

function creative_update(int $id, array $in): void {
    $c = q_one('SELECT * FROM creative_assets WHERE id = ?', [$id]);
    if (!$c || !can_client((int)$c['client_id'])) throw new Forbidden('creative');
    $f = [];
    foreach (['hook_type', 'angle', 'audience_label', 'product', 'tags', 'notes'] as $k) if (array_key_exists($k, $in)) $f[$k] = mb_substr(trim((string)$in[$k]), 0, $k === 'notes' ? 2000 : 120);
    if (array_key_exists('in_library', $in)) $f['in_library'] = (int)!empty($in['in_library']);
    db_update('creative_assets', $id, $f + ['updated_at' => now()]);
    audit('creative.update', 'creative', $id);
}
