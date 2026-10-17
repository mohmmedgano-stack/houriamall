<?php
declare(strict_types=1);

function audience_dimensions(array $clientIds): array {
    $keys = $clientIds ? q_col("SELECT DISTINCT ps.breakdown_key FROM performance_snapshots ps JOIN ad_accounts a ON a.id = ps.ad_account_id
        WHERE a.client_id IN (" . in_list($clientIds) . ") AND ps.breakdown_key <> ''") : [];
    $labels = ['age' => 'العمر', 'gender' => 'النوع', 'region' => 'المنطقة', 'country' => 'الدولة', 'placement' => 'Placement', 'platform' => 'المنصة', 'device' => 'الجهاز'];
    $out = ['adset' => 'Ad Set', 'audience_type' => 'نوع الجمهور (Broad / Interests / LAL / Custom)'];
    foreach ($keys as $k) $out['bd:' . $k] = implode(' × ', array_map(fn($x) => $labels[$x] ?? $x, explode(',', $k)));
    return $out;
}

/** Two-proportion z-score: is segment conversion rate different from the rest? */
function z_two_prop(float $x1, float $n1, float $x2, float $n2): ?float {
    if ($n1 < 1 || $n2 < 1) return null;
    $p = ($x1 + $x2) / ($n1 + $n2);
    $se = sqrt($p * (1 - $p) * (1 / $n1 + 1 / $n2));
    return $se > 0 ? (($x1 / $n1) - ($x2 / $n2)) / $se : null;
}

/**
 * Segment comparison. Returns segments with metrics, spend share, sample flags and significance vs the rest.
 * Only uses dimensions the platform actually reported — nothing is inferred.
 */
function audience_analyze(array $clientIds, string $dim, string $from, string $to, array $opt = []): array {
    if (str_starts_with($dim, 'bd:')) {
        $rows = perf_rows($clientIds, $from, $to, $opt + ['breakdown' => substr($dim, 3)]);
        $rows = pick_level_rows($rows, 'coarsest');
        $key = fn($r) => $r['currency'] . '|' . $r['breakdown_value'];
        $label = fn($r) => $r['breakdown_value'];
    } else {
        $rows = pick_level_rows(perf_rows($clientIds, $from, $to, $opt), 'adset');
        $rows = array_values(array_filter($rows, fn($r) => $r['adset_id'] !== null));
        $meta = $rows ? array_column(q_all('SELECT id, name, audience_type FROM adsets WHERE id IN (' . in_list(array_unique(array_column($rows, 'adset_id'))) . ')'), null, 'id') : [];
        if ($dim === 'audience_type') {
            $key = fn($r) => $r['currency'] . '|' . ($meta[$r['adset_id']]['audience_type'] ?: 'unknown');
            $label = fn($r) => ['broad' => 'Broad', 'interests' => 'Interests', 'lookalike' => 'Lookalike', 'custom' => 'Custom', 'unknown' => 'غير معروف'][$meta[$r['adset_id']]['audience_type'] ?: 'unknown'] ?? $meta[$r['adset_id']]['audience_type'];
        } else {
            $key = fn($r) => $r['currency'] . '|' . $r['adset_id'];
            $label = fn($r) => ($meta[$r['adset_id']]['name'] ?? '#' . $r['adset_id']) . ' — ' . $r['campaign_name'];
        }
    }
    $groups = aggregate($rows, $key);
    $byCur = [];
    foreach ($groups as $k => $g) { $g['label'] = $label($g['rows'][0]); unset($g['rows']); $byCur[$g['currency']][] = $g; }
    $out = [];
    foreach ($byCur as $cur => $segs) {
        $tot = derive(sum_metrics(array_map(fn($s) => $s['m'], $segs)));
        $conv = ($tot['leads'] ?? null) !== null ? 'leads' : (($tot['purchases'] ?? null) !== null ? 'purchases' : 'results');
        $costKey = $conv === 'leads' ? 'cpl' : ($conv === 'purchases' ? 'cpa' : 'cost_per_result');
        $minSpend = (float)($tot['spend'] ?? 0) * 0.05;
        foreach ($segs as &$s) {
            $m = $s['m'];
            $s['share'] = safe_div($m['spend'], $tot['spend']);
            $s['small'] = ($m['spend'] ?? 0) < $minSpend || ($m[$conv] ?? 0) < 5;
            $s['z'] = ($m['link_clicks'] && $tot['link_clicks'] && $m[$conv] !== null)
                ? z_two_prop((float)$m[$conv], (float)$m['link_clicks'], (float)$tot[$conv] - (float)$m[$conv], (float)$tot['link_clicks'] - (float)$m['link_clicks']) : null;
        }
        unset($s);
        $lower = metric_lower_better($costKey);
        usort($segs, fn($a, $b) => ($a['m'][$costKey] === null) <=> ($b['m'][$costKey] === null) ?: ($lower ? $a['m'][$costKey] <=> $b['m'][$costKey] : $b['m'][$costKey] <=> $a['m'][$costKey]));
        $out[$cur] = ['segments' => $segs, 'total' => $tot, 'conv' => $conv, 'cost' => $costKey, 'insights' => audience_insights($segs, $tot, $conv, $costKey, $cur)];
    }
    return $out;
}

/** Plain-language findings + test ideas. Hedged: past results don't guarantee future performance. */
function audience_insights(array $segs, array $tot, string $conv, string $costKey, string $cur): array {
    $ok = array_values(array_filter($segs, fn($s) => !$s['small'] && $s['m'][$costKey] !== null));
    $ins = [];
    if (count($ok) < 2) return [['info', 'مفيش شرائح كفاية بحجم عينة مناسب للمقارنة (محتاج صرف ≥ 5% من الإجمالي و 5 نتايج على الأقل لكل شريحة).']];
    $best = $ok[0]; $worst = $ok[count($ok) - 1];
    $avg = $tot[$costKey];
    $fmt = fn($v) => number_format((float)$v, 2) . " $cur";
    $sig = fn($s) => $s['z'] !== null && abs($s['z']) >= 1.96 ? 'والفرق في معدل التحويل واضح إحصائياً (z=' . number_format($s['z'], 1) . ')' : 'لكن الفرق مش حاسم إحصائياً لسه';
    $ins[] = ['good', "«{$best['label']}» أقل " . metric_label($costKey) . ' = ' . $fmt($best['m'][$costKey]) . ' مقابل متوسط ' . $fmt($avg) . '، ' . $sig($best) . '.'];
    $ins[] = ['bad', "«{$worst['label']}» الأعلى تكلفة = " . $fmt($worst['m'][$costKey]) . ' وواخد ' . round(($worst['share'] ?? 0) * 100) . '% من الصرف، ' . $sig($worst) . '.'];
    if (($best['share'] ?? 0) < 0.25) $ins[] = ['idea', "اقتراح اختبار: زوّد الصرف على «{$best['label']}» تدريجياً (مثلاً 20-30%) في Ad Set منفصل وقارن أسبوع. الأداء ده ممكن مايستمرش مع زيادة الصرف."];
    if (($worst['share'] ?? 0) > 0.2) $ins[] = ['idea', "اقتراح: جرّب تقلل أو تستبعد «{$worst['label']}»، أو اختبر كريتيف مختلف موجّه ليها قبل ما توقفها."];
    $ins[] = ['info', 'تنبيه: الشرائح ممكن تتداخل (نفس الشخص في أكتر من جمهور)، وظروف الاختبار (الكريتيف، التوقيت، الميزانية) مش متساوية، فالنتايج دي مؤشرات مش حقائق.'];
    return $ins;
}
