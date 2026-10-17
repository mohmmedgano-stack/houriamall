<?php
declare(strict_types=1);

/** Demo CRM leads (and, from Phase 6, fees/expenses) linked to the demo campaigns. */
function demo_seed_business(int $clientId, int $accountId, string $kind, int $days): void {
    if ($kind !== 'leads') { if (function_exists('demo_seed_finance')) demo_seed_finance($clientId, $kind); return; }
    $first = ['أحمد', 'محمد', 'مصطفى', 'سارة', 'منى', 'كريم', 'هبة', 'عمر', 'ياسمين', 'خالد', 'نورا', 'طارق', 'دينا', 'محمود', 'ريم'];
    $last = ['حسن', 'علي', 'إبراهيم', 'سعيد', 'عبد الله', 'فتحي', 'منصور', 'شريف', 'جمال', 'عادل'];
    $cities = ['القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة', 'طنطا'];
    $reasons = ['السعر', 'مش بيرد', 'مش مهتم', 'اشترى من منافس', 'بيانات غلط'];
    $camps = q_all("SELECT cp.id, cp.objective, (SELECT id FROM adsets WHERE campaign_id = cp.id LIMIT 1) adset FROM campaigns cp WHERE cp.ad_account_id = ? AND cp.objective LIKE '%LEAD%'", [$accountId]);
    $st = array_column(stages(), null, 'name');
    $cur = (string)q_val('SELECT currency FROM clients WHERE id = ?', [$clientId]);
    // CRM leads mirror what the platform reported (about 90% arrive — some forms get abandoned or blocked)
    $plan = [];
    foreach ($camps as $cp) {
        $byDay = q_all("SELECT ps.date_start d, ps.leads FROM performance_snapshots ps WHERE ps.level = 'campaign' AND ps.entity_id = ? AND ps.leads > 0", [$cp['id']]);
        foreach ($byDay as $r) for ($k = 0; $k < (int)round($r['leads'] * 0.9); $k++) $plan[] = [$cp, (int)((strtotime(today()) - strtotime($r['d'])) / 86400)];
    }
    $plan[] = [null, mt_rand(0, $days - 1)]; $plan[] = [null, mt_rand(0, $days - 1)];   // a couple without a campaign (walk-ins)
    foreach ($plan as $i => [$cp, $daysAgo]) {
        $recv = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400 - mt_rand(0, 80000));
        $phone = '010' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
        if ($i % 23 === 7 && $i > 0) $phone = (string)q_val('SELECT phone FROM leads WHERE client_id = ? ORDER BY id DESC LIMIT 1', [$clientId]) ?: $phone;   // a few duplicates
        $res = lead_create($clientId, ['full_name' => $first[mt_rand(0, 14)] . ' ' . $last[mt_rand(0, 9)], 'phone' => $phone, 'city' => $cities[mt_rand(0, 4)],
            'campaign_id' => $cp ? (int)$cp['id'] : null, 'form_name' => 'Demo form', 'received_at' => $recv, 'external_id' => "demo-$clientId-$i",
            'utm_source' => 'facebook', 'extra' => ['الميزانية' => ['أقل من مليون', '1-3 مليون', 'أكتر من 3 مليون'][mt_rand(0, 2)]]], $cp ? 'meta_form' : 'manual');
        if (!$res['id'] || $res['duplicate_of']) continue;
        $id = $res['id'];
        // the "Interests" campaign brings cheap leads that rarely qualify — so Lead Quality has something to find
        $weak = $cp && str_contains((string)q_val('SELECT name FROM campaigns WHERE id = ?', [$cp['id']]), 'Interests');
        $roll = mt_rand(1, 100);
        if ($daysAgo > 1 && $roll <= 82) {
            $contactAt = gmdate('Y-m-d H:i:s', strtotime($recv . ' UTC') + mt_rand(600, 36 * 3600));
            q('UPDATE leads SET first_contact_at = ?, stage_id = ? WHERE id = ?', [$contactAt, $st['Contacted']['id'], $id]);
            lead_activity($id, 'call', 'answered', 'مكالمة تعارف (بيانات تجريبية)', [], null);
            $q = $weak ? 8 : 60;
            if (mt_rand(1, 100) <= $q) {
                q('UPDATE leads SET qualified_at = ?, stage_id = ? WHERE id = ?', [$contactAt, $st[mt_rand(0, 1) ? 'Qualified' : 'Interested']['id'], $id]);
                if (mt_rand(1, 100) <= 45) q('UPDATE leads SET appointment_at = ? WHERE id = ?', [$contactAt, $id]);
                $o = mt_rand(1, 100);
                if ($o <= 22) q('UPDATE leads SET stage_id = ?, won_at = ?, deal_value = ?, deal_currency = ? WHERE id = ?', [$st['Won']['id'], $contactAt, mt_rand(8, 40) * ($cur === 'USD' ? 100 : 10000), $cur, $id]);
                elseif ($o <= 40) q('UPDATE leads SET stage_id = ? WHERE id = ?', [$st['Negotiation']['id'], $id]);
                elseif ($o <= 60) q('UPDATE leads SET stage_id = ?, lost_at = ?, lost_reason = ? WHERE id = ?', [$st['Lost']['id'], $contactAt, $reasons[mt_rand(0, 1)], $id]);
            } elseif (mt_rand(1, 100) <= 50) q('UPDATE leads SET stage_id = ?, lost_at = ?, lost_reason = ? WHERE id = ?', [$st['Lost']['id'], $contactAt, $reasons[mt_rand(2, 4)], $id]);
        }
        $s = (int)q_val('SELECT stage_id FROM leads WHERE id = ?', [$id]);
        // open leads: most have an upcoming follow-up, a few are overdue (so the overdue view has realistic content)
        if (!stage($s)['is_won'] && !stage($s)['is_lost'] && $daysAgo < 21) q('UPDATE leads SET next_follow_up_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + (mt_rand(1, 10) === 1 ? -mt_rand(3600, 3 * 86400) : mt_rand(3600, 5 * 86400))), $id]);
    }
    q("DELETE FROM events WHERE client_id = ?", [$clientId]);   // demo history shouldn't trigger automations
    if (function_exists('demo_seed_finance')) demo_seed_finance($clientId, $kind);
}

