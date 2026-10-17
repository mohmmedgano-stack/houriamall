<?php
declare(strict_types=1);

function budget_save(?int $id, array $in): int {
    $clientId = (int)($in['client_id'] ?? 0);
    if (!can_client($clientId)) throw new Forbidden('client');
    $row = [
        'client_id' => $clientId,
        'ad_account_id' => (int)($in['ad_account_id'] ?? 0) ?: null,
        'campaign_id' => (int)($in['campaign_id'] ?? 0) ?: null,
        'name' => trim((string)($in['name'] ?? '')),
        'period_start' => (string)($in['period_start'] ?? ''), 'period_end' => (string)($in['period_end'] ?? ''),
        'amount' => parse_num((string)($in['amount'] ?? '')),
        'currency' => strtoupper(trim((string)($in['currency'] ?? ''))),
        'target_metric' => in_array($in['target_metric'] ?? '', ['cpl', 'cpa'], true) ? $in['target_metric'] : null,
        'target_cost' => parse_num((string)($in['target_cost'] ?? '')),
        'target_results' => parse_num((string)($in['target_results'] ?? '')),
        'alert_pct' => parse_num((string)($in['alert_pct'] ?? '90')) ?? 90,
        'notes' => trim((string)($in['notes'] ?? '')),
    ];
    if ($row['name'] === '') throw new InvalidArgumentException('اسم الميزانية مطلوب');
    if (!valid_date($row['period_start']) || !valid_date($row['period_end']) || $row['period_end'] < $row['period_start']) throw new InvalidArgumentException('تواريخ الفترة مش صحيحة');
    if ($row['amount'] === null || $row['amount'] <= 0) throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر');
    if ($row['alert_pct'] <= 0 || $row['alert_pct'] > 200) throw new InvalidArgumentException('نسبة التنبيه بين 1 و 200');
    if ($row['ad_account_id']) {
        $acc = q_one('SELECT * FROM ad_accounts WHERE id = ?', [$row['ad_account_id']]);
        if (!$acc || (int)$acc['client_id'] !== $clientId) throw new InvalidArgumentException('الحساب مش تبع العميل ده');
        $row['currency'] = $acc['currency'];
    }
    if ($row['campaign_id']) {
        if (campaign_client_id($row['campaign_id']) !== $clientId) throw new InvalidArgumentException('الحملة مش تبع العميل ده');
        $acc = q_one('SELECT a.* FROM campaigns c JOIN ad_accounts a ON a.id = c.ad_account_id WHERE c.id = ?', [$row['campaign_id']]);
        $row['ad_account_id'] = (int)$acc['id']; $row['currency'] = $acc['currency'];
    }
    if (!preg_match('/^[A-Z]{3}$/', $row['currency'])) $row['currency'] = (string)q_val('SELECT currency FROM clients WHERE id = ?', [$clientId]);
    if ($id) { db_update('budgets', $id, $row + ['updated_at' => now()]); audit('budget.update', 'budget', $id); return $id; }
    $id = db_insert('budgets', $row + ['created_at' => now()]);
    audit('budget.create', 'budget', $id, ['amount' => $row['amount'], 'currency' => $row['currency']]);
    return $id;
}

/**
 * Actual vs plan for one budget. Actuals come from the performance data; projections are labelled estimates.
 * $asOf lets tests pin "today".
 */
