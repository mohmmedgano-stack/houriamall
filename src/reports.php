<?php
declare(strict_types=1);

function report_types(): array {
    return ['daily' => 'تقرير يومي', 'weekly' => 'تقرير أسبوعي', 'monthly' => 'تقرير شهري', 'client' => 'تقرير عميل (فترة مخصصة)',
        'campaign' => 'تقرير حملة', 'lead_quality' => 'تقرير جودة الليدز', 'budget' => 'تقرير الميزانية'];
}
/** Default period per type (complete days only, ending yesterday for daily/weekly). */
function report_default_period(string $type, ?string $asOf = null): array {
    $t = new DateTimeImmutable($asOf ?? today());
    return match ($type) {
        'daily' => [$t->modify('-1 day')->format('Y-m-d'), $t->modify('-1 day')->format('Y-m-d')],
        'weekly' => [$t->modify('-7 days')->format('Y-m-d'), $t->modify('-1 day')->format('Y-m-d')],
        'monthly' => [$t->modify('first day of last month')->format('Y-m-d'), $t->modify('last day of last month')->format('Y-m-d')],
        default => [$t->modify('-29 days')->format('Y-m-d'), $t->format('Y-m-d')],
    };
}

/**
 * Assemble everything a report shows. Pure data — rendering happens elsewhere.
 * Every sentence in "issues" and "recommendations" is derived from numbers in this array.
 */
