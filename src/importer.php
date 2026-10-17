<?php
declare(strict_types=1);

/* ---------- entity upserts (shared by CSV import, Meta sync, demo seed) ---------- */
function upsert_campaign(int $accountId, string $extId, array $f, string $source): int {
    $id = q_val('SELECT id FROM campaigns WHERE ad_account_id = ? AND external_id = ?', [$accountId, $extId]);
    $f = array_filter($f, fn($v) => $v !== null && $v !== '');
    if ($id) { db_update('campaigns', (int)$id, $f + ['updated_at' => now()]); return (int)$id; }
    return db_insert('campaigns', $f + ['ad_account_id' => $accountId, 'external_id' => $extId, 'name' => $f['name'] ?? $extId, 'source' => $source, 'created_at' => now()]);
}
function upsert_adset(int $campaignId, string $extId, array $f, string $source): int {
    $id = q_val('SELECT id FROM adsets WHERE campaign_id = ? AND external_id = ?', [$campaignId, $extId]);
    $f = array_filter($f, fn($v) => $v !== null && $v !== '');
    if ($id) { db_update('adsets', (int)$id, $f + ['updated_at' => now()]); return (int)$id; }
    return db_insert('adsets', $f + ['campaign_id' => $campaignId, 'external_id' => $extId, 'name' => $f['name'] ?? $extId, 'source' => $source, 'created_at' => now()]);
}
function upsert_ad(int $adsetId, string $extId, array $f, string $source): int {
    $id = q_val('SELECT id FROM ads WHERE adset_id = ? AND external_id = ?', [$adsetId, $extId]);
    $f = array_filter($f, fn($v) => $v !== null && $v !== '');
    if ($id) { db_update('ads', (int)$id, $f + ['updated_at' => now()]); return (int)$id; }
    return db_insert('ads', $f + ['adset_id' => $adsetId, 'external_id' => $extId, 'name' => $f['name'] ?? $extId, 'source' => $source, 'created_at' => now()]);
}
/** Insert or replace one metrics row. Returns 'inserted' | 'updated'. */
function upsert_snapshot(array $r): string {
    $exists = q_val('SELECT id FROM performance_snapshots WHERE level = ? AND entity_id = ? AND date_start = ? AND date_end = ? AND breakdown_key = ? AND breakdown_value = ?',
        [$r['level'], $r['entity_id'], $r['date_start'], $r['date_end'], $r['breakdown_key'] ?? '', $r['breakdown_value'] ?? '']);
    $cols = array_merge(['ad_account_id', 'level', 'entity_id', 'date_start', 'date_end', 'breakdown_key', 'breakdown_value', 'currency', 'source', 'reach', 'result_type'], ADDITIVE);
    $row = []; foreach ($cols as $c) $row[$c] = $r[$c] ?? ($c === 'breakdown_key' || $c === 'breakdown_value' ? '' : null);
    $row['imported_at'] = now();
    if ($exists) { db_update('performance_snapshots', (int)$exists, $row); return 'updated'; }
    db_insert('performance_snapshots', $row);
    return 'inserted';
}
/** Stable external id for entities that come from a file without ids. */
function name_key(string $name): string { return 'name:' . substr(sha1(mb_strtolower(trim($name))), 0, 16); }