function budget_status(array $b, ?string $asOf = null): array {
    $asOf ??= today();
    $start = $b['period_start']; $end = $b['period_end'];
    $last = min($asOf, $end);
    $totalDays = (int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    $elapsed = $last < $start ? 0 : (int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($last))->days + 1;
    $remainingDays = max(0, $totalDays - $elapsed);
    $rows = $elapsed ? perf_rows([(int)$b['client_id']], $start, $last, ['account' => $b['ad_account_id'], 'campaign' => $b['campaign_id']]) : [];
    $rows = array_values(array_filter($rows, fn($r) => $r['currency'] === $b['currency']));   // never mix currencies
    $m = derive(sum_metrics(pick_level_rows($rows, 'coarsest')));
    $spent = (float)($m['spend'] ?? 0);
    $resultKey = ($b['target_metric'] ?? null) === 'cpa' ? 'purchases' : 'leads';
    $results = $m[$resultKey];
    $avgDaily = $elapsed ? $spent / $elapsed : null;
    $projectedSpend = $avgDaily !== null ? $spent + $avgDaily * $remainingDays : null;
    $plannedByNow = (float)$b['amount'] * ($elapsed / max(1, $totalDays));
    $cpr = safe_div($spent, $results);
    $s = [
        'spent' => $spent, 'pct_used' => safe_div($spent, $b['amount']), 'remaining' => (float)$b['amount'] - $spent,
        'elapsed_days' => $elapsed, 'total_days' => $totalDays, 'remaining_days' => $remainingDays,
        'avg_daily' => $avgDaily, 'needed_daily' => $remainingDays ? max(0, ((float)$b['amount'] - $spent) / $remainingDays) : null,
        'projected_spend' => $projectedSpend, 'planned_by_now' => $plannedByNow, 'pace' => safe_div($spent, $plannedByNow),
        'result_key' => $resultKey, 'results' => $results, 'cost_per_result' => $cpr,
        'projected_results' => ($cpr && $projectedSpend !== null) ? $results + ($projectedSpend - $spent) / $cpr : null,
        'target_results' => $b['target_results'] !== null ? (float)$b['target_results'] : ($b['target_cost'] ? (float)$b['amount'] / (float)$b['target_cost'] : null),
        'has_data' => (bool)$rows,
    ];
    $alerts = [];
    if ($s['pct_used'] !== null && $s['pct_used'] * 100 >= (float)$b['alert_pct']) $alerts[] = ['critical', 'اتصرف ' . round($s['pct_used'] * 100) . '% من الميزانية (حد التنبيه ' . (float)$b['alert_pct'] . '%)'];
    if ($projectedSpend !== null && $projectedSpend > (float)$b['amount'] * 1.05 && $remainingDays > 0) $alerts[] = ['warning', 'بالمعدل الحالي الصرف المتوقع هيتخطى الميزانية'];
    if ($b['target_cost'] && $cpr !== null && $cpr > (float)$b['target_cost'] * 1.2 && ($results ?? 0) >= 5) $alerts[] = ['warning', 'التكلفة الفعلية لكل نتيجة أعلى من المستهدف بأكتر من 20%'];
    $s['alerts'] = $alerts;
    return $s;
}

/**
 * Budget scenarios. Assumption (shown to the user): cost per result rises as spend scales —
 * new CPR = CPR × (new daily / current daily) ^ elasticity. Elasticity 0 would mean linear scaling, which rarely holds.
 */
function budget_scenarios(array $b, array $s, float $elasticity = 0.3, array $changes = [-0.3, -0.15, 0.15, 0.3, 0.5]): array {
    if (!$s['cost_per_result'] || !$s['avg_daily'] || !$s['remaining_days']) return [];
    $out = [];
    foreach ($changes as $c) {
        $daily = $s['avg_daily'] * (1 + $c);
        $cpr = $s['cost_per_result'] * (($daily / $s['avg_daily']) ** $elasticity);
        $restSpend = $daily * $s['remaining_days'];
        $out[] = ['change' => $c, 'daily' => $daily, 'total_spend' => $s['spent'] + $restSpend, 'cpr' => $cpr,
            'results' => ($s['results'] ?? 0) + $restSpend / $cpr, 'over_budget' => $s['spent'] + $restSpend > (float)$b['amount']];
    }
    return $out;
}

function budgets_for(array $clientIds): array {
    return $clientIds ? q_all('SELECT b.*, c.name client_name, cp.name campaign_name, a.name account_name FROM budgets b JOIN clients c ON c.id = b.client_id
        LEFT JOIN campaigns cp ON cp.id = b.campaign_id LEFT JOIN ad_accounts a ON a.id = b.ad_account_id
        WHERE b.client_id IN (' . in_list($clientIds) . ') ORDER BY b.period_end DESC, c.name') : [];
}
