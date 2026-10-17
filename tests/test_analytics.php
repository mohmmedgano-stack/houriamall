<?php
// Phase 3 — metrics math, import, aggregation, budgets, auditor, audience, creatives

function test_metric_math_and_null_handling() {
    $m = derive(sum_metrics([
        ['spend' => 100, 'impressions' => 10000, 'clicks' => 300, 'link_clicks' => 150, 'leads' => 10, 'reach' => 8000, 'purchases' => null, 'purchase_value' => null],
        ['spend' => 50, 'impressions' => 5000, 'clicks' => 100, 'link_clicks' => 50, 'leads' => 5, 'reach' => 4000],
    ]));
    eq($m['spend'], 150.0); eq($m['cpm'], 10.0, 'CPM = spend/imp*1000');
    eq($m['ctr'], 400 / 15000, 'CTR all'); eq($m['link_ctr'], 200 / 15000, 'link CTR separate from CTR');
    eq($m['link_cpc'], 0.75); eq($m['cpc'], 0.375); eq($m['cpl'], 10.0);
    eq($m['purchases'], null, 'missing purchases stay null'); eq($m['cpa'], null, 'CPA N/A without purchases'); eq($m['roas'], null);
    eq($m['reach'], null, 'reach not summed across rows'); eq($m['frequency'], null);
    $one = derive(sum_metrics([['spend' => 10, 'impressions' => 1000, 'reach' => 500]]));
    eq($one['reach'], 500.0); eq($one['frequency'], 2.0);
    $z = derive(sum_metrics([['spend' => 10, 'impressions' => 0, 'leads' => 0, 'link_clicks' => 0]]));
    eq($z['cpm'], null, 'div by zero → null'); eq($z['cpl'], null); eq($z['link_cpc'], null);
    $roas = derive(sum_metrics([['spend' => 200, 'purchase_value' => 900, 'purchases' => 3]]));
    eq($roas['roas'], 4.5); eq($roas['cpa'], 200 / 3);
    $mixed = derive(sum_metrics([['spend' => 10, 'results' => 2, 'result_type' => 'lead'], ['spend' => 10, 'results' => 5, 'result_type' => 'link_click']]));
    eq($mixed['result_type'], 'mixed'); eq($mixed['cost_per_result'], null, 'no cost per result across result types');
    ok(str_contains(fmt_metric('cpl', null, 'EGP'), 'N/A'), 'null renders N/A not 0');
}

function test_goal_mapping_and_ranking_min_sample() {
    eq(goal_of('OUTCOME_LEADS'), 'leads'); eq(goal_of('LEAD_GENERATION'), 'leads'); eq(goal_of('OUTCOME_SALES'), 'sales');
    eq(goal_of('CONVERSIONS'), 'sales'); eq(goal_of('LINK_CLICKS'), 'traffic'); eq(goal_of(null, 'lead'), 'leads'); eq(goal_of('MESSAGES'), 'engagement');
    $items = [
        ['name' => 'A', 'm' => ['spend' => 1000, 'cpl' => 50]], ['name' => 'B', 'm' => ['spend' => 1000, 'cpl' => 30]],
        ['name' => 'Tiny', 'm' => ['spend' => 5, 'cpl' => 1]], ['name' => 'C', 'm' => ['spend' => 800, 'cpl' => 90]], ['name' => 'NoData', 'm' => ['spend' => 900, 'cpl' => null]],
    ];
    $r = rank_by_goal($items, 'leads', 100, 1);
    eq($r['best'][0]['name'], 'B', 'lowest CPL wins'); eq($r['worst'][0]['name'], 'C');
    eq($r['excluded'], 2, 'tiny sample and missing data excluded');
    $s = rank_by_goal([['name' => 'x', 'm' => ['spend' => 100, 'roas' => 2]], ['name' => 'y', 'm' => ['spend' => 100, 'roas' => 5]]], 'sales', 0, 1);
    eq($s['best'][0]['name'], 'y', 'higher ROAS wins');
    eq(previous_period('2026-10-08', '2026-10-14'), ['2026-10-01', '2026-10-07']);
}

