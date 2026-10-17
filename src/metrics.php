<?php
declare(strict_types=1);
/*
 * Pure KPI math. Rules:
 *  - null = data not available → never treated as 0
 *  - division by zero → null
 *  - reach is not additive (people overlap across days/entities) → only kept when exactly one row is summed
 */

const ADDITIVE = ['spend', 'impressions', 'clicks', 'link_clicks', 'results', 'leads', 'purchases', 'purchase_value',
    'landing_page_views', 'video_3s', 'thruplays', 'video_p25', 'video_p50', 'video_p75', 'video_p100'];

function safe_div($a, $b, float $mul = 1.0): ?float {
    if ($a === null || $b === null || (float)$b == 0.0) return null;
    return (float)$a / (float)$b * $mul;
}

/** Sum metric rows. Nulls are skipped; a field that is null in every row stays null. */
function sum_metrics(array $rows): array {
    $t = array_fill_keys(ADDITIVE, null);
    // spend that belongs to rows where the result was actually measured — so CPL isn't diluted by traffic campaigns
    // or by days whose lead count is unknown (and leads never get mixed with sales)
    $paired = ['leads' => '_spend_leads', 'purchases' => '_spend_purchases', 'purchase_value' => '_spend_value', 'results' => '_spend_results'];
    foreach ($paired as $sp) $t[$sp] = null;
    foreach ($rows as $r) {
        foreach (ADDITIVE as $f) {
            if (!array_key_exists($f, $r) || $r[$f] === null || $r[$f] === '') continue;
            $t[$f] = ($t[$f] ?? 0) + (float)$r[$f];
        }
        foreach ($paired as $f => $sp) {
            $have = array_key_exists($sp, $r) ? $r[$sp] !== null : (isset($r[$f]) && $r[$f] !== '' && $r[$f] !== null);
            if (!$have) continue;
            $t[$sp] = ($t[$sp] ?? 0) + (float)(array_key_exists($sp, $r) ? $r[$sp] : ($r['spend'] ?? 0));
        }
    }
    $t['reach'] = count($rows) === 1 && isset($rows[array_key_first($rows)]['reach']) && $rows[array_key_first($rows)]['reach'] !== null
        ? (float)$rows[array_key_first($rows)]['reach'] : null;
    $types = array_values(array_unique(array_filter(array_map(fn($r) => $r['result_type'] ?? null, $rows))));
    $t['result_type'] = count($types) === 1 ? $types[0] : (count($types) > 1 ? 'mixed' : null);
    $t['rows'] = count($rows);
    return $t;
}

/** Derived KPIs from summed totals. */
function derive(array $t): array {
    $g = fn($k) => $t[$k] ?? null;
    $d = $t;
    $d['cpm'] = safe_div($g('spend'), $g('impressions'), 1000);
    $d['ctr'] = safe_div($g('clicks'), $g('impressions'));
    $d['link_ctr'] = safe_div($g('link_clicks'), $g('impressions'));
    $d['cpc'] = safe_div($g('spend'), $g('clicks'));
    $d['link_cpc'] = safe_div($g('spend'), $g('link_clicks'));
    $ps = fn($k) => array_key_exists($k, $t) ? $t[$k] : $g('spend');
    $d['cost_per_result'] = ($t['result_type'] ?? null) === 'mixed' ? null : safe_div($ps('_spend_results'), $g('results'));
    $d['cpl'] = safe_div($ps('_spend_leads'), $g('leads'));
    $d['cpa'] = safe_div($ps('_spend_purchases'), $g('purchases'));
    $d['roas'] = safe_div($g('purchase_value'), $ps('_spend_value'));
    $d['frequency'] = safe_div($g('impressions'), $g('reach'));
    $d['lpv_rate'] = safe_div($g('landing_page_views'), $g('link_clicks'));
    $d['lead_cvr'] = safe_div($g('leads'), $g('link_clicks'));
    $d['purchase_cvr'] = safe_div($g('purchases'), $g('link_clicks'));
    $d['hook_rate'] = safe_div($g('video_3s'), $g('impressions'));
    $d['thruplay_rate'] = safe_div($g('thruplays'), $g('impressions'));
    $d['hold_rate'] = safe_div($g('video_p100'), $g('video_3s'));
    $d['cost_per_lpv'] = safe_div($g('spend'), $g('landing_page_views'));
    return $d;
}