function report_build(string $type, int $clientId, string $from, string $to, ?int $campaignId = null): array {
    if (!isset(report_types()[$type])) throw new InvalidArgumentException('نوع تقرير غير معروف');
    if (!valid_date($from) || !valid_date($to) || $from > $to) throw new InvalidArgumentException('الفترة مش صحيحة');
    $client = q_one('SELECT * FROM clients WHERE id = ?', [$clientId]);
    if (!$client) throw new InvalidArgumentException('العميل مش موجود');
    if ($type === 'campaign' && (!$campaignId || campaign_client_id($campaignId) !== $clientId)) throw new InvalidArgumentException('اختار حملة تبع العميل');
    [$pf, $pt] = previous_period($from, $to);
    $opt = $campaignId ? ['campaign' => $campaignId] : [];
    $rows = perf_rows([$clientId], $from, $to, $opt);
    $prevRows = perf_rows([$clientId], $pf, $pt, $opt);
    $tot = totals_by_currency($rows); $prev = totals_by_currency($prevRows);
    $totals = [];
    foreach ($tot as $cur => $g) $totals[$cur] = ['m' => $g['m'], 'prev' => $prev[$cur]['m'] ?? null];
    $camps = entity_table($rows, 'campaign');
    $prevCamps = array_column(array_map(fn($c) => ['id' => $c['id'], 'm' => $c['m']], entity_table($prevRows, 'campaign')), 'm', 'id');
    $byGoal = [];
    foreach ($camps as $c) $byGoal[$c['goal'] . '|' . $c['currency']][] = $c;
    $goals = [];
    foreach ($byGoal as $k => $list) {
        [$goal, $cur] = explode('|', $k);
        $m = derive(sum_metrics(array_merge(...array_map(fn($c) => $c['rows'], $list))));
        $spendTot = array_sum(array_map(fn($c) => (float)$c['m']['spend'], $list));
        $rk = rank_by_goal($list, $goal, $spendTot * 0.05, max(1, min(3, intdiv(count($list), 2))));
        $goals[] = ['goal' => $goal, 'currency' => $cur, 'kpi' => goal_kpi($goal)[0], 'm' => $m, 'campaigns' => count($list),
            'best' => array_map(fn($c) => ['name' => $c['name'], 'v' => $c['m'][$rk['kpi']], 'spend' => $c['m']['spend']], $rk['best']),
            'worst' => array_map(fn($c) => ['name' => $c['name'], 'v' => $c['m'][$rk['kpi']], 'spend' => $c['m']['spend']], $rk['worst'])];
    }
    $campRows = array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'goal' => $c['goal'], 'currency' => $c['currency'], 'status' => $c['status'],
        'm' => array_intersect_key($c['m'], array_flip(['spend', 'impressions', 'link_clicks', 'link_ctr', 'link_cpc', 'cpm', 'results', 'leads', 'cpl', 'purchases', 'cpa', 'purchase_value', 'roas', 'cost_per_result', 'frequency'])),
        'prev' => isset($prevCamps[$c['id']]) ? array_intersect_key($prevCamps[$c['id']], array_flip(['spend', 'cpl', 'cpa', 'roas', 'link_ctr'])) : null], $camps);
    usort($campRows, fn($a, $b) => ($b['m']['spend'] ?? 0) <=> ($a['m']['spend'] ?? 0));

    $data = ['type' => $type, 'client' => ['id' => $clientId, 'name' => $client['name'], 'logo' => $client['logo_path'], 'currency' => $client['currency']],
        'from' => $from, 'to' => $to, 'prev_from' => $pf, 'prev_to' => $pt, 'campaign_id' => $campaignId,
        'campaign_name' => $campaignId ? q_val('SELECT name FROM campaigns WHERE id = ?', [$campaignId]) : null,
        'totals' => $totals, 'goals' => $goals, 'campaigns' => $campRows, 'generated_at' => now()];
    if ($type === 'campaign') {
        $data['adsets'] = array_map(fn($x) => ['name' => $x['name'], 'currency' => $x['currency'], 'm' => array_intersect_key($x['m'], array_flip(['spend', 'link_ctr', 'cpm', 'leads', 'cpl', 'purchases', 'roas', 'cost_per_result']))], entity_table($rows, 'adset'));
        $data['ads'] = array_slice(array_map(fn($x) => ['name' => $x['name'], 'currency' => $x['currency'], 'm' => array_intersect_key($x['m'], array_flip(['spend', 'link_ctr', 'cpm', 'leads', 'cpl', 'purchases', 'roas', 'cost_per_result']))], entity_table($rows, 'ad')), 0, 15);
    }
    // CRM / lead quality
    $lq = lead_quality([$clientId], 'campaign', $from, $to);
    if ($lq['total'] && $lq['total']['total'] > 0) {
        $t = $lq['total'];
        $data['lead_quality'] = array_intersect_key($t, array_flip(['total', 'contacted', 'qualified', 'appointments', 'won', 'contact_rate', 'qualified_rate', 'appointment_rate', 'sales_rate',
            'cost_per_qualified', 'cost_per_sale', 'roas', 'currency', 'revenue', 'ttc_median', 'never_contacted', 'lost_reasons', 'won_without_value'])) + ['duplicates' => $lq['duplicates']];
        $data['lead_quality']['by_campaign'] = array_map(fn($r) => ['label' => $r['label'], 'total' => $r['total'], 'qualified_rate' => $r['qualified_rate'], 'won' => $r['won'],
            'cost_per_qualified' => $r['cost_per_qualified'], 'currency' => $r['currency'], 'low_quality' => $r['low_quality']], array_slice(array_filter($lq['rows'], fn($r) => $r['total'] > 0), 0, 12));
    }
    // budgets overlapping the period
    $data['budgets'] = [];
    foreach (q_all('SELECT * FROM budgets WHERE client_id = ? AND period_start <= ? AND period_end >= ?', [$clientId, $to, $from]) as $b) {
        $s = budget_status($b, min($to, today()));
        $data['budgets'][] = ['name' => $b['name'], 'amount' => (float)$b['amount'], 'currency' => $b['currency'], 'period' => [$b['period_start'], $b['period_end']],
            'spent' => $s['spent'], 'pct_used' => $s['pct_used'], 'projected_spend' => $s['projected_spend'], 'results' => $s['results'], 'cost_per_result' => $s['cost_per_result'],
            'result_key' => $s['result_key'], 'target_cost' => $b['target_cost'] !== null ? (float)$b['target_cost'] : null, 'alerts' => array_column($s['alerts'], 1)];
    }
    $data['alerts'] = q_all("SELECT severity, title, message, created_at FROM alerts WHERE client_id = ? AND created_at BETWEEN ? AND ?" . ($campaignId ? ' AND campaign_id = ' . (int)$campaignId : '') . " ORDER BY id DESC LIMIT 15",
        [$clientId, $from . ' 00:00:00', $to . ' 23:59:59']);
    [$data['issues'], $data['recommendations']] = report_findings($data);
    $data['summary'] = report_summary_text($data);
    return $data;
}

