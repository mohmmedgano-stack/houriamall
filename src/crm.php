<?php
declare(strict_types=1);

function dedupe_rules(): array {
    $r = json_decode((string)setting('dedupe_rules', ''), true);
    return array_merge(['phone' => true, 'email' => true, 'window_days' => 90, 'action' => 'flag'], is_array($r) ? $r : []);
}
function stages(): array { static $s = null; if ($s === null || !empty($GLOBALS['CC_TESTING'])) $s = q_all('SELECT * FROM pipeline_stages ORDER BY position'); return $s; }
function stage(int $id): ?array { foreach (stages() as $s) if ((int)$s['id'] === $id) return $s; return null; }
function first_stage_id(): int { return (int)stages()[0]['id']; }
function stage_by_flag(string $flag): ?array { foreach (stages() as $s) if ((int)$s[$flag] === 1) return $s; return null; }

/** Earlier lead (same client) that matches by phone or email within the configured window. */
function find_duplicate(int $clientId, ?string $phoneNorm, ?string $emailNorm, ?string $receivedAt = null, ?int $excludeId = null): ?array {
    $r = dedupe_rules();
    $cond = []; $p = [$clientId];
    if ($r['phone'] && $phoneNorm) { $cond[] = 'phone_norm = ?'; $p[] = $phoneNorm; }
    if ($r['email'] && $emailNorm) { $cond[] = 'email_norm = ?'; $p[] = $emailNorm; }
    if (!$cond) return null;
    $sql = 'SELECT * FROM leads WHERE client_id = ? AND (' . implode(' OR ', $cond) . ') AND duplicate_of IS NULL';
    if ($excludeId) { $sql .= ' AND id <> ?'; $p[] = $excludeId; }
    if ((int)$r['window_days'] > 0) { $sql .= ' AND received_at >= ?'; $p[] = gmdate('Y-m-d H:i:s', strtotime(($receivedAt ?? now()) . ' UTC') - (int)$r['window_days'] * 86400); }
    return q_one($sql . ' ORDER BY received_at ASC LIMIT 1', $p);
}

/**
 * Create a lead. Validates, normalises, de-duplicates, logs and emits lead.created.
 * Returns ['id' => int|null, 'duplicate_of' => int|null, 'merged' => bool, 'existing' => bool]
 */
function lead_create(int $clientId, array $d, string $source = 'manual', ?int $actorId = null): array {
    $phone = trim((string)($d['phone'] ?? '')); $email = trim((string)($d['email'] ?? '')); $name = trim((string)($d['full_name'] ?? ''));
    if ($name === '' && $phone === '' && $email === '') throw new InvalidArgumentException('الليد محتاج اسم أو تليفون أو إيميل على الأقل');
    if ($email !== '' && !norm_email($email)) throw new InvalidArgumentException('الإيميل مش صحيح: ' . $email);
    $ext = trim((string)($d['external_id'] ?? '')) ?: null;
    if ($ext && ($ex = q_val('SELECT id FROM leads WHERE client_id = ? AND source = ? AND external_id = ?', [$clientId, $source, $ext]))) return ['id' => (int)$ex, 'duplicate_of' => null, 'merged' => false, 'existing' => true];
    $received = !empty($d['received_at']) && strtotime((string)$d['received_at']) ? gmdate('Y-m-d H:i:s', strtotime((string)$d['received_at'])) : now();
    $pn = norm_phone($phone); $en = norm_email($email);
    $dup = find_duplicate($clientId, $pn, $en, $received);
    if ($dup && dedupe_rules()['action'] === 'merge') {
        lead_activity((int)$dup['id'], 'duplicate', null, 'وصل نفس الليد تاني من ' . $source . ($d['form_name'] ?? '' ? ' (' . $d['form_name'] . ')' : ''), ['data' => array_intersect_key($d, array_flip(['full_name', 'phone', 'email', 'campaign_id', 'utm_campaign']))], $actorId);
        return ['id' => null, 'duplicate_of' => (int)$dup['id'], 'merged' => true, 'existing' => false];
    }
    $ids = lead_resolve_ads($clientId, $d);
    $row = ['client_id' => $clientId, 'source' => $source, 'external_id' => $ext, 'full_name' => mb_substr($name, 0, 150) ?: null,
        'phone' => mb_substr($phone, 0, 40) ?: null, 'phone_norm' => $pn, 'email' => $email ?: null, 'email_norm' => $en,
        'city' => mb_substr(trim((string)($d['city'] ?? '')), 0, 80) ?: null, 'product' => mb_substr(trim((string)($d['product'] ?? '')), 0, 120) ?: null,
        'form_name' => mb_substr(trim((string)($d['form_name'] ?? '')), 0, 150) ?: null,
        'stage_id' => first_stage_id(), 'duplicate_of' => $dup ? (int)$dup['id'] : null, 'received_at' => $received, 'created_at' => now(),
        'extra_json' => !empty($d['extra']) ? json_encode($d['extra'], JSON_UNESCAPED_UNICODE) : null] + $ids;
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $u) $row[$u] = mb_substr(trim((string)($d[$u] ?? '')), 0, 200) ?: null;
    $id = db_insert('leads', $row);
    lead_activity($id, 'created', null, 'اتسجل الليد من ' . $source . ($dup ? ' — مكرر لليد #' . $dup['id'] : ''), [], $actorId);
    if (!$dup) emit_event('lead.created', $clientId, (string)$id, ['lead_id' => $id, 'source' => $source, 'campaign_id' => $row['campaign_id']]);
    return ['id' => $id, 'duplicate_of' => $dup ? (int)$dup['id'] : null, 'merged' => false, 'existing' => false];
}