function _acc(string $cur = 'EGP'): array {
    $c = client_create(['name' => 'Imp ' . $cur, 'currency' => $cur]);
    return [$c, account_save(null, $c, ['name' => 'Acc', 'currency' => $cur, 'external_id' => (string)random_int(1000, 99999999)])];
}

function test_import_english_daily_csv_and_reimport_updates() {
    fresh(); [$c, $acc] = _acc('EGP');
    $csv = [
        ['Reporting starts', 'Reporting ends', 'Campaign name', 'Day', 'Objective', 'Amount spent (EGP)', 'Impressions', 'Reach', 'Link clicks', 'Clicks (all)', 'Results', 'Result indicator', 'Leads'],
        ['2026-10-01', '2026-10-02', 'Leads Oct', '2026-10-01', 'OUTCOME_LEADS', '1,000.50', '20000', '15000', '200', '350', '20', 'actions:onsite_conversion.lead_grouped', '20'],
        ['2026-10-01', '2026-10-02', 'Leads Oct', '2026-10-02', 'OUTCOME_LEADS', '500', '10000', '9000', '100', '150', '', '', ''],
        ['2026-10-01', '2026-10-02', 'Traffic', '2026-10-01', 'OUTCOME_TRAFFIC', '300', '9000', '', '-', '200', '', '', ''],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
    ];
    $s = import_performance($acc, $csv);
    eq($s['level'], 'campaign'); eq($s['inserted'], 3); eq($s['skipped'], 0);
    $rows = perf_rows([$c], '2026-10-01', '2026-10-02');
    $t = totals_by_currency($rows)['EGP']['m'];
    eq($t['spend'], 1800.5); eq($t['leads'], 20.0, 'empty Leads cell is not zero');
    eq($t['cpl'], 1000.5 / 20, 'CPL only uses spend where leads were measured (no traffic / unknown-day dilution)');
    eq((int)q_val('SELECT COUNT(*) FROM campaigns'), 2);
    eq(q_val("SELECT objective FROM campaigns WHERE name = 'Traffic'"), 'OUTCOME_TRAFFIC');
    eq(q_val("SELECT link_clicks FROM performance_snapshots WHERE spend = 300"), null, '"-" imported as null');
    // re-importing the same file updates rows instead of duplicating them
    $csv[1][5] = '1100';
    $s2 = import_performance($acc, $csv);
    eq($s2['inserted'], 0); eq($s2['updated'], 3);
    eq((int)q_val('SELECT COUNT(*) FROM performance_snapshots'), 3);
    eq(totals_by_currency(perf_rows([$c], '2026-10-01', '2026-10-02'))['EGP']['m']['spend'], 1900.0);
}

function test_import_arabic_headers_ad_level_with_breakdown_and_creative() {
    fresh(); [$c, $acc] = _acc('EGP');
    $rows = [
        ['اسم الحملة', 'اسم مجموعة الإعلانات', 'اسم الإعلان', 'اليوم', 'العمر', 'المبلغ الذي تم إنفاقه (EGP)', 'مرات الظهور', 'النقرات على الرابط', 'النتائج', 'مؤشر النتائج', 'النص الأساسي', 'العنوان'],
        ['حملة الساحل', 'مستثمرين', 'فيديو 1', '٢٠٢٦-١٠-٠٥', '25-34', '٢٠٠', '٨٠٠٠', '٩٠', '٦', 'lead', 'شقق بالتقسيط', 'احجز الآن'],
        ['حملة الساحل', 'مستثمرين', 'فيديو 1', '2026-10-05', '35-44', '100', '4000', '30', '2', 'lead', 'شقق بالتقسيط', 'احجز الآن'],
    ];
    $s = import_performance($acc, $rows);
    eq($s['level'], 'ad'); eq($s['breakdowns'], ['age']); eq($s['inserted'], 2);
    eq(q_val("SELECT breakdown_value FROM performance_snapshots ORDER BY id LIMIT 1"), '25-34');
    eq((float)q_val("SELECT leads FROM performance_snapshots ORDER BY id LIMIT 1"), 6.0, 'Results with lead indicator fill leads');
    eq(q_val('SELECT body FROM creative_assets'), 'شقق بالتقسيط', 'creative text captured');
    $aud = audience_analyze([$c], 'bd:age', '2026-10-01', '2026-10-31');
    eq(count($aud['EGP']['segments']), 2);
    eq($aud['EGP']['segments'][0]['label'], '25-34', 'cheaper CPL first');
    // breakdown rows never leak into normal totals
    eq(perf_rows([$c], '2026-10-01', '2026-10-31'), [], 'no non-breakdown rows → dashboard empty, not double counted');
}

