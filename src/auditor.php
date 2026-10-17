<?php
declare(strict_types=1);

const AUDIT_STATUSES = ['pass' => 'Pass', 'warning' => 'Warning', 'fail' => 'Fail', 'not_checked' => 'Not Checked'];

/** Default pre-launch checklist. "auto" names a check that can run on data we actually have. */
function default_audit_items(): array {
    return [
        ['key' => 'objective', 'cat' => 'الهدف', 'label' => 'الهدف الإعلاني مناسب للنتيجة المطلوبة (Leads / Sales / Traffic)', 'auto' => 'objective'],
        ['key' => 'budget', 'cat' => 'الميزانية', 'label' => 'الميزانية اليومية/الإجمالية محددة ومناسبة', 'auto' => 'budget'],
        ['key' => 'schedule', 'cat' => 'الميزانية', 'label' => 'تاريخ البداية والنهاية صح', 'auto' => 'schedule'],
        ['key' => 'audience', 'cat' => 'الجمهور', 'label' => 'الجمهور والموقع الجغرافي والأعمار مضبوطين', 'auto' => 'targeting'],
        ['key' => 'placements', 'cat' => 'الجمهور', 'label' => 'الـ Placements مناسبة للكريتيف (Reels / Feed / Stories)', 'auto' => null],
        ['key' => 'tracking', 'cat' => 'التتبع', 'label' => 'إعدادات التحويل والـ Conversion Event صح', 'auto' => null],
        ['key' => 'pixel', 'cat' => 'التتبع', 'label' => 'الـ Pixel / Conversions API شغال وبيبعت الأحداث', 'auto' => null],
        ['key' => 'utm', 'cat' => 'التتبع', 'label' => 'UTM Parameters موجودة على كل الروابط', 'auto' => 'utm'],
        ['key' => 'landing', 'cat' => 'الروابط', 'label' => 'روابط صفحات الهبوط صحيحة وبتبدأ بـ https', 'auto' => 'links'],
        ['key' => 'lead_form', 'cat' => 'الفورم', 'label' => 'فورم الليدز فيه الحقول المطلوبة وأسئلة تأهيل', 'auto' => null],
        ['key' => 'copy', 'cat' => 'الكريتيف', 'label' => 'النصوص مفيهاش أخطاء أو نص مؤقت', 'auto' => 'copy'],
        ['key' => 'data', 'cat' => 'البيانات', 'label' => 'الحملة فيها إعلانات وكريتيف مربوط', 'auto' => 'has_ads'],
        ['key' => 'access', 'cat' => 'الصلاحيات', 'label' => 'عندنا صلاحية وصول للحساب والبيانات بتتزامن', 'auto' => 'access'],
    ];
}

function audit_template_items(?int $templateId): array {
    if ($templateId && ($t = q_val('SELECT items_json FROM audit_templates WHERE id = ?', [$templateId]))) {
        $items = json_decode((string)$t, true);
        if (is_array($items) && $items) return $items;
    }
    return default_audit_items();
}