/** Labels, formats and definitions shown in the UI (tooltip "how is this calculated"). */
function metric_defs(): array {
    return [
        'spend' => ['Amount Spent', 'money', false, 'إجمالي المبلغ اللي اتصرف.'],
        'impressions' => ['Impressions', 'int', false, 'عدد مرات ظهور الإعلانات.'],
        'reach' => ['Reach', 'int', false, 'عدد الأشخاص المختلفين. مش بيتجمع على أيام أو حملات لأن نفس الشخص ممكن يتعد أكتر من مرة، فبيظهر N/A إلا لو الفترة صف واحد.'],
        'frequency' => ['Frequency', 'ratio', true, 'Impressions ÷ Reach.'],
        'cpm' => ['CPM', 'money', true, 'Spend ÷ Impressions × 1000.'],
        'ctr' => ['CTR (all)', 'pct', false, 'Clicks (all) ÷ Impressions.'],
        'link_ctr' => ['Link CTR', 'pct', false, 'Link Clicks ÷ Impressions.'],
        'link_clicks' => ['Link Clicks', 'int', false, 'نقرات على اللينك.'],
        'clicks' => ['Clicks (all)', 'int', false, 'كل النقرات.'],
        'cpc' => ['CPC (all)', 'money', true, 'Spend ÷ Clicks (all).'],
        'link_cpc' => ['Link CPC', 'money', true, 'Spend ÷ Link Clicks.'],
        'results' => ['Results', 'num', false, 'النتيجة حسب هدف الحملة (نوعها في result_type). مينفعش تتجمع لو الأنواع مختلفة.'],
        'cost_per_result' => ['Cost per Result', 'money', true, 'Spend ÷ Results. N/A لو النتائج من أنواع مختلفة.'],
        'leads' => ['Leads', 'num', false, 'الليدز اللي سجلتها المنصة.'],
        'cpl' => ['CPL', 'money', true, 'Spend ÷ Leads — الصرف المحسوب بس من الحملات/الأيام اللي فيها عدد ليدز مسجّل، عشان صرف الترافيك أو المبيعات ميأثرش عليه.'],
        'purchases' => ['Purchases', 'num', false, 'عمليات الشراء اللي سجلها البكسل/المنصة.'],
        'cpa' => ['CPA', 'money', true, 'Spend ÷ Purchases — من الصفوف اللي فيها مشتريات مسجّلة بس.'],
        'purchase_value' => ['Purchase Value', 'money', false, 'قيمة المبيعات المسجلة.'],
        'roas' => ['ROAS', 'ratio', false, 'Purchase Value ÷ Spend — الصرف من الصفوف اللي فيها قيمة مبيعات مسجّلة بس.'],
        'landing_page_views' => ['Landing Page Views', 'int', false, 'مرات تحميل صفحة الهبوط بعد النقر.'],
        'lpv_rate' => ['LPV Rate', 'pct', false, 'Landing Page Views ÷ Link Clicks.'],
        'cost_per_lpv' => ['Cost per LPV', 'money', true, 'Spend ÷ Landing Page Views.'],
        'lead_cvr' => ['Lead CVR', 'pct', false, 'Leads ÷ Link Clicks.'],
        'purchase_cvr' => ['Purchase CVR', 'pct', false, 'Purchases ÷ Link Clicks.'],
        'video_3s' => ['3s Video Plays', 'int', false, 'تشغيل الفيديو 3 ثواني.'],
        'thruplays' => ['ThruPlays', 'int', false, 'مشاهدة 15 ثانية أو الفيديو كله لو أقصر.'],
        'hook_rate' => ['Hook Rate', 'pct', false, '3s Plays ÷ Impressions.'],
        'thruplay_rate' => ['ThruPlay Rate', 'pct', false, 'ThruPlays ÷ Impressions.'],
        'hold_rate' => ['Hold Rate (100%)', 'pct', false, 'مشاهدات 100% ÷ 3s Plays.'],
        'video_p25' => ['Video 25%', 'int', false, ''], 'video_p50' => ['Video 50%', 'int', false, ''],
        'video_p75' => ['Video 75%', 'int', false, ''], 'video_p100' => ['Video 100%', 'int', false, ''],
    ];
}
function metric_label(string $k): string { return metric_defs()[$k][0] ?? $k; }
function metric_lower_better(string $k): bool { return metric_defs()[$k][2] ?? false; }