/** Link a lead to campaign/adset/ad of the same client using ids or names that came with it. */
function lead_resolve_ads(int $clientId, array $d): array {
    $out = ['campaign_id' => null, 'adset_id' => null, 'ad_id' => null];
    $acc = 'a.client_id = ' . (int)$clientId;
    if (!empty($d['campaign_id']) && is_int($d['campaign_id'])) { if (campaign_client_id($d['campaign_id']) === $clientId) $out['campaign_id'] = $d['campaign_id']; }
    $ext = preg_replace('/\D/', '', (string)($d['campaign_ext'] ?? ''));
    $cname = trim((string)($d['campaign_name'] ?? $d['utm_campaign'] ?? ''));
    if (!$out['campaign_id'] && $ext) $out['campaign_id'] = q_val("SELECT cp.id FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE $acc AND cp.external_id = ?", [$ext]);
    if (!$out['campaign_id'] && $cname !== '') $out['campaign_id'] = q_val("SELECT cp.id FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id WHERE $acc AND lower(cp.name) = lower(?) LIMIT 1", [$cname]);
    if ($out['campaign_id']) {
        $out['campaign_id'] = (int)$out['campaign_id'];
        $asn = trim((string)($d['adset_name'] ?? '')); $asx = preg_replace('/\D/', '', (string)($d['adset_ext'] ?? ''));
        if ($asx || $asn !== '') $out['adset_id'] = q_val('SELECT id FROM adsets WHERE campaign_id = ? AND (external_id = ? OR lower(name) = lower(?)) LIMIT 1', [$out['campaign_id'], $asx ?: '-', $asn]);
        $an = trim((string)($d['ad_name'] ?? $d['utm_content'] ?? '')); $ax = preg_replace('/\D/', '', (string)($d['ad_ext'] ?? ''));
        if ($ax || $an !== '') $out['ad_id'] = q_val('SELECT d.id FROM ads d JOIN adsets s ON s.id = d.adset_id WHERE s.campaign_id = ? AND (d.external_id = ? OR lower(d.name) = lower(?)) LIMIT 1', [$out['campaign_id'], $ax ?: '-', $an]);
        if ($out['ad_id'] && !$out['adset_id']) $out['adset_id'] = q_val('SELECT adset_id FROM ads WHERE id = ?', [$out['ad_id']]);
        foreach (['adset_id', 'ad_id'] as $k) $out[$k] = $out[$k] === null ? null : (int)$out[$k];
    }
    return $out;
}