/** Run every automatic check that our data allows. Returns key => [status, note]. Unknown → not_checked, never a guess. */
function audit_auto_checks(?int $campaignId): array {
    $nc = fn(string $why) => ['not_checked', $why];
    if (!$campaignId) return array_fill_keys(['objective', 'budget', 'schedule', 'targeting', 'utm', 'links', 'copy', 'has_ads', 'access'], $nc('اختار حملة عشان الفحص الآلي يشتغل'));
    $c = q_one('SELECT cp.*, a.sync_enabled, a.last_sync_at, a.last_sync_error, a.client_id FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE cp.id = ?', [$campaignId]);
    $adsets = q_all('SELECT * FROM adsets WHERE campaign_id = ?', [$campaignId]);
    $creatives = q_all('SELECT ca.* FROM ads d JOIN adsets s ON s.id = d.adset_id JOIN creative_assets ca ON ca.id = d.creative_asset_id WHERE s.campaign_id = ?', [$campaignId]);
    $adCount = (int)q_val('SELECT COUNT(*) FROM ads d JOIN adsets s ON s.id = d.adset_id WHERE s.campaign_id = ?', [$campaignId]);
    $r = [];

    $goal = goal_of($c['objective']);
    $r['objective'] = !$c['objective'] ? $nc('الهدف مش متسجل في البيانات (ارفع عمود Objective أو اربط Meta)')
        : ($goal === 'other' ? ['warning', 'هدف غير معتاد: ' . $c['objective']] : ['pass', 'الهدف: ' . $c['objective'] . ' (' . goal_labels()[$goal] . ')']);

    $hasPlan = (bool)q_val('SELECT 1 FROM budgets WHERE campaign_id = ? OR (client_id = ? AND campaign_id IS NULL)', [$campaignId, $c['client_id']]);
    $adsetBudget = array_filter($adsets, fn($s) => $s['daily_budget'] || $s['lifetime_budget']);
    if ($c['daily_budget'] || $c['lifetime_budget'] || $adsetBudget) $r['budget'] = ['pass', 'الميزانية محددة في الحملة' . ($hasPlan ? ' ومربوطة بخطة ميزانية' : ' (مفيش خطة ميزانية في Budget Planner)')];
    elseif ($hasPlan) $r['budget'] = ['warning', 'فيه خطة ميزانية، بس ميزانية الحملة نفسها مش ظاهرة في البيانات'];
    else $r['budget'] = $nc('ميزانية الحملة مش متاحة في البيانات');

    if (!$c['start_time']) $r['schedule'] = $nc('تاريخ البداية مش متاح');
    elseif ($c['stop_time'] && substr($c['stop_time'], 0, 10) < today() && strtoupper((string)$c['effective_status']) === 'ACTIVE') $r['schedule'] = ['fail', 'تاريخ النهاية عدّى والحملة لسه Active'];
    elseif ($c['stop_time'] && $c['stop_time'] < $c['start_time']) $r['schedule'] = ['fail', 'النهاية قبل البداية'];
    else $r['schedule'] = ['pass', 'البداية ' . substr($c['start_time'], 0, 10) . ($c['stop_time'] ? ' — النهاية ' . substr($c['stop_time'], 0, 10) : ' — مستمرة')];

    $tg = array_filter(array_map(fn($s) => json_decode((string)$s['targeting_json'], true), $adsets));
    if (!$tg) $r['targeting'] = $nc('إعدادات الاستهداف مش متاحة (محتاجة ربط Meta API)');
    else {
        $noGeo = count(array_filter($tg, fn($t) => empty($t['geo_locations'])));
        $r['targeting'] = $noGeo ? ['fail', "$noGeo Ad Set من غير موقع جغرافي"] : ['pass', count($tg) . ' Ad Set ليهم استهداف جغرافي'];
    }

    $links = array_values(array_filter(array_column($creatives, 'link_url')));
    if (!$links) { $r['utm'] = $nc('روابط الإعلانات مش متاحة في البيانات'); $r['links'] = $nc('روابط الإعلانات مش متاحة في البيانات'); }
    else {
        $noUtm = array_filter($links, fn($l) => !str_contains((string)parse_url($l, PHP_URL_QUERY), 'utm_'));
        $r['utm'] = $noUtm ? ['warning', count($noUtm) . ' من ' . count($links) . ' رابط من غير UTM'] : ['pass', 'كل الروابط فيها UTM'];
        $bad = array_filter($links, fn($l) => !filter_var($l, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($l), 'https://'));
        $r['links'] = $bad ? ['fail', count($bad) . ' رابط مش صالح أو مش https'] : ['pass', 'صيغة الروابط سليمة (اتأكد إن الصفحات بتفتح بنفسك — ده مش فحص اتصال)'];
    }

    $bodies = array_filter($creatives, fn($x) => $x['body'] !== null || $x['title'] !== null);
    if (!$bodies) $r['copy'] = $nc('نصوص الإعلانات مش متاحة في البيانات');
    else {
        $issues = [];
        foreach ($bodies as $x) {
            $t = ($x['title'] ?? '') . ' ' . ($x['body'] ?? '');
            if (preg_match('/(lorem|ipsum|xxx|todo|\{\{|\[\s*product|placeholder|ضع النص)/iu', $t)) $issues[] = $x['name'];
        }
        $empty = count(array_filter($creatives, fn($x) => trim((string)$x['body']) === ''));
        $r['copy'] = $issues ? ['fail', 'نص مؤقت في: ' . implode('، ', array_slice($issues, 0, 3))] : ($empty ? ['warning', "$empty إعلان من غير Primary Text"] : ['pass', count($bodies) . ' إعلان اتفحص نصه']);
    }

    $r['has_ads'] = $adCount ? ($creatives ? ['pass', "$adCount إعلان، " . count($creatives) . ' مربوط بكريتيف'] : ['warning', "$adCount إعلان من غير بيانات كريتيف"]) : ['fail', 'مفيش إعلانات مسجلة للحملة'];

    if (!$c['sync_enabled']) $r['access'] = $nc('الحساب مش مربوط بـ Meta API — البيانات يدوي/CSV');
    elseif ($c['last_sync_error']) $r['access'] = ['fail', 'آخر مزامنة فشلت: ' . mb_substr($c['last_sync_error'], 0, 120)];
    elseif ($c['last_sync_at'] && strtotime($c['last_sync_at'] . ' UTC') > time() - 2 * 86400) $r['access'] = ['pass', 'آخر مزامنة ناجحة ' . fmt_dt($c['last_sync_at'])];
    else $r['access'] = ['warning', 'مفيش مزامنة ناجحة من أكتر من يومين'];
    return $r;
}

/** Build a new audit: auto results where available, everything else Not Checked. */
function audit_create(int $clientId, ?int $campaignId, ?int $templateId, string $name): int {
    if (!can_client($clientId)) throw new Forbidden('client');
    if ($campaignId && campaign_client_id($campaignId) !== $clientId) throw new InvalidArgumentException('الحملة مش تبع العميل ده');
    $auto = audit_auto_checks($campaignId);
    $results = [];
    foreach (audit_template_items($templateId) as $it) {
        [$st, $note] = $it['auto'] && isset($auto[$it['auto']]) ? $auto[$it['auto']] : ['not_checked', 'فحص يدوي'];
        $results[] = ['key' => $it['key'], 'cat' => $it['cat'], 'label' => $it['label'], 'auto' => $it['auto'], 'status' => $st, 'note' => $note,
            'source' => $it['auto'] && $st !== 'not_checked' ? 'auto' : 'manual'];
    }
    $id = db_insert('campaign_audits', ['client_id' => $clientId, 'campaign_id' => $campaignId, 'template_id' => $templateId, 'name' => $name ?: 'مراجعة ' . today(),
        'results_json' => json_encode($results, JSON_UNESCAPED_UNICODE), 'created_by' => uid(), 'created_at' => now()]);
    audit('audit.create', 'campaign_audit', $id);
    return $id;
}
function audit_summary(array $results): array {
    $c = array_fill_keys(array_keys(AUDIT_STATUSES), 0);
    foreach ($results as $r) $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
    return $c;
}
function audit_status_badge(string $s): string {
    return ['pass' => '<span class="badge b-ok">Pass</span>', 'warning' => '<span class="badge b-warn">Warning</span>', 'fail' => '<span class="badge b-bad">Fail</span>'][$s] ?? '<span class="badge">Not Checked</span>';
}
