<?php
declare(strict_types=1);

/*
 * Lead Quality = ad spend joined with what happened to the leads in the CRM.
 * Attribution rule (shown in the UI): a lead/sale is credited to the campaign / ad set / ad recorded on the lead itself
 * (from the Meta lead form, the import file, or chosen by hand). Leads with no campaign are reported as "unattributed".
 * Revenue only counts toward ROAS when the deal currency equals the ad account currency.
 */
function lq_dimensions(): array {
    return ['campaign' => 'الحملة', 'adset' => 'Ad Set', 'ad' => 'الإعلان', 'creative' => 'الكريتيف', 'client' => 'العميل', 'city' => 'المدينة/المنطقة', 'product' => 'المنتج', 'source' => 'المصدر'];
}

function lead_quality(array $clientIds, string $dim, string $from, string $to): array {
    if (!$clientIds || !isset(lq_dimensions()[$dim])) return ['rows' => [], 'total' => null];
    $keyExpr = ['campaign' => 'l.campaign_id', 'adset' => 'l.adset_id', 'ad' => 'l.ad_id', 'creative' => 'd.creative_asset_id', 'client' => 'l.client_id',
        'city' => "COALESCE(NULLIF(TRIM(l.city), ''), '—')", 'product' => "COALESCE(NULLIF(TRIM(l.product), ''), '—')", 'source' => 'l.source'][$dim];
    $leads = q_all("SELECT $keyExpr k, l.*, s.is_contacted s_contacted, s.is_qualified s_qualified, s.is_won s_won, s.is_lost s_lost
        FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id LEFT JOIN ads d ON d.id = l.ad_id
        WHERE l.client_id IN (" . in_list($clientIds) . ") AND l.duplicate_of IS NULL AND l.received_at BETWEEN ? AND ?", [$from . ' 00:00:00', $to . ' 23:59:59']);
    $dupCount = (int)q_val('SELECT COUNT(*) FROM leads WHERE client_id IN (' . in_list($clientIds) . ') AND duplicate_of IS NOT NULL AND received_at BETWEEN ? AND ?', [$from . ' 00:00:00', $to . ' 23:59:59']);

    // spend per key from performance data (only for ad-structure dimensions)
    $spend = []; $platformLeads = [];
    if (in_array($dim, ['campaign', 'adset', 'ad', 'creative', 'client'], true)) {
        $rows = perf_rows($clientIds, $from, $to);
        $lvl = in_array($dim, ['adset', 'ad', 'creative'], true) ? ($dim === 'creative' ? 'ad' : $dim) : 'coarsest';
        $picked = pick_level_rows($rows, $lvl);
        $adCr = [];
        if ($dim === 'creative' && $picked) $adCr = array_column(q_all('SELECT id, creative_asset_id FROM ads WHERE id IN (' . in_list(array_filter(array_column($picked, 'ad_id'))) . ')'), 'creative_asset_id', 'id');
        foreach ($picked as $r) {
            $k = match ($dim) { 'campaign' => $r['campaign_id'], 'adset' => $r['adset_id'], 'ad' => $r['ad_id'], 'creative' => $adCr[$r['ad_id']] ?? null, 'client' => $r['client_id'] };
            if ($k === null) continue;
            $spend[$k][$r['currency']] = ($spend[$k][$r['currency']] ?? 0) + (float)$r['spend'];
            if ($r['leads'] !== null) $platformLeads[$k] = ($platformLeads[$k] ?? 0) + (float)$r['leads'];
        }
    }
    $groups = [];
    foreach ($leads as $l) $groups[(string)($l['k'] ?? '')][] = $l;
    foreach (array_keys($spend) as $k) $groups[(string)$k] ??= [];
    $labels = lq_labels($dim, array_keys($groups));
    $out = []; $all = [];
    foreach ($groups as $k => $ls) {
        $r = lq_compute($ls, $spend[$k] ?? [], $k === '' ? null : ($platformLeads[$k] ?? null));
        $r['key'] = $k; $r['label'] = $k === '' ? 'غير منسوب (مفيش حملة على الليد)' : ($labels[$k] ?? $k);
        $out[] = $r; array_push($all, ...$ls);
    }
    $allSpend = [];
    foreach ($spend as $byCur) foreach ($byCur as $c => $v) $allSpend[$c] = ($allSpend[$c] ?? 0) + $v;
    $total = lq_compute($all, $allSpend, null);
    // flag sources bringing many low-quality leads: ≥ 10 leads and qualified rate under half the overall rate
    foreach ($out as &$r) $r['low_quality'] = $r['total'] >= 10 && $total['qualified_rate'] !== null && $r['qualified_rate'] !== null && $r['qualified_rate'] < $total['qualified_rate'] / 2;
    unset($r);
    usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
    return ['rows' => $out, 'total' => $total, 'duplicates' => $dupCount];
}

/** Funnel + cost metrics for a group of leads and its spend (per currency). */
function lq_compute(array $ls, array $spendByCur, ?float $platformLeads): array {
    $n = count($ls);
    $contacted = count(array_filter($ls, fn($l) => $l['first_contact_at'] || $l['s_contacted']));
    $qualified = count(array_filter($ls, fn($l) => $l['qualified_at'] || $l['s_qualified']));
    $appts = count(array_filter($ls, fn($l) => $l['appointment_at']));
    $won = array_values(array_filter($ls, fn($l) => (int)$l['s_won'] === 1));
    $lost = array_values(array_filter($ls, fn($l) => (int)$l['s_lost'] === 1));
    $revenue = [];
    foreach ($won as $l) if ($l['deal_value'] !== null) $revenue[$l['deal_currency'] ?: '?'] = ($revenue[$l['deal_currency'] ?: '?'] ?? 0) + (float)$l['deal_value'];
    $ttc = array_filter(array_map(fn($l) => $l['first_contact_at'] ? (strtotime($l['first_contact_at']) - strtotime($l['received_at'])) / 3600 : null, $ls), fn($x) => $x !== null && $x >= 0);
    sort($ttc);
    $reasons = [];
    foreach ($lost as $l) { $k = $l['lost_reason'] ?: 'غير محدد'; $reasons[$k] = ($reasons[$k] ?? 0) + 1; }
    arsort($reasons);
    $cur = count($spendByCur) === 1 ? array_key_first($spendByCur) : null;
    $sp = $cur ? $spendByCur[$cur] : null;
    $rev = null;                                   // unknown unless we can say it in the spend currency
    if ($cur !== null) { if (!$won) $rev = 0.0; elseif (isset($revenue[$cur])) $rev = $revenue[$cur]; }
    return ['total' => $n, 'contacted' => $contacted, 'qualified' => $qualified, 'appointments' => $appts, 'won' => count($won), 'lost' => count($lost),
        'contact_rate' => safe_div($contacted, $n), 'qualified_rate' => safe_div($qualified, $n), 'appointment_rate' => safe_div($appts, $n), 'sales_rate' => safe_div(count($won), $n),
        'spend' => $sp, 'currency' => $cur, 'spend_by_currency' => $spendByCur, 'cpl_crm' => safe_div($sp, $n), 'cost_per_qualified' => safe_div($sp, $qualified),
        'cost_per_sale' => safe_div($sp, count($won)), 'revenue' => $revenue, 'roas' => ($sp && $rev !== null) ? safe_div($rev, $sp) : null,
        'platform_leads' => $platformLeads, 'ttc_avg' => $ttc ? array_sum($ttc) / count($ttc) : null, 'ttc_median' => $ttc ? $ttc[intdiv(count($ttc), 2)] : null,
        'never_contacted' => $n - $contacted, 'lost_reasons' => $reasons,
        'won_without_value' => count(array_filter($won, fn($l) => $l['deal_value'] === null))];
}

function lq_labels(string $dim, array $keys): array {
    $ids = array_filter(array_map('intval', $keys));
    return match ($dim) {
        'campaign' => $ids ? array_column(q_all('SELECT id, name FROM campaigns WHERE id IN (' . in_list($ids) . ')'), 'name', 'id') : [],
        'adset' => $ids ? array_column(q_all('SELECT s.id, s.name || \' — \' || c.name name FROM adsets s JOIN campaigns c ON c.id = s.campaign_id WHERE s.id IN (' . in_list($ids) . ')'), 'name', 'id') : [],
        'ad' => $ids ? array_column(q_all('SELECT id, name FROM ads WHERE id IN (' . in_list($ids) . ')'), 'name', 'id') : [],
        'creative' => $ids ? array_column(q_all("SELECT id, COALESCE(name, title, 'كريتيف #' || id) name FROM creative_assets WHERE id IN (" . in_list($ids) . ')'), 'name', 'id') : [],
        'client' => client_names($ids),
        'source' => ['meta_form' => 'Meta Lead Ads', 'import' => 'استيراد', 'manual' => 'يدوي'],
        default => [],
    };
}