/* ---------- Ads Manager export import ---------- */
function header_norm(string $h): string {
    $h = norm_ar(trim($h));
    $h = preg_replace('/\((?:[a-z]{3})\)/iu', '', $h) ?? $h;   // "(EGP)"
    $h = preg_replace('/[^\p{L}\p{N}%]+/u', ' ', $h) ?? $h;
    return trim(preg_replace('/\s+/u', ' ', $h) ?? $h);
}
/** Canonical field => accepted header names (English + Arabic Ads Manager), normalised by header_norm. */
function perf_header_aliases(): array {
    $a = [
        'campaign_name' => ['campaign name', 'campaign', 'اسم الحمله', 'الحمله'],
        'campaign_id' => ['campaign id', 'معرف الحمله', 'رقم الحمله'],
        'adset_name' => ['ad set name', 'adset name', 'ad set', 'اسم مجموعه الاعلانات', 'مجموعه الاعلانات'],
        'adset_id' => ['ad set id', 'adset id', 'معرف مجموعه الاعلانات'],
        'ad_name' => ['ad name', 'ad', 'اسم الاعلان', 'الاعلان'],
        'ad_id' => ['ad id', 'معرف الاعلان'],
        'account_id' => ['account id', 'ad account id', 'معرف الحساب'],
        'day' => ['day', 'date', 'اليوم', 'التاريخ'],
        'date_start' => ['reporting starts', 'reporting start', 'بدء التقارير', 'بدايه التقارير', 'date start'],
        'date_end' => ['reporting ends', 'reporting end', 'انتهاء التقارير', 'نهايه التقارير', 'date stop', 'date end'],
        'objective' => ['objective', 'campaign objective', 'الهدف', 'هدف الحمله'],
        'status' => ['delivery', 'campaign delivery', 'ad set delivery', 'ad delivery', 'status', 'التسليم', 'الحاله'],
        'spend' => ['amount spent', 'spend', 'cost', 'المبلغ الذي تم انفاقه', 'المبلغ المنفق', 'الانفاق', 'التكلفه'],
        'impressions' => ['impressions', 'مرات الظهور', 'الظهور'],
        'reach' => ['reach', 'الوصول', 'مدي الوصول'],
        'frequency' => ['frequency', 'معدل التكرار', 'التكرار'],
        'clicks' => ['clicks all', 'clicks', 'النقرات الكل', 'النقرات'],
        'link_clicks' => ['link clicks', 'outbound clicks', 'نقرات الرابط', 'النقرات علي الرابط', 'نقرات علي الرابط'],
        'results' => ['results', 'النتايج'],
        'result_type' => ['result indicator', 'result type', 'مؤشر النتايج', 'نوع النتيجه'],
        'leads' => ['leads', 'meta leads', 'on facebook leads', 'website leads', 'leads form', 'العملاء المحتملون', 'العملاء المحتملين', 'عملاء محتملون'],
        'purchases' => ['purchases', 'website purchases', 'meta purchases', 'عمليات الشراء', 'المشتريات'],
        'purchase_value' => ['purchases conversion value', 'website purchases conversion value', 'purchase conversion value', 'purchase value', 'قيمه تحويل عمليات الشراء', 'قيمه التحويل للمشتريات', 'قيمه المشتريات'],
        'landing_page_views' => ['landing page views', 'website landing page views', 'مشاهدات الصفحه المقصوده', 'مشاهدات صفحه الهبوط'],
        'video_3s' => ['3 second video plays', '3 second video views', 'video plays 3s', 'مرات تشغيل الفيديو لمده 3 ثوان', 'مشاهدات الفيديو لمده 3 ثوان'],
        'thruplays' => ['thruplays', 'thruplay', 'مرات تشغيل thruplay'],
        'video_p25' => ['video plays at 25%', 'مرات تشغيل الفيديو بنسبه 25%'],
        'video_p50' => ['video plays at 50%', 'مرات تشغيل الفيديو بنسبه 50%'],
        'video_p75' => ['video plays at 75%', 'مرات تشغيل الفيديو بنسبه 75%'],
        'video_p100' => ['video plays at 100%', 'مرات تشغيل الفيديو بنسبه 100%'],
        'age' => ['age', 'العمر'], 'gender' => ['gender', 'النوع', 'الجنس'],
        'region' => ['region', 'المنطقه'], 'country' => ['country', 'الدوله', 'البلد'],
        'placement' => ['placement', 'موضع الاعلان', 'المواضع'], 'platform' => ['platform', 'المنصه'],
        'device' => ['impression device', 'device platform', 'device', 'الجهاز'],
        'body' => ['body', 'ad body', 'primary text', 'النص الاساسي', 'نص الاعلان'],
        'title' => ['title', 'headline', 'العنوان الرييسي', 'العنوان'],
        'cta' => ['call to action type', 'call to action', 'زر الدعوه لاتخاذ اجراء'],
        'thumbnail' => ['image url', 'thumbnail url', 'preview link', 'رابط الصوره'],
        'link_url' => ['link', 'website url', 'destination url', 'رابط الموقع'],
        'currency' => ['currency', 'العمله'],
    ];
    $out = [];
    foreach ($a as $k => $names) foreach ($names as $n) $out[header_norm($n)] = $k;
    return $out;
}
/** Map file headers to canonical fields. Returns [colIndex => field] plus detected currency from "(EGP)" headers. */
function map_headers(array $headers): array {
    $alias = perf_header_aliases(); $map = []; $cur = null;
    foreach ($headers as $i => $h) {
        $h = (string)$h;
        if (preg_match('/\(([A-Z]{3})\)/', $h, $m)) $cur ??= $m[1];
        $n = header_norm($h);
        if (isset($alias[$n]) && !in_array($alias[$n], $map, true)) $map[$i] = $alias[$n];
    }
    return [$map, $cur];
}
function parse_num(?string $v): ?float {
    $v = trim(to_latin_digits((string)$v));
    if ($v === '' || $v === '-' || $v === '—' || strtolower($v) === 'n/a') return null;
    $v = str_replace(['٬', ' ', "\u{00a0}", '%'], '', $v);
    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $v)) $v = str_replace(',', '', $v);
    elseif (preg_match('/^-?\d+,\d+$/', $v)) $v = str_replace(',', '.', $v);   // European decimal comma
    return is_numeric($v) ? (float)$v : null;
}
function parse_date(?string $v): ?string {
    $v = trim(to_latin_digits((string)$v));
    if ($v === '') return null;
    if ($d = excel_date($v)) return $d;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) $d = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m)) $d = (int)$m[1] > 12 ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    else return null;
    return valid_date($d) ? $d : null;
}
function result_type_norm(?string $t): ?string {
    $t = trim((string)$t);
    if ($t === '') return null;
    $l = strtolower($t);
    if (str_contains($l, 'lead')) return 'lead';
    if (str_contains($l, 'purchase')) return 'purchase';
    if (str_contains($l, 'landing_page')) return 'landing_page_view';
    if (str_contains($l, 'link_click')) return 'link_click';
    if (str_contains($l, 'reach')) return 'reach';
    if (str_contains($l, 'messag')) return 'messaging_conversation';
    if (str_contains($l, 'thruplay') || str_contains($l, 'video')) return 'video_view';
    if (str_contains($l, 'engagement')) return 'post_engagement';
    return mb_substr($t, 0, 60);
}