/** Rule-based observations. Each item states the numbers it came from. */
function report_findings(array $d): array {
    $issues = []; $recs = [];
    $n = fn($v, $dec = 2) => number_format((float)$v, $dec);
    foreach ($d['totals'] as $cur => $t) {
        $m = $t['m']; $p = $t['prev'];
        if ($p) {
            foreach ([['cpl', true, 0.2], ['cpa', true, 0.2], ['roas', false, 0.2], ['link_ctr', false, 0.2], ['cpm', true, 0.25]] as [$k, $lowerBetter, $th]) {
                $ch = pct_change($m[$k] === null ? null : (float)$m[$k], $p[$k] === null ? null : (float)$p[$k]);
                if ($ch === null || abs($ch) < $th) continue;
                $worse = $lowerBetter ? $ch > 0 : $ch < 0;
                $txt = metric_label($k) . ' ' . ($ch > 0 ? 'زاد' : 'قل') . ' ' . $n(abs($ch) * 100, 0) . '% عن الفترة السابقة (' . ($k === 'link_ctr' ? $n($p[$k] * 100) . '% ← ' . $n($m[$k] * 100) . '%' : $n($p[$k]) . ' ← ' . $n($m[$k]) . " $cur") . ')';
                if ($worse) $issues[] = $txt; else $recs[] = 'تحسن: ' . $txt . ' — حافظ على اللي اتغيّر في الفترة دي وراقب لو هيستمر.';
            }
        }
        if ($m['frequency'] !== null && $m['frequency'] > 3.5) $issues[] = "Frequency وصل {$n($m['frequency'])} — الجمهور بيشوف الإعلان كتير وده ممكن يرفع التكلفة.";
    }
    foreach ($d['campaigns'] as $c) {
        $res = $c['goal'] === 'sales' ? 'purchases' : ($c['goal'] === 'leads' ? 'leads' : 'results');
        if (($c['m']['spend'] ?? 0) > 0 && in_array($c['goal'], ['leads', 'sales'], true) && ($c['m'][$res] ?? null) === 0.0)
            $issues[] = "«{$c['name']}» صرفت {$n($c['m']['spend'], 0)} {$c['currency']} من غير أي " . metric_label($res) . '.';
    }
    foreach ($d['goals'] as $g) {
        if ($g['best'] && $g['worst']) {
            $b = $g['best'][0]; $w = $g['worst'][0];
            if ($b['v'] !== null && $w['v'] !== null && $b['v'] > 0) {
                $ratio = goal_kpi($g['goal'])[1] ? $w['v'] / $b['v'] : $b['v'] / max(0.0001, $w['v']);
                if ($ratio >= 1.5) $recs[] = 'في حملات ' . goal_labels()[$g['goal']] . ': «' . $b['name'] . '» أفضل من «' . $w['name'] . '» بـ ' . $n($ratio, 1) . 'x في ' . metric_label($g['kpi'])
                    . ' (' . $n($b['v']) . ' مقابل ' . $n($w['v']) . ' ' . $g['currency'] . '). اقتراح: راجع توزيع الميزانية بين الاتنين تدريجياً واختبر سبب الفرق (كريتيف/جمهور).';
            }
        }
    }
    if ($lq = $d['lead_quality'] ?? null) {
        if ($lq['contact_rate'] !== null && $lq['contact_rate'] < 0.7) $issues[] = 'Contact Rate ' . $n($lq['contact_rate'] * 100, 0) . '% بس — ' . (int)$lq['never_contacted'] . ' ليد محدش كلمهم.';
        if ($lq['ttc_median'] !== null && $lq['ttc_median'] > 24) $issues[] = 'متوسط وقت أول تواصل ' . $n($lq['ttc_median'], 1) . ' ساعة — الليدز بتبرد. الهدف أقل من ساعة.';
        foreach ($lq['by_campaign'] as $r) if ($r['low_quality']) $issues[] = "«{$r['label']}» جابت {$r['total']} ليد لكن المؤهل " . $n(($r['qualified_rate'] ?? 0) * 100, 0) . '% بس.';
        if ($lq['won_without_value']) $recs[] = 'سجّل قيمة البيع لـ ' . (int)$lq['won_without_value'] . ' صفقة عشان الـ ROAS الحقيقي يتحسب.';
    }
    foreach ($d['budgets'] as $b) foreach ($b['alerts'] as $a) $issues[] = 'ميزانية «' . $b['name'] . '»: ' . $a;
    foreach ($d['alerts'] as $a) if ($a['severity'] === 'critical') $issues[] = 'تنبيه حرج: ' . $a['title'];
    if (!$issues) $issues[] = 'مفيش مشاكل واضحة في الأرقام المتاحة للفترة دي.';
    if (!$recs) $recs[] = 'الأداء مستقر — كمّل الاختبارات الحالية وراجع الأرقام الأسبوع الجاي.';
    return [array_values(array_unique($issues)), array_values(array_unique($recs))];
}

