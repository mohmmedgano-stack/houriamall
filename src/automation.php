<?php
declare(strict_types=1);
/*
 * Marketing Automation Hub: Trigger → Conditions → Actions, with execution history, retries and de-duplication.
 * Workflows never touch ad accounts (no pausing, no budget edits) and never send marketing messages to leads.
 * The only outward-facing action (webhook) has to be explicitly approved by an admin per workflow.
 */

function wf_condition_fields(): array {
    return ['client_id' => 'العميل (ID)', 'source' => 'مصدر الليد', 'campaign_id' => 'الحملة (ID)', 'severity' => 'خطورة التنبيه', 'metric' => 'مؤشر التنبيه',
        'hours_late' => 'ساعات التأخير', 'pct_used' => 'نسبة استهلاك الميزانية', 'deal_value' => 'قيمة البيع', 'to_stage' => 'المرحلة الجديدة (اسم)', 'owner_user_id' => 'المسؤول (ID)'];
}
function wf_ops(): array { return ['eq' => '=', 'neq' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'contains' => 'يحتوي', 'empty' => 'فاضي', 'not_empty' => 'مش فاضي']; }
function wf_action_types(): array {
    return ['assign_round_robin' => 'إسناد الليد بالتساوي على فريق المبيعات', 'assign_user' => 'إسناد الليد لمستخدم محدد', 'notify_owner' => 'إشعار مسؤول الليد',
        'notify_role' => 'إشعار دور (مديرين / ميديا باير / مبيعات)', 'add_lead_note' => 'إضافة ملاحظة على الليد', 'set_followup' => 'تحديد متابعة بعد X ساعة',
        'escalate' => 'تصعيد للمديرين لو التأخير ≥ X ساعة', 'generate_report' => 'إنشاء تقرير وحفظه', 'create_alert' => 'إنشاء تنبيه في Watchdog', 'webhook' => 'إرسال Webhook (خارجي — محتاج موافقة)'];
}