/**
 * Import an Ads Manager export into one ad account.
 * Options: date_start/date_end when the file has no dates (whole-period export).
 * Returns stats; nothing is written when there are blocking errors.
 */
function import_performance(int $accountId, array $rows, array $opt = []): array {
    $acc = q_one('SELECT * FROM ad_accounts WHERE id = ?', [$accountId]);
    if (!$acc) throw new InvalidArgumentException('الحساب الإعلاني مش موجود');
    // skip empty leading lines; the header is the first row containing a campaign column
    $hi = null;
    foreach (array_slice($rows, 0, 10, true) as $i => $r) { [$m] = map_headers($r); if (in_array('campaign_name', $m, true) || in_array('campaign_id', $m, true)) { $hi = $i; break; } }
    if ($hi === null) throw new InvalidArgumentException('مش لاقي عمود "Campaign name" أو "اسم الحملة" في الملف. صدّر التقرير من Ads Manager بالأعمدة الافتراضية.');
    [$map, $fileCur] = map_headers($rows[$hi]);
    $fields = array_flip($map);
    if ($fileCur && $fileCur !== $acc['currency'])
        throw new InvalidArgumentException("عملة الملف ($fileCur) مختلفة عن عملة الحساب ({$acc['currency']}). اختار الحساب الصح — الأرقام بعملات مختلفة مينفعش تتخلط.");
    $hasDates = isset($fields['day']) || isset($fields['date_start']);
    if (!$hasDates && (!valid_date($opt['date_start'] ?? null) || !valid_date($opt['date_end'] ?? null)))
        throw new InvalidArgumentException('الملف مفيهوش تواريخ. حدد بداية ونهاية الفترة اللي التقرير بيغطيها.');
    $level = isset($fields['ad_name']) || isset($fields['ad_id']) ? 'ad' : (isset($fields['adset_name']) || isset($fields['adset_id']) ? 'adset' : 'campaign');
    $bkeys = array_values(array_filter(['age', 'gender', 'region', 'country', 'placement', 'platform', 'device'], fn($k) => isset($fields[$k])));

    $get = function (array $r, string $f) use ($fields) { return isset($fields[$f]) ? trim((string)($r[$fields[$f]] ?? '')) : ''; };
    $stats = ['level' => $level, 'rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'breakdowns' => $bkeys, 'currency' => $acc['currency']];
    $seenKeys = [];
    tx(function () use ($rows, $hi, $get, $acc, $accountId, $level, $bkeys, $opt, &$stats, &$seenKeys, $fields) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($x) => trim((string)$x) !== '')) continue;
            $line = $i + 1;
            $cname = $get($r, 'campaign_name'); $cid = $get($r, 'campaign_id');
            if ($cname === '' && $cid === '') { $stats['skipped']++; continue; }
            if (preg_match('/^(total|results|الاجمالي|الإجمالي|النتائج)/iu', $cname)) { $stats['skipped']++; continue; }   // summary row
            $stats['rows']++;
            if (isset($fields['account_id']) && ($aid = preg_replace('/\D/', '', $get($r, 'account_id'))) && $acc['external_id'] && $aid !== $acc['external_id']) {
                $stats['errors'][] = "سطر $line: تبع حساب تاني ($aid)"; $stats['skipped']++; continue;
            }
            if ($c = $get($r, 'currency')) if (strtoupper($c) !== $acc['currency']) { $stats['errors'][] = "سطر $line: عملة $c مختلفة عن عملة الحساب"; $stats['skipped']++; continue; }
            $ds = parse_date($get($r, 'day')) ?? parse_date($get($r, 'date_start')) ?? ($opt['date_start'] ?? null);
            $de = isset($fields['day']) ? $ds : (parse_date($get($r, 'date_end')) ?? ($opt['date_end'] ?? $ds));
            if (!$ds || !$de || $de < $ds) { $stats['errors'][] = "سطر $line: تاريخ غير صالح"; $stats['skipped']++; continue; }

            $campId = upsert_campaign($accountId, $cid !== '' ? preg_replace('/\D/', '', $cid) : name_key($cname),
                ['name' => $cname ?: null, 'objective' => $get($r, 'objective') ?: null, 'status' => $level === 'campaign' ? ($get($r, 'status') ?: null) : null], 'csv');
            $entity = $campId;
            if ($level !== 'campaign') {
                $asn = $get($r, 'adset_name'); $asid = $get($r, 'adset_id');
                $entity = $adsetId = upsert_adset($campId, $asid !== '' ? preg_replace('/\D/', '', $asid) : name_key($cname . '|' . $asn), ['name' => $asn ?: null, 'status' => $level === 'adset' ? ($get($r, 'status') ?: null) : null], 'csv');
                if ($level === 'ad') {
                    $an = $get($r, 'ad_name'); $adid = $get($r, 'ad_id');
                    $entity = $adId = upsert_ad($adsetId, $adid !== '' ? preg_replace('/\D/', '', $adid) : name_key($cname . '|' . $asn . '|' . $an), ['name' => $an ?: null, 'status' => $get($r, 'status') ?: null], 'csv');
                    if ($get($r, 'body') !== '' || $get($r, 'title') !== '' || $get($r, 'thumbnail') !== '') creative_attach_from_import($acc, $adId, $an, $r, $get);
                }
            }
            $bk = implode(',', $bkeys); $bv = implode(',', array_map(fn($k) => $get($r, $k) ?: 'unknown', $bkeys));
            $key = "$level|$entity|$ds|$de|$bk|$bv";
            if (isset($seenKeys[$key])) { $stats['errors'][] = "سطر $line: مكرر في نفس الملف (اتجاهل)"; $stats['skipped']++; continue; }
            $seenKeys[$key] = true;
            $snap = ['ad_account_id' => $accountId, 'level' => $level, 'entity_id' => $entity, 'date_start' => $ds, 'date_end' => $de,
                'breakdown_key' => $bk, 'breakdown_value' => $bv, 'currency' => $acc['currency'], 'source' => 'csv',
                'reach' => parse_num($get($r, 'reach')), 'result_type' => result_type_norm($get($r, 'result_type'))];
            foreach (ADDITIVE as $f) $snap[$f] = isset($fields[$f]) ? parse_num($get($r, $f)) : null;
            // Results column without a type: infer only when it's unambiguous
            if ($snap['result_type'] === null && $snap['results'] !== null) {
                if ($snap['leads'] !== null && abs($snap['leads'] - $snap['results']) < 0.001) $snap['result_type'] = 'lead';
                elseif ($snap['purchases'] !== null && abs($snap['purchases'] - $snap['results']) < 0.001) $snap['result_type'] = 'purchase';
            }
            if ($snap['result_type'] === 'lead' && $snap['leads'] === null) $snap['leads'] = $snap['results'];
            if ($snap['result_type'] === 'purchase' && $snap['purchases'] === null) $snap['purchases'] = $snap['results'];
            $stats[upsert_snapshot($snap)]++;
        }
    });
    return $stats;
}