/** Executive summary built only from the numbers in the report (no invented causes). */
function report_summary_text(array $d): string {
    $parts = [];
    $period = $d['from'] === $d['to'] ? 'يوم ' . $d['from'] : 'الفترة من ' . $d['from'] . ' إلى ' . $d['to'];
    if (!$d['totals']) return "مفيش بيانات أداء مسجلة لـ {$d['client']['name']} في $period.";
    foreach ($d['totals'] as $cur => $t) {
        $m = $t['m']; $p = $t['prev'];
        $s = "إجمالي الصرف " . number_format((float)$m['spend']) . " $cur";
        $ch = $p ? pct_change((float)$m['spend'], $p['spend'] === null ? null : (float)$p['spend']) : null;
        if ($ch !== null) $s .= ' (' . ($ch >= 0 ? '+' : '−') . number_format(abs($ch) * 100, 0) . '% عن الفترة السابقة)';
        if ($m['leads'] !== null) $s .= '، ' . number_format((float)$m['leads']) . ' ليد بتكلفة ' . ($m['cpl'] === null ? 'N/A' : number_format($m['cpl'], 2)) . ' لليد';
        if ($m['purchases'] !== null) $s .= '، ' . number_format((float)$m['purchases']) . ' عملية شراء' . ($m['roas'] !== null ? ' و ROAS ' . number_format($m['roas'], 2) . 'x' : '');
        $parts[] = $s . '.';
    }
    if ($lq = $d['lead_quality'] ?? null) $parts[] = 'في الـ CRM: ' . $lq['total'] . ' ليد، المؤهل منهم ' . ($lq['qualified_rate'] === null ? 'N/A' : number_format($lq['qualified_rate'] * 100, 0) . '%') . '، واتقفل ' . $lq['won'] . ' بيع.';
    $realIssues = array_filter($d['issues'], fn($i) => !str_starts_with($i, 'مفيش مشاكل'));
    $parts[] = $realIssues ? 'فيه ' . count($realIssues) . ' ملاحظة محتاجة متابعة (تحت).' : 'مفيش مشاكل واضحة في الأرقام.';
    return "تقرير {$d['client']['name']} عن $period: " . implode(' ', $parts);
}

function report_save(array $data, ?string $summaryOverride = null, bool $ai = false): int {
    $title = report_types()[$data['type']] . ' — ' . $data['client']['name'] . ($data['campaign_name'] ? ' — ' . $data['campaign_name'] : '') . ' (' . $data['from'] . ($data['from'] !== $data['to'] ? ' → ' . $data['to'] : '') . ')';
    $id = db_insert('reports', ['client_id' => $data['client']['id'], 'type' => $data['type'], 'title' => $title, 'period_start' => $data['from'], 'period_end' => $data['to'],
        'campaign_id' => $data['campaign_id'], 'data_json' => json_encode($data, JSON_UNESCAPED_UNICODE), 'summary_text' => $summaryOverride ?? $data['summary'], 'ai_used' => $ai ? 1 : 0,
        'created_by' => $GLOBALS['CC_USER']['id'] ?? null, 'created_at' => now()]);
    audit('report.create', 'report', $id, ['type' => $data['type']]);
    return $id;
}

/* ---------- rendering ---------- */
const REPORT_COLS = ['spend', 'impressions', 'link_ctr', 'link_cpc', 'cpm', 'leads', 'cpl', 'purchases', 'cpa', 'roas', 'cost_per_result'];

/** Plain-text cell value (for PDF/CSV) */
function rv(string $k, $v): string {
    if ($v === null) return 'N/A';
    $f = metric_defs()[$k][1] ?? 'num';
    return match ($f) { 'pct' => number_format((float)$v * 100, 2) . '%', 'ratio' => number_format((float)$v, 2) . ($k === 'roas' ? 'x' : ''),
        'money' => number_format((float)$v, in_array($k, ['spend', 'purchase_value'], true) ? 0 : 2), default => number_format((float)$v, floor((float)$v) == (float)$v ? 0 : 1) };
}

