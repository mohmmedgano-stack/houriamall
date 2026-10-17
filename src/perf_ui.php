<?php
declare(strict_types=1);

const TABLE_METRICS = ['spend', 'impressions', 'reach', 'frequency', 'cpm', 'ctr', 'link_ctr', 'link_clicks', 'link_cpc', 'results', 'cost_per_result',
    'leads', 'cpl', 'purchases', 'cpa', 'purchase_value', 'roas', 'landing_page_views', 'lpv_rate', 'thruplays', 'hook_rate'];
const TABLE_HIDDEN = ['reach', 'frequency', 'ctr', 'link_clicks', 'lpv_rate', 'thruplays', 'hook_rate', 'clicks'];

/** Sortable, searchable, column-toggle table of entities (campaign/adset/ad). */
function perf_table(array $items, string $level, string $id, bool $linkCampaign = true): string {
    if (!$items) return empty_state('مفيش بيانات للفترة دي', ' جرّب فترة تانية، أو استورد بيانات من صفحة الاستيراد.');
    $sort = in_str('sort', 'spend'); $dir = in_str('dir', 'desc');
    if (in_array($sort, TABLE_METRICS, true)) usort($items, function ($a, $b) use ($sort, $dir) {
        $x = $a['m'][$sort]; $y = $b['m'][$sort];
        if ($x === null && $y === null) return 0; if ($x === null) return 1; if ($y === null) return -1;   // N/A always last
        return $dir === 'asc' ? $x <=> $y : $y <=> $x;
    });
    elseif ($sort === 'name') usort($items, fn($a, $b) => $dir === 'asc' ? strcmp($a['name'], $b['name']) : strcmp($b['name'], $a['name']));
    $multiCur = count(array_unique(array_column($items, 'currency'))) > 1;
    $h = '<div class="row" style="margin-bottom:8px"><input type="search" placeholder="بحث بالاسم…" data-search-for="' . h($id) . '" style="max-width:240px"><span class="sp"></span>' . col_picker($id) . '</div>';
    $h .= '<div class="tbl"><table class="t cards" id="' . h($id) . '" data-default-hidden="' . h(implode(',', TABLE_HIDDEN)) . '"><thead><tr>';
    $h .= '<th>' . sort_link('name', ['campaign' => 'الحملة', 'adset' => 'Ad Set', 'ad' => 'الإعلان'][$level]) . '</th><th data-col="goal" data-label="الهدف">الهدف</th>';
    if ($level !== 'campaign') $h .= '<th data-col="parent" data-label="الحملة">الحملة</th>';
    foreach (TABLE_METRICS as $k) $h .= '<th class="n" data-col="' . $k . '" data-label="' . h(metric_label($k)) . '" title="' . h(metric_defs()[$k][3]) . '">' . sort_link($k, metric_label($k)) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($items as $it) {
        $m = $it['m']; $cur = $it['currency'];
        $name = h($it['name']);
        if ($level === 'campaign' && $linkCampaign) $name = '<a href="' . h(url('campaign', ['id' => $it['id'], 'from' => in_str('from'), 'to' => in_str('to')])) . '">' . $name . '</a>';
        $st = strtoupper((string)$it['status']);
        $h .= '<tr><td class="main-cell">' . $name . ($st && $st !== 'ACTIVE' ? ' <span class="badge">' . h(strtolower($st)) . '</span>' : '') . ($multiCur ? ' <span class="badge ltr">' . h($cur) . '</span>' : '') . '</td>';
        $h .= '<td data-col="goal" data-l="الهدف"><span class="badge b-acc">' . h(goal_labels()[$it['goal']] ?? $it['goal']) . '</span></td>';
        if ($level !== 'campaign') $h .= '<td data-col="parent" data-l="الحملة" class="small">' . h($it['campaign_name']) . '</td>';
        foreach (TABLE_METRICS as $k) $h .= '<td class="n" data-col="' . $k . '" data-l="' . h(metric_label($k)) . '">' . fmt_metric($k, $m[$k] ?? null, $cur) . '</td>';
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/** KPI grid for one currency, with previous-period change. */
function kpi_grid(array $m, ?array $prev, string $cur, bool $showSales, bool $showLeads, bool $showVideo): string {
    $keys = ['spend', 'impressions', 'reach', 'frequency', 'cpm', 'link_ctr', 'ctr', 'link_cpc', 'results', 'cost_per_result'];
    if ($showLeads) array_push($keys, 'leads', 'cpl');
    if ($showSales) array_push($keys, 'purchases', 'cpa', 'purchase_value', 'roas');
    $keys[] = 'landing_page_views';
    if ($showVideo) array_push($keys, 'thruplays', 'hook_rate');
    $o = '<div class="kpis">';
    foreach ($keys as $k) {
        $v = $m[$k] ?? null; $p = $prev[$k] ?? null;
        $label = metric_label($k) . ($k === 'results' && ($m['result_type'] ?? null) && $m['result_type'] !== 'mixed' ? ' · ' . $m['result_type'] : '');
        if ($k === 'results' && ($m['result_type'] ?? null) === 'mixed') { $o .= kpi('Results', fmt_num(null), null, false, 'النتائج من أنواع مختلفة (ليدز، مشتريات، زيارات…) ومينفعش تتجمع. فلتر بالهدف الإعلاني عشان تشوفها.'); continue; }
        $o .= kpi($label, fmt_metric($k, $v, $cur), $prev !== null && $v !== null ? pct_change((float)$v, $p === null ? null : (float)$p) : null,
            metric_lower_better($k), metric_defs()[$k][3], $prev !== null && $p !== null && $v === null ? fmt_metric($k, $p, $cur) : '');
    }
    return $o . '</div>';
}

function any_metric(array $groups, array $keys): bool {
    foreach ($groups as $g) foreach ($keys as $k) if (($g['m'][$k] ?? null) !== null) return true;
    return false;
}

/** Chart spec for spend + a result metric over days. */
function daily_chart_spec(array $series, string $cur, string $resultKey): array {
    $labels = array_keys($series);
    $col = fn($k) => array_map(fn($m) => $m === null || $m[$k] === null ? null : round((float)$m[$k], 2), array_values($series));
    return ['type' => 'bar', 'labels' => array_map(fn($d) => substr($d, 5), $labels), 'series' => [
        ['name' => "Spend ($cur)", 'data' => $col('spend'), 'axis' => 'y', 'type' => 'bar'],
        ['name' => metric_label($resultKey), 'data' => $col($resultKey), 'axis' => 'y1', 'type' => 'line'],
    ]];
}
function cost_chart_spec(array $series, string $cur, string $costKey): array {
    $labels = array_keys($series);
    return ['type' => 'line', 'labels' => array_map(fn($d) => substr($d, 5), $labels), 'series' => [
        ['name' => metric_label($costKey) . " ($cur)", 'data' => array_map(fn($m) => $m === null || $m[$costKey] === null ? null : round((float)$m[$costKey], 2), array_values($series)), 'axis' => 'y'],
        ['name' => 'CPM', 'data' => array_map(fn($m) => $m === null || $m['cpm'] === null ? null : round((float)$m['cpm'], 2), array_values($series)), 'axis' => 'y1'],
    ]];
}

/** Hidden inputs that keep the current filters when submitting another form. */
function keep_filters(array $except = []): string {
    $o = '';
    foreach (['client', 'account', 'campaign', 'goal', 'from', 'to', 'level'] as $k) if (!in_array($k, $except, true) && in_str($k) !== '') $o .= '<input type="hidden" name="' . $k . '" value="' . h(in_str($k)) . '">';
    return $o;
}
function date_presets(string $route, array $extra = []): string {
    $t = today(); $o = '<div class="row small" style="gap:6px">';
    foreach (['7' => '7 أيام', '14' => '14 يوم', '30' => '30 يوم', '90' => '90 يوم'] as $d => $l) {
        $from = (new DateTimeImmutable($t))->modify('-' . ((int)$d - 1) . ' days')->format('Y-m-d');
        $o .= '<a class="btn sm ghost" href="' . h(url($route, array_merge($_GET, $extra, ['r' => null, 'from' => $from, 'to' => $t, 'page' => null]))) . '">' . $l . '</a>';
    }
    $m1 = (new DateTimeImmutable($t))->modify('first day of this month')->format('Y-m-d');
    $o .= '<a class="btn sm ghost" href="' . h(url($route, array_merge($_GET, $extra, ['r' => null, 'from' => $m1, 'to' => $t]))) . '">الشهر ده</a>';
    $pm1 = (new DateTimeImmutable($t))->modify('first day of last month')->format('Y-m-d'); $pm2 = (new DateTimeImmutable($t))->modify('last day of last month')->format('Y-m-d');
    return $o . '<a class="btn sm ghost" href="' . h(url($route, array_merge($_GET, $extra, ['r' => null, 'from' => $pm1, 'to' => $pm2]))) . '">الشهر اللي فات</a></div>';
}