/** Render a metric value as HTML according to its format. */
function fmt_metric(string $k, $v, ?string $cur): string {
    $f = metric_defs()[$k][1] ?? 'num';
    return match ($f) {
        'money' => fmt_money($v, $cur, in_array($k, ['spend', 'purchase_value'], true) ? 0 : 2),
        'pct' => fmt_pct($v),
        'ratio' => $v === null ? fmt_num(null) : number_format((float)$v, 2) . ($k === 'roas' ? 'x' : ''),
        'int' => fmt_num($v, 0),
        default => fmt_num($v, ($v !== null && floor((float)$v) != (float)$v) ? 1 : 0),
    };
}

/* ---------- objectives ---------- */
/** Collapse Meta objective names / result types into the goal used to judge performance. */
function goal_of(?string $objective, ?string $resultType = null): string {
    $o = strtoupper((string)$objective);
    if (preg_match('/LEAD/', $o)) return 'leads';
    if (preg_match('/SALES|CONVERSION|PRODUCT_CATALOG|PURCHASE/', $o)) return 'sales';
    if (preg_match('/TRAFFIC|LINK_CLICK/', $o)) return 'traffic';
    if (preg_match('/AWARENESS|REACH|BRAND/', $o)) return 'awareness';
    if (preg_match('/ENGAGEMENT|MESSAGE|VIDEO_VIEW|POST/', $o)) return 'engagement';
    if (preg_match('/APP/', $o)) return 'app';
    $rt = strtolower((string)$resultType);
    if (str_contains($rt, 'lead')) return 'leads';
    if (str_contains($rt, 'purchase')) return 'sales';
    if (str_contains($rt, 'link_click') || str_contains($rt, 'landing')) return 'traffic';
    return 'other';
}
function goal_labels(): array {
    return ['leads' => 'Leads', 'sales' => 'Sales', 'traffic' => 'Traffic', 'awareness' => 'Awareness', 'engagement' => 'Engagement', 'app' => 'App', 'other' => 'Other'];
}
/** The KPI that decides "best/worst" for a goal: [metric, lowerIsBetter]. */
function goal_kpi(string $goal): array {
    return match ($goal) {
        'leads' => ['cpl', true], 'sales' => ['roas', false], 'traffic' => ['link_cpc', true],
        'awareness' => ['cpm', true], default => ['cost_per_result', true],
    };
}

/**
 * Rank entities by their goal KPI. Entities below the minimum sample are excluded (small samples make noisy rankings).
 * Returns ['best' => [...], 'worst' => [...], 'excluded' => n]
 */
function rank_by_goal(array $items, string $goal, float $minSpend, int $n = 3): array {
    [$k, $lower] = goal_kpi($goal);
    $ok = []; $excluded = 0;
    foreach ($items as $it) {
        $m = $it['m'];
        if (($m['spend'] ?? 0) < $minSpend || $m[$k] === null) { $excluded++; continue; }
        $ok[] = $it;
    }
    usort($ok, fn($a, $b) => $lower ? $a['m'][$k] <=> $b['m'][$k] : $b['m'][$k] <=> $a['m'][$k]);
    $best = array_slice($ok, 0, $n);
    $worst = count($ok) > $n ? array_reverse(array_slice($ok, -min($n, count($ok) - $n))) : [];
    return ['best' => $best, 'worst' => $worst, 'kpi' => $k, 'excluded' => $excluded];
}

/** Previous period of the same length, ending the day before $from. */
function previous_period(string $from, string $to): array {
    $f = new DateTimeImmutable($from); $t = new DateTimeImmutable($to);
    $days = (int)$f->diff($t)->days + 1;
    return [$f->modify("-$days days")->format('Y-m-d'), $f->modify('-1 day')->format('Y-m-d')];
}
function date_range_days(string $from, string $to): array {
    $out = []; $d = new DateTimeImmutable($from); $end = new DateTimeImmutable($to);
    for ($i = 0; $d <= $end && $i < 1000; $i++, $d = $d->modify('+1 day')) $out[] = $d->format('Y-m-d');
    return $out;
}