/** HTML used by both the screen view and the PDF (inline styles only, TCPDF-friendly). */
function report_html(array $r, array $d, bool $forPdf = false): string {
    $th = 'style="background-color:#eef2f0;font-weight:bold"';
    $h = '<table cellpadding="4" width="100%"><tr><td width="75%"><h1 style="font-size:18pt">' . h($r['title']) . '</h1>'
        . '<span style="color:#666">' . h($d['client']['name']) . ' · ' . h($d['from']) . ($d['from'] !== $d['to'] ? ' → ' . h($d['to']) : '') . ' · مقارنة بـ ' . h($d['prev_from']) . ' → ' . h($d['prev_to']) . '</span></td>';
    $logo = $d['client']['logo'] ? APP_ROOT . '/' . $d['client']['logo'] : null;
    $h .= '<td width="25%" align="left">' . ($logo && is_file($logo) ? '<img src="' . ($forPdf ? h($logo) : h($d['client']['logo'])) . '" height="50">' : '') . '</td></tr></table>';
    $h .= '<h2 style="font-size:13pt">1. الملخص التنفيذي</h2><p>' . nl2br(h($r['summary_text'])) . '</p>';
    if ($r['ai_used']) $h .= '<p style="color:#888;font-size:8pt">الملخص اتكتب بمساعدة AI من الأرقام اللي في التقرير بس.</p>';
    $h .= '<h2 style="font-size:13pt">2. الصرف والنتائج والمقارنة بالفترة السابقة</h2>';
    if (!$d['totals']) $h .= '<p>مفيش بيانات أداء للفترة.</p>';
    foreach ($d['totals'] as $cur => $t) {
        $h .= '<table border="1" cellpadding="4" width="100%"><tr><td ' . $th . '>المؤشر (' . h($cur) . ')</td><td ' . $th . '>الفترة</td><td ' . $th . '>السابقة</td><td ' . $th . '>التغير</td></tr>';
        foreach (['spend', 'impressions', 'link_clicks', 'link_ctr', 'link_cpc', 'cpm', 'leads', 'cpl', 'purchases', 'cpa', 'purchase_value', 'roas'] as $k) {
            $v = $t['m'][$k] ?? null; $p = $t['prev'][$k] ?? null;
            if ($v === null && $p === null) continue;
            $ch = pct_change($v === null ? null : (float)$v, $p === null ? null : (float)$p);
            $h .= '<tr><td>' . h(metric_label($k)) . '</td><td>' . rv($k, $v) . '</td><td>' . rv($k, $p) . '</td><td>' . ($ch === null ? '—' : ($ch >= 0 ? '+' : '') . number_format($ch * 100, 1) . '%') . '</td></tr>';
        }
        $h .= '</table><br>';
    }
    $h .= '<h2 style="font-size:13pt">3. الأداء حسب هدف الحملة</h2>';
    foreach ($d['goals'] as $g) {
        $h .= '<p><b>' . h(goal_labels()[$g['goal']]) . '</b> (' . h($g['currency']) . ') — ' . $g['campaigns'] . ' حملة، صرف ' . rv('spend', $g['m']['spend']) . '، ' . h(metric_label($g['kpi'])) . ': ' . rv($g['kpi'], $g['m'][$g['kpi']]) . '<br>';
        foreach ($g['best'] as $b) $h .= '✔ الأفضل: ' . h($b['name']) . ' — ' . rv($g['kpi'], $b['v']) . '<br>';
        foreach ($g['worst'] as $w) $h .= '✘ الأضعف: ' . h($w['name']) . ' — ' . rv($g['kpi'], $w['v']) . '<br>';
        $h .= '</p>';
    }
    if ($d['campaigns']) {
        $h .= '<h2 style="font-size:13pt">4. الحملات</h2><table border="1" cellpadding="3" width="100%" style="font-size:8pt"><tr><td ' . $th . ' width="22%">الحملة</td>';
        foreach (REPORT_COLS as $k) $h .= '<td ' . $th . '>' . h(metric_label($k)) . '</td>';
        $h .= '</tr>';
        foreach ($d['campaigns'] as $c) { $h .= '<tr><td>' . h($c['name']) . ' <span style="color:#888">(' . h($c['currency']) . ')</span></td>'; foreach (REPORT_COLS as $k) $h .= '<td>' . rv($k, $c['m'][$k] ?? null) . '</td>'; $h .= '</tr>'; }
        $h .= '</table>';
    }
    foreach (['adsets' => 'Ad Sets', 'ads' => 'الإعلانات'] as $key => $lbl) if (!empty($d[$key])) {
        $h .= '<h3>' . $lbl . '</h3><table border="1" cellpadding="3" width="100%" style="font-size:8pt"><tr><td ' . $th . ' width="30%">الاسم</td>';
        $cols = ['spend', 'link_ctr', 'cpm', 'leads', 'cpl', 'purchases', 'roas']; foreach ($cols as $k) $h .= '<td ' . $th . '>' . h(metric_label($k)) . '</td>';
        $h .= '</tr>'; foreach ($d[$key] as $x) { $h .= '<tr><td>' . h($x['name']) . '</td>'; foreach ($cols as $k) $h .= '<td>' . rv($k, $x['m'][$k] ?? null) . '</td>'; $h .= '</tr>'; } $h .= '</table>';
    }
    if ($lq = $d['lead_quality'] ?? null) {
        $h .= '<h2 style="font-size:13pt">5. جودة الليدز (من الـ CRM)</h2><table border="1" cellpadding="4" width="100%"><tr>';
        foreach (['ليدز', 'Contact', 'Qualified', 'Appointments', 'Won', 'Cost/Qualified', 'Cost/Sale', 'ROAS', 'أول تواصل (وسيط)'] as $x) $h .= '<td ' . $th . '>' . $x . '</td>';
        $h .= '</tr><tr><td>' . $lq['total'] . '</td><td>' . rv('link_ctr', $lq['contact_rate']) . '</td><td>' . $lq['qualified'] . ' (' . rv('link_ctr', $lq['qualified_rate']) . ')</td><td>' . $lq['appointments'] . '</td><td>' . $lq['won'] . '</td>'
            . '<td>' . rv('cpl', $lq['cost_per_qualified']) . '</td><td>' . rv('cpl', $lq['cost_per_sale']) . '</td><td>' . rv('roas', $lq['roas']) . '</td><td>' . ($lq['ttc_median'] === null ? 'N/A' : number_format($lq['ttc_median'], 1) . ' س') . '</td></tr></table>';
        if ($lq['by_campaign']) {
            $h .= '<table border="1" cellpadding="3" width="100%" style="font-size:8pt"><tr><td ' . $th . ' width="40%">الحملة</td><td ' . $th . '>ليدز</td><td ' . $th . '>مؤهل %</td><td ' . $th . '>بيع</td><td ' . $th . '>Cost/Qualified</td></tr>';
            foreach ($lq['by_campaign'] as $x) $h .= '<tr><td>' . h($x['label']) . ($x['low_quality'] ? ' ⚠' : '') . '</td><td>' . $x['total'] . '</td><td>' . rv('link_ctr', $x['qualified_rate']) . '</td><td>' . $x['won'] . '</td><td>' . rv('cpl', $x['cost_per_qualified']) . ' ' . h($x['currency'] ?? '') . '</td></tr>';
            $h .= '</table>';
        }
        $h .= '<p style="color:#888;font-size:8pt">الإسناد: الليد بيتحسب للحملة المسجلة عليه في الـ CRM.</p>';
    }
    if ($d['budgets']) {
        $h .= '<h2 style="font-size:13pt">6. الميزانية</h2><table border="1" cellpadding="4" width="100%"><tr><td ' . $th . '>الميزانية</td><td ' . $th . '>المخطط</td><td ' . $th . '>المصروف</td><td ' . $th . '>%</td><td ' . $th . '>المتوقع (تقدير)</td></tr>';
        foreach ($d['budgets'] as $b) $h .= '<tr><td>' . h($b['name']) . '</td><td>' . number_format($b['amount']) . ' ' . h($b['currency']) . '</td><td>' . number_format((float)$b['spent']) . '</td><td>' . rv('link_ctr', $b['pct_used']) . '</td><td>' . ($b['projected_spend'] === null ? 'N/A' : number_format($b['projected_spend'])) . '</td></tr>';
        $h .= '</table>';
    }
    $h .= '<h2 style="font-size:13pt">7. المشكلات الملحوظة</h2><ul>'; foreach ($d['issues'] as $i) $h .= '<li>' . h($i) . '</li>'; $h .= '</ul>';
    $h .= '<h2 style="font-size:13pt">8. التوصيات والخطوات القادمة</h2><ul>'; foreach ($d['recommendations'] as $i) $h .= '<li>' . h($i) . '</li>'; $h .= '</ul>';
    $h .= '<p style="color:#888;font-size:8pt">التوصيات مقترحات مبنية على أرقام الفترة دي، مش ضمان لنتايج مستقبلية. N/A = البيانات غير متاحة. اتعمل ' . h(fmt_dt($d['generated_at'])) . ' · Media Buying Command Center</p>';
    return $h;
}

