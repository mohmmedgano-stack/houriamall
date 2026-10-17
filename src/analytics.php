<?php
declare(strict_types=1);

/** Read dashboard filters from the query string, with safe defaults (last 30 days). */
function perf_filters(): array {
    $to = in_date('to') ?? today();
    $from = in_date('from') ?? (new DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
    if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 731) $from = (new DateTimeImmutable($to))->modify('-731 days')->format('Y-m-d');
    $level = in_array(in_str('level'), ['campaign', 'adset', 'ad'], true) ? in_str('level') : 'campaign';
    return ['clients' => filter_client_ids(), 'client' => in_int('client'), 'account' => in_int('account'), 'campaign' => in_int('campaign'),
        'goal' => array_key_exists(in_str('goal'), goal_labels()) ? in_str('goal') : '', 'from' => $from, 'to' => $to, 'level' => $level,
        'compare' => in_str('compare', '1') === '1'];
}

/**
 * All metric rows in [from, to] for the given clients, each tagged with its campaign/adset/ad ids,
 * account, client, currency and campaign objective. Only rows fully inside the range are used.
 */
function perf_rows(array $clientIds, string $from, string $to, array $opt = []): array {
    if (!$clientIds) return [];
    $bk = $opt['breakdown'] ?? '';
    $where = 'a.client_id IN (' . in_list($clientIds) . ') AND x.date_start >= ? AND x.date_end <= ? AND x.breakdown_key = ?';
    $p = [$from, $to, $bk];
    if (!empty($opt['account'])) { $where .= ' AND a.id = ?'; $p[] = (int)$opt['account']; }
    if (!empty($opt['campaign'])) { $where .= ' AND x.campaign_id = ?'; $p[] = (int)$opt['campaign']; }
    if (!empty($opt['levels'])) $where .= " AND x.level IN ('" . implode("','", array_intersect($opt['levels'], ['campaign', 'adset', 'ad'])) . "')";
    $sql = "SELECT x.*, a.client_id, a.name account_name, cp.name campaign_name, cp.objective, cp.status campaign_status, cp.effective_status
      FROM (
        SELECT ps.*, ps.entity_id campaign_id, NULL adset_id, NULL ad_id FROM performance_snapshots ps WHERE ps.level = 'campaign'
        UNION ALL
        SELECT ps.*, s.campaign_id, s.id adset_id, NULL ad_id FROM performance_snapshots ps JOIN adsets s ON s.id = ps.entity_id WHERE ps.level = 'adset'
        UNION ALL
        SELECT ps.*, s.campaign_id, s.id adset_id, d.id ad_id FROM performance_snapshots ps JOIN ads d ON d.id = ps.entity_id JOIN adsets s ON s.id = d.adset_id WHERE ps.level = 'ad'
      ) x
      JOIN ad_accounts a ON a.id = x.ad_account_id
      JOIN campaigns cp ON cp.id = x.campaign_id
      WHERE $where";
    $rows = q_all($sql, $p);
    if (!empty($opt['goal'])) $rows = array_values(array_filter($rows, fn($r) => goal_of($r['objective'], $r['result_type']) === $opt['goal']));
    return $rows;
}

/**
 * Pick, per campaign, which level's rows to use so nothing is double-counted:
 *  - $want = 'coarsest' → campaign rows if the account has them, else adset, else ad (best for totals: keeps reach exact)
 *  - $want = 'campaign'|'adset'|'ad' → that level if present, else the nearest finer level that can be rolled up
 */
function pick_level_rows(array $rows, string $want): array {
    $by = [];
    foreach ($rows as $r) $by[$r['campaign_id']][$r['level']][] = $r;
    $order = ['campaign', 'adset', 'ad'];
    $out = [];
    foreach ($by as $levels) {
        $candidates = $want === 'coarsest' ? $order : array_slice($order, array_search($want, $order, true));
        foreach ($candidates as $lv) if (!empty($levels[$lv])) { array_push($out, ...$levels[$lv]); break; }
    }
    return $out;
}

/** Group rows by a key function and compute derived metrics per group. Each group keeps one currency. */
function aggregate(array $rows, callable $keyFn): array {
    $g = [];
    foreach ($rows as $r) { $k = $keyFn($r); if ($k === null) continue; $g[$k][] = $r; }
    $out = [];
    foreach ($g as $k => $rs) {
        $curs = array_values(array_unique(array_column($rs, 'currency')));
        $out[$k] = ['key' => $k, 'rows' => $rs, 'currency' => count($curs) === 1 ? $curs[0] : null, 'm' => derive(sum_metrics($rs))];
    }
    return $out;
}

/** Totals per currency (money is never summed across currencies). */
function totals_by_currency(array $rows): array {
    return aggregate(pick_level_rows($rows, 'coarsest'), fn($r) => $r['currency']);
}

/** One line per entity at the requested level, with labels and goal. */
function entity_table(array $rows, string $level): array {
    $rows = pick_level_rows($rows, $level);
    $key = $level . '_id';
    $groups = aggregate($rows, fn($r) => $r[$key] === null ? null : $r['currency'] . '|' . $r[$key]);
    if (!$groups) return [];
    $ids = array_unique(array_map(fn($g) => (int)$g['rows'][0][$key], $groups));
    $names = match ($level) {
        'campaign' => array_column(q_all('SELECT id, name FROM campaigns WHERE id IN (' . in_list($ids) . ')'), 'name', 'id'),
        'adset' => array_column(q_all('SELECT id, name FROM adsets WHERE id IN (' . in_list($ids) . ')'), 'name', 'id'),
        default => array_column(q_all('SELECT id, name FROM ads WHERE id IN (' . in_list($ids) . ')'), 'name', 'id'),
    };
    $out = [];
    foreach ($groups as $g) {
        $f = $g['rows'][0];
        $out[] = $g + ['id' => (int)$f[$key], 'name' => $names[(int)$f[$key]] ?? '#' . $f[$key], 'campaign_id' => (int)$f['campaign_id'],
            'campaign_name' => $f['campaign_name'], 'account_name' => $f['account_name'], 'client_id' => (int)$f['client_id'],
            'objective' => $f['objective'], 'status' => $f['effective_status'] ?: $f['campaign_status'],
            'goal' => goal_of($f['objective'], $g['m']['result_type'])];
    }
    return $out;
}

/** Daily series per currency for charts. Multi-day (period) rows can't be split into days and are left out. */
function daily_series(array $rows, string $from, string $to): array {
    $daily = array_filter(pick_level_rows($rows, 'coarsest'), fn($r) => $r['date_start'] === $r['date_end']);
    $by = aggregate($daily, fn($r) => $r['currency'] . '|' . $r['date_start']);
    $series = [];
    foreach (array_unique(array_column($daily, 'currency')) as $cur) {
        foreach (date_range_days($from, $to) as $d) $series[$cur][$d] = $by["$cur|$d"]['m'] ?? null;
    }
    return $series;
}

function client_names(array $ids): array { return $ids ? array_column(q_all('SELECT id, name FROM clients WHERE id IN (' . in_list($ids) . ')'), 'name', 'id') : []; }

/** Has any performance data been imported/synced for these clients? */
function has_perf_data(array $clientIds): bool {
    return $clientIds && (bool)q_val('SELECT 1 FROM performance_snapshots ps JOIN ad_accounts a ON a.id = ps.ad_account_id WHERE a.client_id IN (' . in_list($clientIds) . ') LIMIT 1');
}