function wf_save(?int $id, array $in): int {
    $trigger = (string)($in['trigger_type'] ?? '');
    if (!isset(event_types()[$trigger])) throw new InvalidArgumentException('اختار Trigger');
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('اسم الـ Workflow مطلوب');
    $clientId = (int)($in['client_id'] ?? 0) ?: null;
    if ($clientId && !can_client($clientId)) throw new Forbidden('client');
    if (!$clientId && !is_admin()) throw new InvalidArgumentException('اختار عميل');
    $conds = [];
    foreach ((array)($in['conditions'] ?? []) as $c) {
        if (empty($c['field']) || !isset(wf_condition_fields()[$c['field']]) || !isset(wf_ops()[$c['op'] ?? ''])) continue;
        $conds[] = ['field' => $c['field'], 'op' => $c['op'], 'value' => mb_substr(trim((string)($c['value'] ?? '')), 0, 200)];
    }
    $acts = [];
    foreach ((array)($in['actions'] ?? []) as $a) {
        if (empty($a['type']) || !isset(wf_action_types()[$a['type']])) continue;
        $p = array_map(fn($v) => is_string($v) ? mb_substr(trim($v), 0, 1000) : $v, (array)($a['params'] ?? []));
        if ($a['type'] === 'webhook' && !preg_match('#^https://#i', (string)($p['url'] ?? ''))) throw new InvalidArgumentException('رابط الـ Webhook لازم يبدأ بـ https://');
        $acts[] = ['type' => $a['type'], 'params' => $p];
    }
    if (!$acts) throw new InvalidArgumentException('ضيف Action واحد على الأقل');
    $cfg = (array)($in['trigger_config'] ?? []);
    if ($trigger === 'schedule') {
        $cfg = ['freq' => in_array($cfg['freq'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $cfg['freq'] : 'weekly', 'hour' => max(0, min(23, (int)($cfg['hour'] ?? 9))),
            'weekday' => max(0, min(6, (int)($cfg['weekday'] ?? 6))), 'monthday' => max(1, min(28, (int)($cfg['monthday'] ?? 1)))];
    }
    $row = ['name' => mb_substr($name, 0, 150), 'client_id' => $clientId, 'trigger_type' => $trigger, 'trigger_config_json' => json_encode($cfg),
        'conditions_json' => json_encode($conds, JSON_UNESCAPED_UNICODE), 'actions_json' => json_encode($acts, JSON_UNESCAPED_UNICODE),
        'active' => !empty($in['active']) ? 1 : 0, 'max_retries' => max(0, min(10, (int)($in['max_retries'] ?? 3)))];
    // external actions need an admin's explicit approval, stored on the workflow
    $ext = (bool)array_filter($acts, fn($a) => $a['type'] === 'webhook');
    $prevCfg = $id ? json_decode((string)q_val('SELECT trigger_config_json FROM automation_workflows WHERE id = ?', [$id]), true) : [];
    $approved = $ext && is_admin() && !empty($in['approve_external']);
    $cfg['external_approved'] = $approved || ($ext && !empty($prevCfg['external_approved']) && !isset($in['approve_external_set']));
    if (!$ext) $cfg['external_approved'] = false;
    $row['trigger_config_json'] = json_encode($cfg);
    if ($id) { db_update('automation_workflows', $id, $row + ['updated_at' => now()]); audit('workflow.update', 'workflow', $id); return $id; }
    $id = db_insert('automation_workflows', $row + ['created_by' => uid(), 'created_at' => now()]);
    audit('workflow.create', 'workflow', $id, ['trigger' => $trigger]);
    return $id;
}

/** The six starter workflows described in the spec. */
function wf_templates(): array {
    return [
        'new_lead' => ['name' => 'ليد جديد ← توزيع وإشعار', 'trigger_type' => 'lead.created', 'conditions' => [],
            'actions' => [['type' => 'assign_round_robin', 'params' => []], ['type' => 'notify_owner', 'params' => ['text' => 'ليد جديد اتسند ليك — كلمه في أسرع وقت']], ['type' => 'set_followup', 'params' => ['hours' => '1']]]],
        'cpl_alert' => ['name' => 'تنبيه CPL ← إشعار الميديا باير', 'trigger_type' => 'alert.created', 'conditions' => [['field' => 'metric', 'op' => 'eq', 'value' => 'cpl']],
            'actions' => [['type' => 'notify_role', 'params' => ['role' => 'buyer', 'text' => 'CPL عدّى الحد — راجع الحملة']]]],
        'weekly_report' => ['name' => 'نهاية الأسبوع ← تقرير العميل', 'trigger_type' => 'schedule', 'trigger_config' => ['freq' => 'weekly', 'weekday' => 4, 'hour' => 18], 'conditions' => [],
            'actions' => [['type' => 'generate_report', 'params' => ['type' => 'weekly']], ['type' => 'notify_role', 'params' => ['role' => 'admin', 'text' => 'التقرير الأسبوعي جاهز']]]],
        'overdue' => ['name' => 'متابعة متأخرة ← تنبيه ثم تصعيد', 'trigger_type' => 'lead.overdue', 'conditions' => [],
            'actions' => [['type' => 'notify_owner', 'params' => ['text' => 'عندك متابعة متأخرة']], ['type' => 'escalate', 'params' => ['hours' => '24']]]],
        'won' => ['name' => 'Won ← تسجيل البيع وتحديث المؤشرات', 'trigger_type' => 'lead.won', 'conditions' => [],
            'actions' => [['type' => 'add_lead_note', 'params' => ['text' => 'بيع اتسجل — داخل في Lead Quality و ROAS الحقيقي']], ['type' => 'notify_role', 'params' => ['role' => 'admin', 'text' => 'صفقة جديدة اتقفلت 🎉']]]],
        'budget' => ['name' => 'الميزانية قربت تخلص ← تنبيه', 'trigger_type' => 'budget.threshold', 'conditions' => [],
            'actions' => [['type' => 'notify_role', 'params' => ['role' => 'buyer', 'text' => 'ميزانية قربت تخلص أو هتتخطى الحد']], ['type' => 'notify_role', 'params' => ['role' => 'admin', 'text' => 'ميزانية قربت تخلص أو هتتخطى الحد']]]],
    ];
}

/* ---------- evaluation ---------- */
function wf_context(array $event): array {
    $ctx = (json_decode((string)$event['payload_json'], true) ?: []) + ['client_id' => $event['client_id'], 'event_type' => $event['type'], 'ref' => $event['ref']];
    if (!empty($ctx['lead_id']) && ($l = q_one('SELECT * FROM leads WHERE id = ?', [(int)$ctx['lead_id']]))) {
        $ctx += ['source' => $l['source'], 'campaign_id' => $l['campaign_id'], 'owner_user_id' => $l['owner_user_id'], 'deal_value' => $l['deal_value']];
        $ctx['owner_user_id'] = $l['owner_user_id'];
        if (isset($ctx['to'])) $ctx['to_stage'] = stage((int)$ctx['to'])['name'] ?? '';
    }
    if (!empty($ctx['alert_id']) && ($a = q_one('SELECT * FROM alerts WHERE id = ?', [(int)$ctx['alert_id']]))) $ctx += ['alert_title' => $a['title'], 'alert_message' => $a['message']];
    return $ctx;
}
function wf_conditions_pass(array $conds, array $ctx): bool {
    foreach ($conds as $c) {
        $v = $ctx[$c['field']] ?? null; $want = $c['value'];
        $num = is_numeric($v) && is_numeric($want);
        $ok = match ($c['op']) {
            'eq' => $num ? (float)$v == (float)$want : mb_strtolower((string)$v) === mb_strtolower($want),
            'neq' => $num ? (float)$v != (float)$want : mb_strtolower((string)$v) !== mb_strtolower($want),
            'gt' => $num && (float)$v > (float)$want, 'gte' => $num && (float)$v >= (float)$want,
            'lt' => $num && (float)$v < (float)$want, 'lte' => $num && (float)$v <= (float)$want,
            'contains' => $v !== null && str_contains(mb_strtolower((string)$v), mb_strtolower($want)),
            'empty' => $v === null || $v === '', 'not_empty' => $v !== null && $v !== '',
            default => false,
        };
        if (!$ok) return false;
    }
    return true;
}

/** Run (or resume) one workflow for one trigger. Idempotent per (workflow, dedupe key); succeeded actions are never repeated. */
function wf_execute(array $wf, array $ctx, string $dedupeKey, ?int $eventId = null): array {
    $ins = q('INSERT OR IGNORE INTO workflow_executions (workflow_id, event_id, dedupe_key, status, attempts, log_json, started_at) VALUES (?, ?, ?, ?, 0, ?, ?)',
        [$wf['id'], $eventId, $dedupeKey, 'running', '[]', now()]);
    $ex = q_one('SELECT * FROM workflow_executions WHERE workflow_id = ? AND dedupe_key = ?', [$wf['id'], $dedupeKey]);
    if ($ins->rowCount() === 0 && $ex['status'] !== 'retrying') return $ex;   // already handled (or being handled) — never run twice
    if (!wf_conditions_pass(json_decode((string)$wf['conditions_json'], true) ?: [], $ctx)) {
        db_update('workflow_executions', (int)$ex['id'], ['status' => 'skipped', 'log_json' => json_encode([['step' => 'conditions', 'ok' => true, 'msg' => 'الشروط مش متحققة — اتجاهل']], JSON_UNESCAPED_UNICODE), 'finished_at' => now()]);
        return q_one('SELECT * FROM workflow_executions WHERE id = ?', [$ex['id']]);
    }
    $log = json_decode((string)$ex['log_json'], true) ?: [];
    $done = []; foreach ($log as $l) if (($l['ok'] ?? false) && isset($l['i'])) $done[$l['i']] = true;
    $cfg = json_decode((string)$wf['trigger_config_json'], true) ?: [];
    $error = null;
    foreach (json_decode((string)$wf['actions_json'], true) ?: [] as $i => $a) {
        if (isset($done[$i])) continue;
        try { $msg = wf_run_action($a, $ctx, $wf, $cfg); $log[] = ['i' => $i, 'step' => $a['type'], 'ok' => true, 'msg' => $msg, 'at' => now()]; }
        catch (Throwable $e) { $error = $e->getMessage(); $log[] = ['i' => $i, 'step' => $a['type'], 'ok' => false, 'msg' => redact($error), 'at' => now()]; break; }
    }
    $attempts = (int)$ex['attempts'] + 1;
    if ($error === null) $f = ['status' => 'success', 'error' => null, 'next_retry_at' => null, 'finished_at' => now()];
    elseif ($attempts <= (int)$wf['max_retries']) $f = ['status' => 'retrying', 'error' => redact($error), 'next_retry_at' => gmdate('Y-m-d H:i:s', time() + 60 * (2 ** ($attempts - 1)))];
    else $f = ['status' => 'failed', 'error' => redact($error), 'next_retry_at' => null, 'finished_at' => now()];
    db_update('workflow_executions', (int)$ex['id'], $f + ['attempts' => $attempts, 'log_json' => json_encode($log, JSON_UNESCAPED_UNICODE)]);
    q('UPDATE automation_workflows SET last_run_at = ? WHERE id = ?', [now(), $wf['id']]);
    if ($f['status'] === 'failed') foreach (client_audience((int)($ctx['client_id'] ?? 0)) as $u) if (is_admin($u)) notify((int)$u['id'], 'Workflow فشل: ' . $wf['name'], (string)$f['error'], '?r=automation&wf=' . $wf['id'], 'warning');
    return q_one('SELECT * FROM workflow_executions WHERE id = ?', [$ex['id']]);
}

function wf_users_by_role(int $clientId, string $role): array {
    $all = client_audience($clientId);
    return array_values(array_filter($all, fn($u) => $role === 'admin' ? in_array($u['role'], ['owner', 'admin'], true) : $u['role'] === $role));
}

/** Perform one action. Returns a human log line; throws on failure (which triggers retry). */
function wf_run_action(array $a, array &$ctx, array $wf, array $cfg): string {
    $p = $a['params'] ?? [];
    $leadId = (int)($ctx['lead_id'] ?? 0);
    $cid = (int)($ctx['client_id'] ?? 0);
    $needLead = function () use ($leadId) { if (!$leadId || !q_val('SELECT 1 FROM leads WHERE id = ?', [$leadId])) throw new RuntimeException('الـ Trigger ده مفيهوش ليد'); };
    switch ($a['type']) {
        case 'assign_round_robin':
            $needLead();
            if (q_val('SELECT owner_user_id FROM leads WHERE id = ?', [$leadId])) return 'الليد مسند بالفعل — مفيش تغيير';
            $u = next_assignee($cid);
            if (!$u) throw new RuntimeException('مفيش حد في فريق المبيعات عنده صلاحية على العميل ده');
            lead_assign($leadId, $u, null);
            return 'اتسند لـ ' . q_val('SELECT name FROM users WHERE id = ?', [$u]);
        case 'assign_user':
            $needLead(); lead_assign($leadId, (int)($p['user_id'] ?? 0), null);
            return 'اتسند لمستخدم #' . (int)($p['user_id'] ?? 0);
        case 'notify_owner':
            $needLead();
            $o = (int)q_val('SELECT owner_user_id FROM leads WHERE id = ?', [$leadId]);
            if (!$o) throw new RuntimeException('الليد مش مسند لحد لسه');
            $l = q_one('SELECT full_name, phone FROM leads WHERE id = ?', [$leadId]);
            notify($o, (string)($p['text'] ?? 'تحديث على ليد'), (string)($l['full_name'] ?: $l['phone']), '?r=lead&id=' . $leadId);
            return 'اتبعت إشعار للمسؤول';
        case 'notify_role':
            $role = in_array($p['role'] ?? '', ['admin', 'buyer', 'sales'], true) ? $p['role'] : 'admin';
            $us = wf_users_by_role($cid, $role);
            foreach ($us as $u) notify((int)$u['id'], (string)($p['text'] ?? $wf['name']), (string)($ctx['alert_message'] ?? $ctx['alert_title'] ?? ''), $leadId ? '?r=lead&id=' . $leadId : (!empty($ctx['alert_id']) ? '?r=alerts&view=' . (int)$ctx['alert_id'] : (!empty($ctx['report_id']) ? '?r=report&id=' . (int)$ctx['report_id'] : '')));
            return 'اتبعت إشعار لـ ' . count($us) . ' مستخدم';
        case 'add_lead_note':
            $needLead(); lead_activity($leadId, 'automation', null, (string)($p['text'] ?? ''), ['workflow' => (int)$wf['id']], null);
            return 'اتضافت ملاحظة';
        case 'set_followup':
            $needLead(); $h = max(0.25, (float)($p['hours'] ?? 24));
            q('UPDATE leads SET next_follow_up_at = COALESCE(next_follow_up_at, ?) WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + (int)($h * 3600)), $leadId]);
            return "متابعة بعد $h ساعة (لو مفيش متابعة متحددة)";
        case 'escalate':
            $h = (float)($p['hours'] ?? 24);
            if ((float)($ctx['hours_late'] ?? 0) < $h) return 'التأخير أقل من ' . $h . ' ساعة — مفيش تصعيد';
            foreach (wf_users_by_role($cid, 'admin') as $u) notify((int)$u['id'], 'تصعيد: متابعة متأخرة ' . round((float)$ctx['hours_late']) . ' ساعة', '', $leadId ? '?r=lead&id=' . $leadId : '', 'critical');
            return 'اتصعّد للمديرين';
        case 'generate_report':
            if (!$cid) throw new RuntimeException('التقرير محتاج عميل');
            $type = array_key_exists($p['type'] ?? '', report_types()) && !in_array($p['type'], ['campaign'], true) ? $p['type'] : 'weekly';
            [$f, $t] = report_default_period($type);
            $rid = report_save(report_build($type, $cid, $f, $t));
            $ctx['report_id'] = $rid;   // later actions (e.g. notify) link to it
            return 'اتعمل تقرير #' . $rid;
        case 'create_alert':
            $id = db_insert('alerts', ['client_id' => $cid ?: null, 'kind' => 'automation', 'severity' => in_array($p['severity'] ?? '', ['info', 'warning', 'critical'], true) ? $p['severity'] : 'info',
                'title' => (string)($p['title'] ?? $wf['name']), 'message' => (string)($p['text'] ?? ''), 'dedupe_key' => 'wf:' . $wf['id'] . ':' . ($ctx['ref'] ?? ''), 'status' => 'open', 'created_at' => now()]);
            return 'اتعمل تنبيه #' . $id;
        case 'webhook':
            if (empty($cfg['external_approved'])) throw new RuntimeException('الـ Webhook محتاج موافقة مدير على الـ Workflow ده قبل ما يشتغل');
            $url = (string)($p['url'] ?? '');
            if (!preg_match('#^https://#i', $url)) throw new RuntimeException('رابط غير آمن');
            $body = json_encode(['event' => $ctx['event_type'] ?? 'schedule', 'workflow' => $wf['name'], 'data' => array_diff_key($ctx, array_flip(['alert_message']))], JSON_UNESCAPED_UNICODE);
            if (!empty($GLOBALS['WF_HTTP'])) { $code = ($GLOBALS['WF_HTTP'])($url, $body); }
            else {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
                curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            }
            if ($code < 200 || $code >= 300) throw new RuntimeException("الـ Webhook رجّع $code");
            return "Webhook اتبعت ($code)";
    }
    throw new RuntimeException('Action غير معروف');
}

/** Worker: process new events, due retries and scheduled workflows. */
function process_automation(int $limit = 200, ?int $now = null): array {
    $now ??= time();
    $stats = ['events' => 0, 'runs' => 0, 'retries' => 0, 'scheduled' => 0];
    foreach (q_all('SELECT * FROM events WHERE processed_at IS NULL ORDER BY id LIMIT ' . (int)$limit) as $ev) {
        q('UPDATE events SET processed_at = ? WHERE id = ? AND processed_at IS NULL', [now(), $ev['id']]);
        $stats['events']++;
        foreach (q_all('SELECT * FROM automation_workflows WHERE active = 1 AND trigger_type = ? AND (client_id IS NULL OR client_id = ?)', [$ev['type'], $ev['client_id']]) as $wf) {
            wf_execute($wf, wf_context($ev), 'event:' . $ev['id'], (int)$ev['id']); $stats['runs']++;
        }
    }
    foreach (q_all("SELECT x.*, e.type etype, e.client_id ecid, e.ref eref, e.payload_json FROM workflow_executions x LEFT JOIN events e ON e.id = x.event_id WHERE x.status = 'retrying' AND x.next_retry_at <= ?", [gmdate('Y-m-d H:i:s', $now)]) as $x) {
        $wf = q_one('SELECT * FROM automation_workflows WHERE id = ?', [$x['workflow_id']]);
        if (!$wf || !$wf['active']) { db_update('workflow_executions', (int)$x['id'], ['status' => 'failed', 'error' => 'الـ Workflow اتوقف', 'finished_at' => now()]); continue; }
        if ($x['event_id']) $ctx = wf_context(['type' => $x['etype'], 'client_id' => $x['ecid'], 'ref' => $x['eref'], 'payload_json' => $x['payload_json']]);
        else { $parts = explode(':', $x['dedupe_key']); $ctx = ['client_id' => (int)end($parts), 'event_type' => 'schedule', 'ref' => $parts[1] . ':' . ($parts[2] ?? '')]; }
        wf_execute($wf, $ctx, $x['dedupe_key'], $x['event_id'] ? (int)$x['event_id'] : null); $stats['retries']++;
    }
    foreach (q_all("SELECT * FROM automation_workflows WHERE active = 1 AND trigger_type = 'schedule'") as $wf) {
        $slot = wf_schedule_slot(json_decode((string)$wf['trigger_config_json'], true) ?: [], $now);
        if (!$slot) continue;
        $clients = $wf['client_id'] ? [(int)$wf['client_id']] : array_map('intval', q_col("SELECT id FROM clients WHERE status = 'active'"));
        foreach ($clients as $cid) {
            $ex = wf_execute($wf, ['client_id' => $cid, 'event_type' => 'schedule', 'ref' => $slot], "schedule:$slot:$cid");
            if (($ex['started_at'] ?? '') >= gmdate('Y-m-d H:i:s', $now - 5)) $stats['scheduled']++;
        }
    }
    return $stats;
}

/** Current schedule slot (e.g. "weekly:2026-W42") if it is due now in the app time zone, else null. */
function wf_schedule_slot(array $cfg, int $now): ?string {
    $d = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(app_tz()));
    if ((int)$d->format('G') < (int)($cfg['hour'] ?? 9)) return null;
    return match ($cfg['freq'] ?? 'weekly') {
        'daily' => 'daily:' . $d->format('Y-m-d'),
        'weekly' => (int)$d->format('w') === (int)($cfg['weekday'] ?? 6) ? 'weekly:' . $d->format('o-\WW') : null,
        'monthly' => (int)$d->format('j') === (int)($cfg['monthday'] ?? 1) ? 'monthly:' . $d->format('Y-m') : null,
        default => null,
    };
}