function report_pdf(array $r): string {
    require_once APP_ROOT . '/lib/tcpdf/tcpdf.php';
    $d = json_decode($r['data_json'], true);
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetCreator('Media Buying Command Center'); $pdf->SetTitle($r['title']);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->setRTL(true); $pdf->SetMargins(12, 12, 12); $pdf->SetAutoPageBreak(true, 14);
    $pdf->SetFont('dejavusans', '', 9.5);
    $pdf->AddPage();
    $pdf->writeHTML(report_html($r, $d, true), true, false, true, false, '');
    return $pdf->Output('', 'S');
}

/** Flat rows for CSV / Excel. */
function report_rows(array $r): array {
    $d = json_decode($r['data_json'], true);
    $out = [[$r['title']], ['Client', $d['client']['name']], ['Period', $d['from'], $d['to']], ['Previous', $d['prev_from'], $d['prev_to']], ['Summary', $r['summary_text']], []];
    $out[] = ['Totals', 'Currency', 'Metric', 'Value', 'Previous', 'Change %'];
    foreach ($d['totals'] as $cur => $t) foreach (['spend', 'impressions', 'link_clicks', 'link_ctr', 'link_cpc', 'cpm', 'leads', 'cpl', 'purchases', 'cpa', 'purchase_value', 'roas'] as $k) {
        $v = $t['m'][$k] ?? null; $p = $t['prev'][$k] ?? null; $ch = pct_change($v === null ? null : (float)$v, $p === null ? null : (float)$p);
        $out[] = ['', $cur, metric_label($k), $v === null ? 'N/A' : round((float)$v, 4), $p === null ? 'N/A' : round((float)$p, 4), $ch === null ? '' : round($ch * 100, 2)];
    }
    $out[] = []; $head = ['Campaign', 'Goal', 'Currency']; foreach (REPORT_COLS as $k) $head[] = metric_label($k); $out[] = $head;
    foreach ($d['campaigns'] as $c) { $row = [$c['name'], $c['goal'], $c['currency']]; foreach (REPORT_COLS as $k) $row[] = ($c['m'][$k] ?? null) === null ? 'N/A' : round((float)$c['m'][$k], 4); $out[] = $row; }
    if ($lq = $d['lead_quality'] ?? null) {
        $out[] = []; $out[] = ['Lead quality', 'Leads', 'Contacted', 'Qualified', 'Appointments', 'Won', 'Cost/Qualified', 'Cost/Sale', 'ROAS'];
        $out[] = ['', $lq['total'], $lq['contacted'], $lq['qualified'], $lq['appointments'], $lq['won'], $lq['cost_per_qualified'] ?? 'N/A', $lq['cost_per_sale'] ?? 'N/A', $lq['roas'] ?? 'N/A'];
    }
    $out[] = []; $out[] = ['Issues']; foreach ($d['issues'] as $i) $out[] = ['', $i];
    $out[] = ['Recommendations']; foreach ($d['recommendations'] as $i) $out[] = ['', $i];
    return $out;
}

function export_reports(string $kind, string $fmt): bool {
    if ($kind !== 'report') return false;
    require_perm('reports.view');
    $r = q_one('SELECT * FROM reports WHERE id = ?', [(int)in_int('id')]);
    if (!$r || !can_client((int)$r['client_id'])) throw new Forbidden('report');
    $base = 'report-' . $r['id'] . '-' . $r['period_start'];
    audit('export.report', 'report', $r['id'], ['format' => in_str('format')]);
    if (in_str('format') === 'pdf') {
        $bin = report_pdf($r);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $base . '.pdf"');
        header('Content-Length: ' . strlen($bin));
        echo $bin; exit;
    }
    $fmt === 'xlsx' ? send_xlsx($base . '.xlsx', report_rows($r), 'Report') : send_csv($base . '.csv', report_rows($r));
}
