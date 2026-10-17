<?php
declare(strict_types=1);

/** Demo performance data: campaigns → ad sets → ads with daily rows, creatives and audience breakdowns. */
function demo_seed_performance(int $clientId, int $accountId, string $kind, int $days): void {
    $cur = (string)q_val('SELECT currency FROM ad_accounts WHERE id = ?', [$accountId]);
    $fx = $cur === 'USD' ? 0.1 : 1.0;   // scale money for USD accounts
    $rnd = fn(float $a, float $b) => $a + (mt_rand() / mt_getrandmax()) * ($b - $a);
    $plans = $kind === 'sales'
        ? [['Sales | Prospecting | Broad', 'OUTCOME_SALES', 1.0], ['Sales | Retargeting 30d', 'OUTCOME_SALES', 1.6], ['Traffic | Blog', 'OUTCOME_TRAFFIC', 0.8], ['Awareness | Ramadan teaser', 'OUTCOME_AWARENESS', 0.7]]
        : [['Leads | Instant Form | Broad', 'OUTCOME_LEADS', 1.0], ['Leads | Interests | Investors', 'OUTCOME_LEADS', 0.7], ['Leads | Lookalike 1%', 'OUTCOME_LEADS', 1.3], ['Traffic | Landing page', 'OUTCOME_TRAFFIC', 0.9]];
    $audiences = [['Broad 25-55', 'broad'], ['Interests — Real estate', 'interests'], ['Lookalike 1% buyers', 'lookalike'], ['Custom — site visitors 30d', 'custom']];
    $hooks = [['سؤال مباشر', 'السعر', 'فيديو'], ['شهادة عميل', 'الثقة', 'فيديو'], ['عرض محدود', 'العرض', 'صورة'], ['قبل وبعد', 'النتيجة', 'كاروسيل'], ['مشكلة وحل', 'الألم', 'فيديو']];
    $regions = ['Cairo', 'Giza', 'Alexandria', 'Dakahlia']; $ages = ['18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
    $placements = ['facebook_feed', 'instagram_feed', 'instagram_reels', 'facebook_reels'];
    $end = new DateTimeImmutable(today());
    $ci = 0;
    foreach ($plans as [$cname, $objective, $quality]) {
        $ci++;
        $paused = $ci === 4 && $kind !== 'sales';
        $campId = upsert_campaign($accountId, "demo-$accountId-$ci", ['name' => $cname, 'objective' => $objective, 'status' => $paused ? 'PAUSED' : 'ACTIVE',
            'effective_status' => $paused ? 'PAUSED' : 'ACTIVE', 'daily_budget' => round(600 * $quality * $fx, 2), 'start_time' => $end->modify('-' . ($days - 1) . ' days')->format('Y-m-d')], 'demo');
        $goal = goal_of($objective);
        for ($si = 0; $si < 2; $si++) {
            [$aname, $atype] = $audiences[($ci + $si) % 4];
            $adsetId = upsert_adset($campId, "demo-$campId-$si", ['name' => $aname, 'audience_type' => $atype, 'status' => 'ACTIVE',
                'optimization_goal' => $goal === 'leads' ? 'LEAD_GENERATION' : ($goal === 'sales' ? 'OFFSITE_CONVERSIONS' : 'LINK_CLICKS'),
                'targeting_json' => json_encode(['geo_locations' => ['countries' => ['EG']], 'age_min' => 25, 'age_max' => 55])], 'demo');
            $adQ = [];
            for ($ai = 0; $ai < 3; $ai++) {
                [$hook, $angle, $type] = $hooks[($ci + $si + $ai) % 5];
                $q = $quality * [1.15, 0.95, 0.6][$ai];   // the third ad is a weak one
                $cr = db_insert('creative_assets', ['client_id' => $clientId, 'ad_account_id' => $accountId, 'name' => "$hook — $angle", 'type' => $type === 'فيديو' ? 'video' : ($type === 'صورة' ? 'image' : 'carousel'),
                    'body' => "إعلان تجريبي بزاوية «{$angle}» وبيبدأ بـ «{$hook}».", 'title' => $angle === 'العرض' ? 'خصم لفترة محدودة' : 'اعرف التفاصيل', 'cta' => $goal === 'leads' ? 'SIGN_UP' : 'SHOP_NOW',
                    'hook_type' => $hook, 'angle' => $angle, 'audience_label' => $aname, 'product' => $kind === 'sales' ? 'عطور' : 'وحدات سكنية', 'in_library' => $ai === 0 ? 1 : 0, 'created_at' => now()]);
                $adId = upsert_ad($adsetId, "demo-$adsetId-$ai", ['name' => "Ad $ai · $hook", 'status' => 'ACTIVE', 'creative_asset_id' => $cr], 'demo');
                $adQ[$adId] = [$q, $type === 'فيديو'];
            }
            $adsetDays = [];
            for ($d = $days - 1; $d >= 0; $d--) {
                $date = $end->modify("-$d days")->format('Y-m-d');
                if ($paused && $d < 10) continue;
                $trend = 1 + (($days - $d) / $days) * 0.25;   // spend grows over time
                foreach ($adQ as $adId => [$q, $video]) {
                    $spend = round($rnd(90, 260) * $trend * $fx, 2);
                    $cpm = $rnd(28, 55) * $fx / max(0.5, $q * 0.9);
                    $imp = (int)round($spend / $cpm * 1000);
                    $lctr = $rnd(0.008, 0.022) * $q;
                    $link = (int)round($imp * $lctr);
                    $clicks = (int)round($link * $rnd(1.4, 1.9));
                    $row = ['spend' => $spend, 'impressions' => $imp, 'clicks' => $clicks, 'link_clicks' => $link,
                        'landing_page_views' => $goal === 'leads' && $ci !== 4 ? null : (int)round($link * $rnd(0.6, 0.85)),
                        'leads' => null, 'purchases' => null, 'purchase_value' => null, 'results' => null, 'result_type' => null,
                        'video_3s' => $video ? (int)round($imp * $rnd(0.18, 0.34) * $q) : null, 'thruplays' => $video ? (int)round($imp * $rnd(0.05, 0.11) * $q) : null];
                    if ($video) { $row['video_p25'] = (int)round($row['video_3s'] * 0.55); $row['video_p50'] = (int)round($row['video_3s'] * 0.33); $row['video_p75'] = (int)round($row['video_3s'] * 0.21); $row['video_p100'] = (int)round($row['video_3s'] * 0.12); }
                    if ($goal === 'leads') { $row['leads'] = (float)(int)round($link * $rnd(0.008, 0.018) * $q); $row['results'] = $row['leads']; $row['result_type'] = 'lead'; }
                    elseif ($goal === 'sales') { $row['purchases'] = (float)(int)round($link * $rnd(0.012, 0.03) * $q); $row['purchase_value'] = round($row['purchases'] * $rnd(700, 1200) * $fx, 2); $row['results'] = $row['purchases']; $row['result_type'] = 'purchase'; }
                    elseif ($goal === 'traffic') { $row['results'] = (float)$row['landing_page_views']; $row['result_type'] = 'landing_page_view'; }
                    else { $row['results'] = (float)(int)round($imp / 1.6); $row['result_type'] = 'reach'; }
                    upsert_snapshot($row + ['ad_account_id' => $accountId, 'level' => 'ad', 'entity_id' => $adId, 'date_start' => $date, 'date_end' => $date, 'currency' => $cur, 'source' => 'demo']);
                    $adsetDays[$date][] = $row;
                }
            }
            // audience breakdowns at ad set level, one daily row per segment
            foreach ($adsetDays as $date => $rs) {
                $t = sum_metrics($rs);
                foreach ([['age', $ages, [0.08, 0.30, 0.28, 0.18, 0.10, 0.06]], ['gender', ['male', 'female'], [0.58, 0.42]],
                          ['region', $regions, [0.42, 0.24, 0.20, 0.14]], ['placement', $placements, [0.38, 0.27, 0.22, 0.13]]] as [$bk, $vals, $w]) {
                    foreach ($vals as $i => $v) {
                        $eff = $bk === 'age' ? [0.7, 1.1, 1.2, 1.0, 0.8, 0.5][$i] : ($bk === 'region' ? [1.1, 1.0, 0.9, 1.25][$i] : ($bk === 'placement' ? [1.0, 0.9, 1.2, 0.8][$i] : [1.0, 1.0][$i]));
                        $sh = $w[$i];
                        $seg = ['spend' => round(($t['spend'] ?? 0) * $sh, 2), 'impressions' => (int)round(($t['impressions'] ?? 0) * $sh), 'clicks' => (int)round(($t['clicks'] ?? 0) * $sh),
                            'link_clicks' => (int)round(($t['link_clicks'] ?? 0) * $sh * $eff), 'leads' => $t['leads'] === null ? null : round($t['leads'] * $sh * $eff),
                            'purchases' => $t['purchases'] === null ? null : round($t['purchases'] * $sh * $eff), 'purchase_value' => $t['purchase_value'] === null ? null : round($t['purchase_value'] * $sh * $eff, 2),
                            'results' => $t['results'] === null ? null : round($t['results'] * $sh * $eff), 'result_type' => $t['result_type']];
                        upsert_snapshot($seg + ['ad_account_id' => $accountId, 'level' => 'adset', 'entity_id' => $adsetId, 'date_start' => $date, 'date_end' => $date,
                            'breakdown_key' => $bk, 'breakdown_value' => $v, 'currency' => $cur, 'source' => 'demo']);
                    }
                }
            }
        }
        // campaign-level daily rows with reach (what Meta reports at campaign level)
        $adRows = q_all("SELECT ps.* FROM performance_snapshots ps JOIN ads d ON d.id = ps.entity_id JOIN adsets s ON s.id = d.adset_id
            WHERE ps.level = 'ad' AND s.campaign_id = ? AND ps.breakdown_key = ''", [$campId]);
        $byDay = []; foreach ($adRows as $r) $byDay[$r['date_start']][] = $r;
        foreach ($byDay as $date => $rs) {
            $t = sum_metrics($rs);
            $row = array_intersect_key($t, array_flip(ADDITIVE)) + ['result_type' => $t['result_type'], 'reach' => (int)round(($t['impressions'] ?? 0) / $rnd(1.15, 1.45))];
            upsert_snapshot($row + ['ad_account_id' => $accountId, 'level' => 'campaign', 'entity_id' => $campId, 'date_start' => $date, 'date_end' => $date, 'currency' => $cur, 'source' => 'demo']);
        }
    }
}
