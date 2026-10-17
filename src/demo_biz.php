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
        if (!stage($s)['is_won'] && !stage($s)['is_lost']) q('UPDATE leads SET next_follow_up_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + mt_rand(-3, 4) * 86400 + mt_rand(0, 40000)), $id]);
    }
    q("DELETE FROM events WHERE client_id = ?", [$clientId]);   // demo history shouldn't trigger automations
    if (function_exists('demo_seed_finance')) demo_seed_finance($clientId, $kind);
}