function test_import_rejects_currency_mismatch_and_missing_columns() {
    fresh(); [$c, $acc] = _acc('USD');
    throws(fn() => import_performance($acc, [['Campaign name', 'Day', 'Amount spent (EGP)'], ['X', '2026-10-01', '10']]), InvalidArgumentException::class, 'EGP file into USD account');
    throws(fn() => import_performance($acc, [['Foo', 'Bar'], ['1', '2']]), InvalidArgumentException::class, 'no campaign column');
    throws(fn() => import_performance($acc, [['Campaign name', 'Amount spent (USD)'], ['X', '10']]), InvalidArgumentException::class, 'no dates and no period');
    $s = import_performance($acc, [['Campaign name', 'Amount spent (USD)', 'Reach', 'Impressions'], ['X', '10', '700', '1000']], ['date_start' => '2026-09-01', 'date_end' => '2026-09-30']);
    eq($s['inserted'], 1);
    $m = totals_by_currency(perf_rows([$c], '2026-09-01', '2026-09-30'))['USD']['m'];
    eq($m['reach'], 700.0, 'period row keeps exact reach'); eq(round($m['frequency'], 4), round(1000 / 700, 4));
    eq(perf_rows([$c], '2026-09-01', '2026-09-15'), [], 'period row not counted in a partial range');
}

function test_xlsx_roundtrip_and_import() {
    fresh(); [$c, $acc] = _acc('EGP');
    $tmp = $GLOBALS['__tmp'] . '/t.xlsx';
    xlsx_write($tmp, [['Campaign name', 'Day', 'Amount spent (EGP)', 'Leads'], ['عربي', '2026-10-03', 120.5, 4], ['Two', '2026-10-03', 80, 0]]);
    $rows = xlsx_read($tmp);
    eq($rows[1][0], 'عربي'); eq($rows[1][2], '120.5');
    $s = import_performance($acc, $rows);
    eq($s['inserted'], 2);
    $m = totals_by_currency(perf_rows([$c], '2026-10-03', '2026-10-03'))['EGP']['m'];
    eq($m['leads'], 4.0); eq($m['cpl'], 200.5 / 4, 'zero leads is a real zero and its spend counts toward CPL');
    eq(excel_date('46298'), '2026-10-03');
}

function test_multi_currency_never_summed() {
    fresh();
    [$c1, $a1] = _acc('EGP'); [$c2, $a2] = _acc('USD');
    import_performance($a1, [['Campaign name', 'Day', 'Amount spent (EGP)'], ['E', '2026-10-01', '1000']]);
    import_performance($a2, [['Campaign name', 'Day', 'Amount spent (USD)'], ['U', '2026-10-01', '20']]);
    $t = totals_by_currency(perf_rows([$c1, $c2], '2026-10-01', '2026-10-01'));
    eq(array_keys($t), ['EGP', 'USD']); eq($t['EGP']['m']['spend'], 1000.0); eq($t['USD']['m']['spend'], 20.0);
}

