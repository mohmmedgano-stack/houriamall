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
    audit('demo.seed', null, null, ['clients' => count($ids)]);
    return count($ids);
}