function creative_attach_from_import(array $acc, int $adId, string $name, array $r, callable $get): void {
    $existing = q_val('SELECT creative_asset_id FROM ads WHERE id = ?', [$adId]);
    $f = ['name' => $name ?: null, 'body' => $get($r, 'body') ?: null, 'title' => $get($r, 'title') ?: null, 'cta' => $get($r, 'cta') ?: null,
        'thumbnail_url' => filter_var($get($r, 'thumbnail'), FILTER_VALIDATE_URL) ?: null, 'link_url' => filter_var($get($r, 'link_url'), FILTER_VALIDATE_URL) ?: null];
    $f = array_filter($f, fn($v) => $v !== null);
    if ($existing) { db_update('creative_assets', (int)$existing, $f + ['updated_at' => now()]); return; }
    $cid = db_insert('creative_assets', $f + ['client_id' => $acc['client_id'], 'ad_account_id' => $acc['id'], 'type' => 'unknown', 'created_at' => now()]);
    q('UPDATE ads SET creative_asset_id = ? WHERE id = ?', [$cid, $adId]);
}

function record_import(int $clientId, string $kind, string $filename, array $stats): int {
    return db_insert('import_batches', ['client_id' => $clientId, 'kind' => $kind, 'filename' => mb_substr($filename, 0, 200),
        'rows_total' => $stats['rows'] ?? 0, 'inserted' => $stats['inserted'] ?? 0, 'updated' => $stats['updated'] ?? 0, 'skipped' => $stats['skipped'] ?? 0,
        'errors_json' => json_encode(array_slice($stats['errors'] ?? [], 0, 200), JSON_UNESCAPED_UNICODE), 'created_by' => uid(), 'created_at' => now()]);
}