function lead_activity(int $leadId, string $type, ?string $outcome, ?string $body, array $meta = [], ?int $userId = null): int {
    return db_insert('lead_activities', ['lead_id' => $leadId, 'user_id' => $userId ?? uid(), 'type' => $type, 'outcome' => $outcome,
        'body' => $body !== null ? mb_substr($body, 0, 4000) : null, 'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now()]);
}

const CONTACT_TYPES = ['call', 'whatsapp', 'meeting', 'email', 'sms'];
function activity_types(): array { return ['call' => 'مكالمة', 'whatsapp' => 'واتساب', 'meeting' => 'مقابلة', 'email' => 'إيميل', 'sms' => 'رسالة', 'note' => 'ملاحظة', 'appointment' => 'تحديد معاد']; }
function call_outcomes(): array { return ['answered' => 'رد', 'no_answer' => 'مردش', 'busy' => 'مشغول', 'wrong_number' => 'رقم غلط', 'callback' => 'هيكلمنا تاني', 'not_interested' => 'مش مهتم']; }

/** Log a call / note / appointment. Sets first contact time and the next follow-up. */
function lead_log(int $leadId, string $type, ?string $outcome, string $body, ?string $nextFollowUp = null, ?string $appointmentAt = null): void {
    if (!isset(activity_types()[$type])) throw new InvalidArgumentException('نوع نشاط غير معروف');
    if ($outcome !== null && $outcome !== '' && !isset(call_outcomes()[$outcome])) throw new InvalidArgumentException('نتيجة غير معروفة');
    $lead = q_one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    $f = ['updated_at' => now()];
    if (in_array($type, CONTACT_TYPES, true) && !$lead['first_contact_at']) $f['first_contact_at'] = now();
    if ($nextFollowUp !== null) $f['next_follow_up_at'] = $nextFollowUp === '' ? null : gmdate('Y-m-d H:i:s', strtotime($nextFollowUp . ' ' . app_tz()));
    if ($type === 'appointment' || $appointmentAt) $f['appointment_at'] = $appointmentAt ? gmdate('Y-m-d H:i:s', strtotime($appointmentAt . ' ' . app_tz())) : now();
    db_update('leads', $leadId, $f);
    lead_activity($leadId, $type, $outcome ?: null, $body, array_filter(['next_follow_up' => $nextFollowUp, 'appointment' => $appointmentAt]));
    // a contact attempt moves a brand-new lead to the first "contacted" stage
    if (in_array($type, CONTACT_TYPES, true) && ($outcome === 'answered' || $type !== 'call') && (int)$lead['stage_id'] === first_stage_id() && ($c = stage_by_flag('is_contacted')))
        lead_set_stage($leadId, (int)$c['id']);
}

/** Move a lead to a stage; records timestamps used by Lead Quality and emits events. */
function lead_set_stage(int $leadId, int $stageId, array $extra = []): void {
    $lead = q_one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    $st = stage($stageId);
    if (!$lead || !$st) throw new InvalidArgumentException('مرحلة غير معروفة');
    if ((int)$lead['stage_id'] === $stageId && !$extra) return;
    $f = ['stage_id' => $stageId, 'updated_at' => now()];
    if ($st['is_contacted'] && !$lead['first_contact_at']) $f['first_contact_at'] = now();
    if ($st['is_qualified'] && !$lead['qualified_at']) $f['qualified_at'] = now();
    if ($st['is_won']) {
        $f['won_at'] = $lead['won_at'] ?: now();
        $v = $extra['deal_value'] ?? null;
        if ($v !== null && $v !== '') { $v = parse_num((string)$v); if ($v === null || $v < 0) throw new InvalidArgumentException('قيمة البيع مش صحيحة'); $f['deal_value'] = $v; }
        $f['deal_currency'] = $extra['deal_currency'] ?? $lead['deal_currency'] ?? (string)q_val('SELECT currency FROM clients WHERE id = ?', [$lead['client_id']]);
        $f['next_follow_up_at'] = null; $f['lost_at'] = null; $f['lost_reason'] = null;
    } elseif ($st['is_lost']) {
        $f['lost_at'] = now(); $f['lost_reason'] = mb_substr(trim((string)($extra['lost_reason'] ?? '')), 0, 200) ?: null; $f['next_follow_up_at'] = null;
    } else { $f['won_at'] = null; $f['lost_at'] = null; }
    db_update('leads', $leadId, $f);
    $from = stage((int)$lead['stage_id']);
    lead_activity($leadId, 'stage_change', null, ($from['name'] ?? '?') . ' ← ' . $st['name'] . (!empty($f['lost_reason']) ? ' (' . $f['lost_reason'] . ')' : '') . (isset($f['deal_value']) ? ' — قيمة ' . $f['deal_value'] : ''), ['from' => $lead['stage_id'], 'to' => $stageId]);
    emit_event('lead.stage_changed', (int)$lead['client_id'], (string)$leadId, ['lead_id' => $leadId, 'from' => (int)$lead['stage_id'], 'to' => $stageId]);
    if ($st['is_won'] && !$lead['won_at']) emit_event('lead.won', (int)$lead['client_id'], (string)$leadId, ['lead_id' => $leadId, 'deal_value' => $f['deal_value'] ?? $lead['deal_value'], 'campaign_id' => $lead['campaign_id']]);
}

/** Users who may own leads of this client (active, can edit CRM, see the client). */
function assignable_users(int $clientId): array {
    return array_values(array_filter(client_audience($clientId), fn($u) => can('crm.edit', $u) && !in_array($u['role'], ['viewer'], true)));
}
function lead_assign(int $leadId, ?int $userId, ?int $by = null): void {
    $lead = q_one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    if ($userId !== null && !in_array($userId, array_map(fn($u) => (int)$u['id'], assignable_users((int)$lead['client_id'])), true))
        throw new InvalidArgumentException('المستخدم ده مش مسموح له بليدز العميل ده');
    if ((int)($lead['owner_user_id'] ?? 0) === (int)$userId) return;
    q('UPDATE lead_assignments SET unassigned_at = ? WHERE lead_id = ? AND unassigned_at IS NULL', [now(), $leadId]);
    if ($userId) db_insert('lead_assignments', ['lead_id' => $leadId, 'user_id' => $userId, 'assigned_by' => $by ?? uid(), 'assigned_at' => now()]);
    db_update('leads', $leadId, ['owner_user_id' => $userId, 'updated_at' => now()]);
    $name = $userId ? (string)q_val('SELECT name FROM users WHERE id = ?', [$userId]) : 'محدش';
    lead_activity($leadId, 'assign', null, 'اتسند لـ ' . $name, ['user_id' => $userId], $by ?? uid());
    if ($userId && $userId !== ($by ?? uid())) notify($userId, 'ليد جديد مسند ليك', ($lead['full_name'] ?: $lead['phone'] ?: 'ليد #' . $leadId), '?r=lead&id=' . $leadId);
}
/** Round-robin: the assignable user with the fewest open leads for this client. */
function next_assignee(int $clientId): ?int {
    $users = array_filter(assignable_users($clientId), fn($u) => $u['role'] === 'sales');
    if (!$users) $users = assignable_users($clientId);
    if (!$users) return null;
    $best = null; $min = PHP_INT_MAX;
    foreach ($users as $u) {
        $n = (int)q_val('SELECT COUNT(*) FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id WHERE l.owner_user_id = ? AND l.client_id = ? AND s.is_won = 0 AND s.is_lost = 0', [$u['id'], $clientId]);
        if ($n < $min) { $min = $n; $best = (int)$u['id']; }
    }
    return $best;
}

/* ---------- import ---------- */
function lead_header_aliases(): array {
    $a = ['full_name' => ['full name', 'name', 'full_name', 'الاسم', 'الاسم بالكامل', 'اسم العميل'], 'phone' => ['phone', 'phone number', 'phone_number', 'mobile', 'رقم الهاتف', 'التليفون', 'الموبايل', 'رقم الموبايل', 'الهاتف'],
        'email' => ['email', 'e mail', 'البريد الالكتروني', 'الايميل'], 'city' => ['city', 'المدينه', 'المحافظه'], 'product' => ['product', 'المنتج', 'المشروع'],
        'campaign_name' => ['campaign name', 'campaign_name', 'اسم الحمله'], 'campaign_ext' => ['campaign id', 'campaign_id'], 'adset_name' => ['adset name', 'ad set name', 'adset_name'], 'adset_ext' => ['adset id', 'adset_id', 'ad set id'],
        'ad_name' => ['ad name', 'ad_name', 'اسم الاعلان'], 'ad_ext' => ['ad id', 'ad_id'], 'form_name' => ['form name', 'form_name', 'اسم النموذج'],
        'external_id' => ['id', 'lead id', 'lead_id'], 'received_at' => ['created time', 'created_time', 'date', 'التاريخ', 'تاريخ الانشاء'],
        'utm_source' => ['utm source', 'utm_source'], 'utm_medium' => ['utm medium', 'utm_medium'], 'utm_campaign' => ['utm campaign', 'utm_campaign'], 'utm_content' => ['utm content', 'utm_content'], 'utm_term' => ['utm term', 'utm_term'],
        'stage' => ['stage', 'status', 'المرحله', 'الحاله'], 'owner_email' => ['owner', 'owner email', 'المسؤول']];
    $out = [];
    foreach ($a as $k => $names) foreach ($names as $n) $out[header_norm(str_replace('_', ' ', $n))] = $k;
    return $out;
}
/** Import leads from CSV/XLSX rows (Meta lead form export or any sheet with name/phone/email). */
function leads_import(int $clientId, array $rows, ?bool $autoAssign = false): array {
    $alias = lead_header_aliases(); $map = []; $hi = null;
    foreach (array_slice($rows, 0, 10, true) as $i => $r) {
        $m = [];
        foreach ($r as $ci => $h) { $n = header_norm(str_replace('_', ' ', (string)$h)); if (isset($alias[$n]) && !in_array($alias[$n], $m, true)) $m[$ci] = $alias[$n]; }
        if (array_intersect($m, ['full_name', 'phone', 'email'])) { $map = $m; $hi = $i; break; }
    }
    if ($hi === null) throw new InvalidArgumentException('مش لاقي أعمدة الاسم أو التليفون أو الإيميل في الملف');
    $stagesByName = []; foreach (stages() as $s) $stagesByName[mb_strtolower($s['name'])] = (int)$s['id'];
    $st = ['rows' => 0, 'inserted' => 0, 'duplicates' => 0, 'merged' => 0, 'existing' => 0, 'skipped' => 0, 'errors' => []];
    tx(function () use ($rows, $hi, $map, $clientId, $autoAssign, $stagesByName, &$st) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($x) => trim((string)$x) !== '')) continue;
            $st['rows']++;
            $d = []; foreach ($map as $ci => $k) $d[$k] = trim((string)($r[$ci] ?? ''));
            if (isset($d['phone'])) $d['phone'] = preg_replace('/^p:/', '', $d['phone']);   // Meta exports prefix phones with "p:"
            if (isset($d['received_at']) && ($x = excel_date($d['received_at']))) $d['received_at'] = $x;
            try {
                $res = lead_create($clientId, $d, 'import');
                if ($res['existing']) { $st['existing']++; continue; }
                if ($res['merged']) { $st['merged']++; continue; }
                $st['inserted']++;
                if ($res['duplicate_of']) $st['duplicates']++;
                if (!empty($d['stage']) && isset($stagesByName[mb_strtolower($d['stage'])])) lead_set_stage($res['id'], $stagesByName[mb_strtolower($d['stage'])]);
                if (!empty($d['owner_email']) && ($oid = q_val('SELECT id FROM users WHERE email = ?', [$d['owner_email']]))) { try { lead_assign($res['id'], (int)$oid); } catch (InvalidArgumentException $e) { $st['errors'][] = 'سطر ' . ($i + 1) . ': ' . $e->getMessage(); } }
                elseif ($autoAssign && !$res['duplicate_of'] && ($u = next_assignee($clientId))) lead_assign($res['id'], $u);
            } catch (InvalidArgumentException $e) { $st['skipped']++; $st['errors'][] = 'سطر ' . ($i + 1) . ': ' . $e->getMessage(); }
        }
    });
    return $st;
}

