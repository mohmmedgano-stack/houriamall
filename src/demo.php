<?php
declare(strict_types=1);

/** Remove every demo client (cascade removes their accounts, campaigns, leads, budgets, reports…). */
function demo_clear(): int {
    $ids = q_col('SELECT id FROM clients WHERE is_demo = 1');
    foreach ($ids as $id) q('DELETE FROM clients WHERE id = ?', [$id]);
    q('DELETE FROM expenses WHERE is_demo = 1');
    audit('demo.clear', null, null, ['clients' => count($ids)]);
    return count($ids);
}

/** Build a realistic, clearly-labelled demo workspace. Deterministic (seeded) so tests are stable. */
function demo_seed(int $days = 60): int {
    demo_clear();
    mt_srand(20261018);
    $prevMode = mode(); set_mode('demo');   // demo clients are only in scope in demo mode
    try { return demo_seed_inner($days); } finally { set_mode($prevMode); }
}
function demo_seed_inner(int $days): int {
    $clients = [
        ['name' => 'شركة عقارات الساحل', 'industry' => 'عقارات', 'currency' => 'EGP', 'kind' => 'leads'],
        ['name' => 'متجر عطور أونلاين', 'industry' => 'تجارة إلكترونية', 'currency' => 'EGP', 'kind' => 'sales'],
        ['name' => 'Gulf Clinic', 'industry' => 'طبي', 'currency' => 'USD', 'kind' => 'leads'],
    ];
    $ids = [];
    tx(function () use ($clients, $days, &$ids) { foreach ($clients as $c) {
        $cid = client_create($c, true);
        $ids[] = $cid;
        $acc = account_save(null, $cid, ['name' => $c['name'] . ' — Ad Account', 'currency' => $c['currency'], 'platform' => 'meta']);
        if (function_exists('demo_seed_performance')) demo_seed_performance($cid, $acc, $c['kind'], $days);
        if (function_exists('demo_seed_business')) demo_seed_business($cid, $acc, $c['kind'], $days);
    } });
    foreach ($ids as $cid) {
        $cur = (string)q_val('SELECT currency FROM clients WHERE id = ?', [$cid]);
        $spent30 = (float)(totals_by_currency(perf_rows([$cid], (new DateTimeImmutable(today()))->modify('-29 days')->format('Y-m-d'), today()))[$cur]['m']['spend'] ?? 0);
        db_insert('budgets', ['client_id' => $cid, 'name' => 'ميزانية ' . ar_month(), 'period_start' => (new DateTimeImmutable(today()))->modify('first day of this month')->format('Y-m-d'),
            'period_end' => (new DateTimeImmutable(today()))->modify('last day of this month')->format('Y-m-d'), 'amount' => round($spent30 * 1.05, -2) ?: 1000, 'currency' => $cur,
            'target_metric' => 'cpl', 'target_cost' => $cur === 'USD' ? 20 : 220, 'alert_pct' => 85, 'created_at' => now()]);
    }
    // demo monitoring + one report so every screen has something to show
    if (function_exists('default_alert_rules')) {
        foreach ($ids as $cid) default_alert_rules($cid);
        $cid = $ids[0];
        alert_rule_save(null, ['client_id' => $cid, 'name' => 'CPL أعلى من المستهدف', 'metric' => 'cpl', 'operator' => 'gt', 'threshold' => 300, 'window_days' => 7, 'min_spend' => 1000, 'min_results' => 5, 'severity' => 'warning', 'active' => 1, 'cooldown_hours' => 24]);
        foreach (q_all('SELECT * FROM alert_rules WHERE client_id IN (' . in_list($ids) . ')') as $r) evaluate_rule($r);
        check_budget_alerts();
    }
    if (function_exists('report_build')) { [$f, $t] = report_default_period('weekly'); report_save(report_build('weekly', $ids[0], $f, $t)); }
    q('DELETE FROM events WHERE client_id IN (' . in_list($ids) . ')');
    audit('demo.seed', null, null, ['clients' => count($ids)]);
    return count($ids);
}