function test_level_picking_does_not_double_count() {
    fresh(); [$c, $acc] = _acc('EGP');
    import_performance($acc, [['Campaign name', 'Day', 'Amount spent (EGP)', 'Reach', 'Impressions'], ['C', '2026-10-01', '100', '900', '1000']]);
    import_performance($acc, [['Campaign name', 'Ad Set Name', 'Ad name', 'Day', 'Amount spent (EGP)', 'Impressions'], ['C', 'S', 'A1', '2026-10-01', '60', '600'], ['C', 'S', 'A2', '2026-10-01', '40', '400']]);
    $rows = perf_rows([$c], '2026-10-01', '2026-10-01');
    eq(count($rows), 3);
    $t = totals_by_currency($rows)['EGP']['m'];
    eq($t['spend'], 100.0, 'campaign rows used, ad rows not added on top'); eq($t['reach'], 900.0);
    eq(count(entity_table($rows, 'ad')), 2); eq(count(entity_table($rows, 'adset')), 1, 'adset level rolled up from ads');
    eq(entity_table($rows, 'adset')[0]['m']['spend'], 100.0);
}

function test_budget_status_projection_and_alerts() {
    fresh(); [$c, $acc] = _acc('EGP');
    $csv = [['Campaign name', 'Day', 'Amount spent (EGP)', 'Leads']];
    for ($d = 1; $d <= 10; $d++) $csv[] = ['C', sprintf('2026-10-%02d', $d), '100', '2'];
    import_performance($acc, $csv);
    $bid = budget_save(null, ['client_id' => $c, 'name' => 'Oct', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => '2000', 'currency' => 'EGP', 'target_metric' => 'cpl', 'target_cost' => '40', 'alert_pct' => '40']);
    $b = q_one('SELECT * FROM budgets WHERE id = ?', [$bid]);
    $s = budget_status($b, '2026-10-10');
    eq($s['spent'], 1000.0); eq($s['pct_used'], 0.5); eq($s['remaining'], 1000.0);
    eq($s['elapsed_days'], 10); eq($s['remaining_days'], 21); eq($s['avg_daily'], 100.0);
    eq($s['projected_spend'], 3100.0, 'spent + avg daily × remaining days');
    eq($s['cost_per_result'], 50.0); eq($s['results'], 20.0); eq($s['projected_results'], 62.0);
    eq($s['target_results'], 50.0, 'amount ÷ target CPL');
    eq(count($s['alerts']), 3, 'over alert %, projected overspend, CPL above target');
    $sc = budget_scenarios($b, $s, 0.3);
    $up = array_values(array_filter($sc, fn($x) => abs($x['change'] - 0.5) < 1e-9))[0];
    ok($up['cpr'] > 50, 'scaling up raises cost per result (not linear)');
    ok($up['results'] < 20 + 150 * 21 / 50, 'results grow less than linearly');
    $lin = budget_scenarios($b, $s, 0.0);
    eq(round($lin[0]['cpr'], 6), 50.0, 'elasticity 0 = linear');
    throws(fn() => budget_save(null, ['client_id' => $c, 'name' => 'x', 'period_start' => '2026-10-31', 'period_end' => '2026-10-01', 'amount' => '1']), InvalidArgumentException::class);
    throws(fn() => budget_save(null, ['client_id' => $c, 'name' => 'x', 'period_start' => '2026-10-01', 'period_end' => '2026-10-02', 'amount' => '-5']), InvalidArgumentException::class);
}

function test_budget_ignores_other_currency() {
    fresh();
    $c = client_create(['name' => 'Mixed', 'currency' => 'EGP']);
    $e = account_save(null, $c, ['name' => 'E', 'currency' => 'EGP']); $u = account_save(null, $c, ['name' => 'U', 'currency' => 'USD']);
    import_performance($e, [['Campaign name', 'Day', 'Amount spent (EGP)'], ['A', '2026-10-01', '100']]);
    import_performance($u, [['Campaign name', 'Day', 'Amount spent (USD)'], ['B', '2026-10-01', '100']]);
    $bid = budget_save(null, ['client_id' => $c, 'name' => 'B', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => '1000', 'currency' => 'EGP']);
    eq(budget_status(q_one('SELECT * FROM budgets WHERE id = ?', [$bid]), '2026-10-05')['spent'], 100.0, 'USD spend not added to EGP budget');
}