/* ---------- queries ---------- */
/** WHERE clause for leads the current user may see. */
function leads_scope_sql(string $alias = 'l'): array {
    $ids = scope_client_ids();
    $w = "$alias.client_id IN (" . in_list($ids) . ')';
    if (!can('crm.view_all') && !is_admin()) $w .= " AND $alias.owner_user_id = " . (int)uid();
    return [$w, []];
}
function crm_filters_sql(): array {
    [$w, $p] = leads_scope_sql();
    if ($c = in_int('client')) { $w .= ' AND l.client_id = ?'; $p[] = $c; }
    if ($s = in_int('stage')) { $w .= ' AND l.stage_id = ?'; $p[] = $s; }
    if (($o = in_str('owner')) !== '') { if ($o === 'none') $w .= ' AND l.owner_user_id IS NULL'; else { $w .= ' AND l.owner_user_id = ?'; $p[] = (int)$o; } }
    if ($cp = in_int('campaign')) { $w .= ' AND l.campaign_id = ?'; $p[] = $cp; }
    if ($src = in_str('source')) { $w .= ' AND l.source = ?'; $p[] = $src; }
    if ($f = in_date('from')) { $w .= ' AND l.received_at >= ?'; $p[] = $f . ' 00:00:00'; }
    if ($t = in_date('to')) { $w .= ' AND l.received_at <= ?'; $p[] = $t . ' 23:59:59'; }
    if (in_str('dups') !== '1') $w .= ' AND l.duplicate_of IS NULL';
    if (in_str('view') === 'overdue') { $w .= ' AND l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < ? AND s.is_won = 0 AND s.is_lost = 0'; $p[] = now(); }
    if (($qq = in_str('q')) !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $qq) . '%';
        $w .= " AND (l.full_name LIKE ? ESCAPE '\\' OR l.phone LIKE ? ESCAPE '\\' OR l.email LIKE ? ESCAPE '\\' OR l.phone_norm = ?)"; array_push($p, $like, $like, $like, norm_phone($qq) ?? '-');
    }
    return [$w, $p];
}