/** Demo fees, invoices and expenses so the profit tracker has history. */
function demo_seed_finance(int $clientId, string $kind): void {
    if (q_val('SELECT 1 FROM client_services WHERE client_id = ?', [$clientId])) return;
    $cur = (string)q_val('SELECT currency FROM clients WHERE id = ?', [$clientId]);
    $fee = $cur === 'USD' ? 600 : ($kind === 'sales' ? 12000 : 18000);
    $start = (new DateTimeImmutable(today()))->modify('first day of this month')->modify('-5 months');
    $sid = db_insert('client_services', ['client_id' => $clientId, 'name' => 'إدارة الحملات الإعلانية', 'fee_type' => 'monthly', 'amount' => $fee, 'currency' => $cur,
        'start_date' => $start->format('Y-m-d'), 'billing_day' => 1, 'active' => 1, 'created_at' => now()]);
    for ($i = 0; $i < 6; $i++) {
        $m = $start->modify("+$i months"); $issue = $m->format('Y-m-01');
        $paid = $i < 5 ? ($i === 4 && $kind === 'sales' ? $fee / 2 : $fee) : 0;
        db_insert('client_invoices', ['client_id' => $clientId, 'service_id' => $sid, 'number' => 'INV-' . $m->format('Ym') . '-S' . $sid, 'description' => 'إدارة الحملات — ' . ar_month($issue),
            'amount' => $fee, 'currency' => $cur, 'issue_date' => $issue, 'due_date' => $m->modify('+7 days')->format('Y-m-d'), 'paid_amount' => $paid,
            'paid_at' => $paid ? $m->modify('+5 days')->format('Y-m-d') : null, 'status' => $paid >= $fee ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'), 'created_at' => now()]);
        if ($cur === 'EGP') db_insert('expenses', ['client_id' => $clientId, 'category' => 'content', 'vendor' => 'تصوير', 'amount' => 2500, 'currency' => 'EGP', 'date' => $m->format('Y-m-10'), 'is_demo' => 1, 'created_at' => now()]);
    }
    if (!q_val('SELECT 1 FROM expenses WHERE client_id IS NULL AND is_demo = 1'))
        for ($i = 0; $i < 6; $i++) foreach ([['tools', 'Canva', 450], ['ai', 'AI tools', 1500], ['hosting', 'Hostinger', 300]] as [$cat, $v, $a])
            db_insert('expenses', ['client_id' => null, 'category' => $cat, 'vendor' => $v, 'amount' => $a, 'currency' => 'EGP', 'date' => $start->modify("+$i months")->format('Y-m-05'), 'is_demo' => 1, 'created_at' => now()]);
}