function test_auditor_never_claims_unavailable_checks() {
    fresh(); [$c, $acc] = _acc('EGP');
    $aid = audit_create($c, null, null, 'pre-launch');
    $res = json_decode(q_val('SELECT results_json FROM campaign_audits WHERE id = ?', [$aid]), true);
    ok(!array_filter($res, fn($r) => $r['status'] !== 'not_checked'), 'no campaign → nothing auto-passed');
    import_performance($acc, [['Campaign name', 'Ad set name', 'Ad name', 'Day', 'Objective', 'Amount spent (EGP)', 'Body'], ['C', 'S', 'A', '2026-10-01', 'OUTCOME_LEADS', '10', 'Lorem ipsum offer']]);
    $cid = (int)q_val('SELECT id FROM campaigns');
    $auto = audit_auto_checks($cid);
    eq($auto['objective'][0], 'pass'); eq($auto['copy'][0], 'fail', 'placeholder text caught');
    eq($auto['access'][0], 'not_checked', 'no API connection → not checked');
    eq($auto['targeting'][0], 'not_checked', 'no targeting data → not checked');
    eq($auto['utm'][0], 'not_checked');
    q("UPDATE creative_assets SET link_url = 'http://site.com/page', body = 'عرض حقيقي'");
    $auto = audit_auto_checks($cid);
    eq($auto['utm'][0], 'warning'); eq($auto['links'][0], 'fail', 'non-https link'); eq($auto['copy'][0], 'pass');
    $b = fresh('buyer');
    throws(fn() => audit_create($c, null, null, 'x'), Forbidden::class, 'other clients blocked');
}

function test_audience_ztest_and_small_samples() {
    ok(abs(z_two_prop(50, 500, 20, 500) - 3.72) < 0.02, 'z-test value');
    eq(z_two_prop(1, 0, 2, 10), null);
    $ins = audience_insights([['label' => 'a', 'small' => true, 'm' => ['cpl' => 1], 'share' => .1, 'z' => null]], ['cpl' => 2], 'leads', 'cpl', 'EGP');
    eq($ins[0][0], 'info', 'not enough sample → no recommendation');
}

function test_creative_performance_by_asset() {
    fresh(); [$c, $acc] = _acc('EGP');
    import_performance($acc, [['Campaign name', 'Ad set name', 'Ad name', 'Day', 'Objective', 'Amount spent (EGP)', 'Leads', 'Title'],
        ['C', 'S', 'Good', '2026-10-01', 'OUTCOME_LEADS', '100', '10', 'T1'], ['C', 'S', 'Bad', '2026-10-01', 'OUTCOME_LEADS', '100', '2', 'T2']]);
    $items = creative_flag_samples(creative_performance([$c], '2026-10-01', '2026-10-01'));
    eq(count($items), 2);
    $by = array_column(array_map(fn($i) => ['t' => $i['asset']['title'], 'cpl' => $i['m']['cpl'], 'small' => $i['small']], $items), null, 't');
    eq($by['T1']['cpl'], 10.0); eq($by['T2']['cpl'], 50.0);
    ok(!$by['T1']['small'] && $by['T2']['small'], 'fewer than 3 results flagged as small sample');
    $cid = (int)q_val("SELECT id FROM creative_assets WHERE title = 'T1'");
    creative_update($cid, ['hook_type' => 'سؤال', 'in_library' => '1']);
    eq((int)q_val('SELECT in_library FROM creative_assets WHERE id = ?', [$cid]), 1);
}

function test_demo_dataset_is_consistent() {
    fresh(); demo_seed(20); set_mode('demo');
    $ids = scope_client_ids(); eq(count($ids), 3);
    $rows = perf_rows($ids, (new DateTimeImmutable(today()))->modify('-19 days')->format('Y-m-d'), today());
    $t = totals_by_currency($rows);
    eq(array_keys($t), ['EGP', 'USD']);
    // campaign-level totals equal the sum of their ads (generated consistently)
    $camp = entity_table($rows, 'campaign')[0];
    $adsSum = array_sum(array_map(fn($a) => $a['m']['spend'], array_filter(entity_table($rows, 'ad'), fn($a) => $a['campaign_id'] === $camp['id'])));
    ok(abs($adsSum - $camp['m']['spend']) < 0.05, 'campaign spend = sum of ads');
}