/** Funnel numbers for a set of leads (non-duplicates). */
function crm_stats(string $where, array $p): array {
    $r = q_one("SELECT COUNT(*) total,
        SUM(CASE WHEN l.first_contact_at IS NOT NULL OR s.is_contacted = 1 THEN 1 ELSE 0 END) contacted,
        SUM(CASE WHEN l.qualified_at IS NOT NULL OR s.is_qualified = 1 THEN 1 ELSE 0 END) qualified,
        SUM(CASE WHEN l.appointment_at IS NOT NULL THEN 1 ELSE 0 END) appointments,
        SUM(CASE WHEN s.is_won = 1 THEN 1 ELSE 0 END) won,
        SUM(CASE WHEN s.is_lost = 1 THEN 1 ELSE 0 END) lost
        FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id WHERE $where", $p);
    $t = (int)$r['total'];
    return ['total' => $t, 'contacted' => (int)$r['contacted'], 'qualified' => (int)$r['qualified'], 'appointments' => (int)$r['appointments'], 'won' => (int)$r['won'], 'lost' => (int)$r['lost'],
        'contact_rate' => safe_div($r['contacted'], $t), 'qualified_rate' => safe_div($r['qualified'], $t), 'appointment_rate' => safe_div($r['appointments'], $t),
        'win_rate' => safe_div($r['won'], $t), 'close_rate' => safe_div($r['won'], (int)$r['won'] + (int)$r['lost'])];
}

/** Leads that need follow-up now: emits lead.overdue once per lead per due time (used by the worker). */
function scan_overdue_leads(): int {
    $rows = q_all("SELECT l.* FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id WHERE l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < ?
        AND s.is_won = 0 AND s.is_lost = 0 AND l.duplicate_of IS NULL", [now()]);
    $n = 0;
    foreach ($rows as $l) {
        $ref = $l['id'] . '@' . $l['next_follow_up_at'];
        if (q_val("SELECT 1 FROM events WHERE type = 'lead.overdue' AND ref = ?", [$ref])) continue;
        $hours = (time() - strtotime($l['next_follow_up_at'] . ' UTC')) / 3600;
        emit_event('lead.overdue', (int)$l['client_id'], $ref, ['lead_id' => (int)$l['id'], 'owner_user_id' => $l['owner_user_id'], 'hours_late' => round($hours, 1)]);
        $n++;
    }
    return $n;
}
/** Plain list of a lead's personal data, for export (audited by the caller). */
function lead_export_rows(string $where, array $p): array {
    $rows = q_all("SELECT l.*, s.name stage, c.name client, cp.name campaign, u.name owner FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id JOIN clients c ON c.id = l.client_id
        LEFT JOIN campaigns cp ON cp.id = l.campaign_id LEFT JOIN users u ON u.id = l.owner_user_id WHERE $where ORDER BY l.received_at DESC LIMIT 50000", $p);
    $out = [['ID', 'Client', 'Name', 'Phone', 'Email', 'City', 'Product', 'Stage', 'Owner', 'Source', 'Campaign', 'Form', 'UTM Source', 'UTM Campaign', 'Received', 'First contact', 'Next follow-up', 'Deal value', 'Lost reason', 'Duplicate of']];
    foreach ($rows as $r) $out[] = [(int)$r['id'], $r['client'], $r['full_name'], $r['phone'], $r['email'], $r['city'], $r['product'], $r['stage'], $r['owner'], $r['source'], $r['campaign'], $r['form_name'],
        $r['utm_source'], $r['utm_campaign'], fmt_dt($r['received_at']), fmt_dt($r['first_contact_at']), fmt_dt($r['next_follow_up_at']), $r['deal_value'] === null ? '' : (float)$r['deal_value'], $r['lost_reason'], $r['duplicate_of']];
    return $out;
}
