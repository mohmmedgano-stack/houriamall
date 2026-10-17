<?php
declare(strict_types=1);
/*
 * Client & Profit Tracker — the media buyer's own business: fees, invoices, payments, expenses, profit.
 * The clients' ad spend is NOT revenue and never enters these numbers (it's shown separately for context only).
 */

function service_save(?int $id, array $in): int {
    $cid = (int)($in['client_id'] ?? 0);
    if (!can_client($cid)) throw new Forbidden('client');
    $row = ['client_id' => $cid, 'name' => trim((string)($in['name'] ?? '')), 'fee_type' => ($in['fee_type'] ?? '') === 'fixed' ? 'fixed' : 'monthly',
        'amount' => parse_num((string)($in['amount'] ?? '')), 'currency' => strtoupper(trim((string)($in['currency'] ?? 'EGP'))),
        'start_date' => (string)($in['start_date'] ?? ''), 'end_date' => ($in['end_date'] ?? '') ?: null,
        'billing_day' => max(1, min(28, (int)($in['billing_day'] ?? 1))), 'active' => !empty($in['active']) ? 1 : 0];
    if ($row['name'] === '') throw new InvalidArgumentException('اسم الخدمة مطلوب');
    if ($row['amount'] === null || $row['amount'] <= 0) throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر');
    if (!preg_match('/^[A-Z]{3}$/', $row['currency'])) throw new InvalidArgumentException('العملة مش صحيحة');
    if (!valid_date($row['start_date']) || ($row['end_date'] && (!valid_date($row['end_date']) || $row['end_date'] < $row['start_date']))) throw new InvalidArgumentException('التواريخ مش صحيحة');
    if ($id) { db_update('client_services', $id, $row); audit('service.update', 'service', $id); return $id; }
    $id = db_insert('client_services', $row + ['created_at' => now()]); audit('service.create', 'service', $id); return $id;
}

function invoice_create(array $in): int {
    $cid = (int)($in['client_id'] ?? 0);
    if (!can_client($cid)) throw new Forbidden('client');
    $row = ['client_id' => $cid, 'service_id' => (int)($in['service_id'] ?? 0) ?: null, 'number' => trim((string)($in['number'] ?? '')) ?: null,
        'description' => trim((string)($in['description'] ?? '')), 'amount' => parse_num((string)($in['amount'] ?? '')), 'currency' => strtoupper(trim((string)($in['currency'] ?? 'EGP'))),
        'issue_date' => (string)($in['issue_date'] ?? today()), 'due_date' => (string)($in['due_date'] ?? ''), 'notes' => trim((string)($in['notes'] ?? ''))];
    if ($row['amount'] === null || $row['amount'] <= 0) throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر');
    if (!valid_date($row['issue_date']) || !valid_date($row['due_date']) || $row['due_date'] < $row['issue_date']) throw new InvalidArgumentException('تاريخ الإصدار والاستحقاق مش صحيحين');
    if (!preg_match('/^[A-Z]{3}$/', $row['currency'])) throw new InvalidArgumentException('العملة مش صحيحة');
    $id = db_insert('client_invoices', $row + ['paid_amount' => 0, 'status' => 'unpaid', 'created_at' => now()]);
    if (!$row['number']) q('UPDATE client_invoices SET number = ? WHERE id = ?', ['INV-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT), $id]);
    audit('invoice.create', 'invoice', $id, ['amount' => $row['amount'], 'currency' => $row['currency']]);
    return $id;
}
function invoice_pay(int $id, float $amount, ?string $date = null): void {
    $inv = q_one('SELECT * FROM client_invoices WHERE id = ?', [$id]);
    if (!$inv || !can_client((int)$inv['client_id'])) throw new Forbidden('invoice');
    if ($inv['status'] === 'void') throw new InvalidArgumentException('الفاتورة ملغية');
    if ($amount <= 0) throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر');
    $paid = round((float)$inv['paid_amount'] + $amount, 2);
    if ($paid > (float)$inv['amount'] + 0.005) throw new InvalidArgumentException('المدفوع أكبر من قيمة الفاتورة (المتبقي ' . number_format((float)$inv['amount'] - (float)$inv['paid_amount'], 2) . ')');
    $date = $date && valid_date($date) ? $date : today();
    db_update('client_invoices', $id, ['paid_amount' => $paid, 'status' => abs($paid - (float)$inv['amount']) < 0.005 ? 'paid' : 'partial', 'paid_at' => $date, 'updated_at' => now()]);
    audit('invoice.payment', 'invoice', $id, ['amount' => $amount]);
}
function invoice_status(array $inv, ?string $asOf = null): string {
    if (in_array($inv['status'], ['paid', 'void'], true)) return $inv['status'];
    return $inv['due_date'] < ($asOf ?? today()) ? 'overdue' : $inv['status'];
}

/** Create this month's invoices for active monthly retainers (idempotent: one per service per month). */
function invoices_generate_monthly(?string $asOf = null): int {
    $asOf ??= today(); $month = substr($asOf, 0, 7); $n = 0;
    foreach (q_all("SELECT s.* FROM client_services s JOIN clients c ON c.id = s.client_id WHERE s.active = 1 AND s.fee_type = 'monthly' AND c.status = 'active'
        AND s.start_date <= ? AND (s.end_date IS NULL OR s.end_date >= ?)", [$asOf, $month . '-01']) as $s) {
        $issue = $month . '-' . str_pad((string)$s['billing_day'], 2, '0', STR_PAD_LEFT);
        if ($issue > $asOf) continue;
        $num = 'INV-' . str_replace('-', '', $month) . '-S' . $s['id'];
        if (q_val('SELECT 1 FROM client_invoices WHERE number = ?', [$num])) continue;
        db_insert('client_invoices', ['client_id' => $s['client_id'], 'service_id' => $s['id'], 'number' => $num, 'description' => $s['name'] . ' — ' . ar_month($issue),
            'amount' => $s['amount'], 'currency' => $s['currency'], 'issue_date' => $issue, 'due_date' => (new DateTimeImmutable($issue))->modify('+7 days')->format('Y-m-d'),
            'paid_amount' => 0, 'status' => 'unpaid', 'created_at' => now()]);
        $n++;
    }
    return $n;
}

/** Notify about invoices due within 3 days or overdue, once per invoice per day; emits invoice.due. */
function scan_due_invoices(?string $asOf = null): int {
    $asOf ??= today(); $soon = (new DateTimeImmutable($asOf))->modify('+3 days')->format('Y-m-d'); $n = 0;
    foreach (q_all("SELECT i.*, c.name cname FROM client_invoices i JOIN clients c ON c.id = i.client_id WHERE i.status IN ('unpaid','partial') AND i.due_date <= ?", [$soon]) as $i) {
        $ref = $i['id'] . '@' . $asOf;
        if (q_val("SELECT 1 FROM events WHERE type = 'invoice.due' AND ref = ?", [$ref])) continue;
        $over = $i['due_date'] < $asOf;
        emit_event('invoice.due', (int)$i['client_id'], $ref, ['invoice_id' => (int)$i['id'], 'overdue' => $over, 'remaining' => (float)$i['amount'] - (float)$i['paid_amount']]);
        foreach (q_all("SELECT id FROM users WHERE active = 1 AND role IN ('owner','admin')") as $u)
            notify((int)$u['id'], ($over ? 'فاتورة متأخرة: ' : 'فاتورة مستحقة قريب: ') . $i['cname'], number_format((float)$i['amount'] - (float)$i['paid_amount']) . ' ' . $i['currency'] . ' — استحقاق ' . $i['due_date'], '?r=finance&tab=invoices', $over ? 'warning' : 'info');
        $n++;
    }
    return $n;
}

function expense_save(array $in): int {
    $row = ['client_id' => (int)($in['client_id'] ?? 0) ?: null, 'category' => trim((string)($in['category'] ?? '')), 'vendor' => trim((string)($in['vendor'] ?? '')) ?: null,
        'amount' => parse_num((string)($in['amount'] ?? '')), 'currency' => strtoupper(trim((string)($in['currency'] ?? 'EGP'))), 'date' => (string)($in['date'] ?? today()),
        'notes' => trim((string)($in['notes'] ?? '')) ?: null, 'is_demo' => mode() === 'demo' ? 1 : 0];
    if ($row['client_id'] && !can_client($row['client_id'])) throw new Forbidden('client');
    if ($row['category'] === '') throw new InvalidArgumentException('اختار نوع المصروف');
    if ($row['amount'] === null || $row['amount'] <= 0) throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر');
    if (!valid_date($row['date'])) throw new InvalidArgumentException('التاريخ مش صحيح');
    $id = db_insert('expenses', $row + ['created_at' => now()]);
    audit('expense.create', 'expense', $id, ['amount' => $row['amount']]);
    return $id;
}

/**
 * Profit summary per currency for a period. Revenue = invoiced fees (accrual); collected = payments received.
 * Expenses include general (no client) ones. Never includes ad spend.
 */
function finance_summary(array $clientIds, string $from, string $to): array {
    $in = in_list($clientIds); $demo = mode() === 'demo' ? 1 : 0;
    $out = [];
    foreach (q_all("SELECT currency, SUM(amount) invoiced, SUM(paid_amount) collected FROM client_invoices WHERE client_id IN ($in) AND status <> 'void' AND issue_date BETWEEN ? AND ? GROUP BY currency", [$from, $to]) as $r)
        $out[$r['currency']] = ['invoiced' => (float)$r['invoiced'], 'collected' => (float)$r['collected'], 'expenses' => 0.0];
    foreach (q_all("SELECT currency, SUM(amount) s FROM expenses WHERE (client_id IN ($in) OR (client_id IS NULL AND is_demo = ?)) AND date BETWEEN ? AND ? GROUP BY currency", [$demo, $from, $to]) as $r) {
        $out[$r['currency']] ??= ['invoiced' => 0.0, 'collected' => 0.0];
        $out[$r['currency']]['expenses'] = (float)$r['s'];
    }
    foreach ($out as $c => &$v) {
        $v['outstanding'] = (float)q_val("SELECT COALESCE(SUM(amount - paid_amount), 0) FROM client_invoices WHERE client_id IN ($in) AND currency = ? AND status IN ('unpaid','partial')", [$c]);
        $v['profit'] = $v['invoiced'] - $v['expenses'];
        $v['cash_profit'] = $v['collected'] - $v['expenses'];
        $v['margin'] = safe_div($v['profit'], $v['invoiced']);
    }
    ksort($out);
    return $out;
}
/** Per-client profitability: fees invoiced/collected minus expenses tagged to that client. General expenses stay unallocated. */
function finance_by_client(array $clientIds, string $from, string $to): array {
    $rows = [];
    foreach ($clientIds as $cid) {
        $inv = q_all("SELECT currency, SUM(amount) a, SUM(paid_amount) p FROM client_invoices WHERE client_id = ? AND status <> 'void' AND issue_date BETWEEN ? AND ? GROUP BY currency", [$cid, $from, $to]);
        $exp = array_column(q_all('SELECT currency, SUM(amount) s FROM expenses WHERE client_id = ? AND date BETWEEN ? AND ? GROUP BY currency', [$cid, $from, $to]), 's', 'currency');
        $curs = array_unique(array_merge(array_column($inv, 'currency'), array_keys($exp)));
        $byCur = array_column($inv, null, 'currency');
        foreach ($curs as $c) {
            $a = (float)($byCur[$c]['a'] ?? 0); $e = (float)($exp[$c] ?? 0);
            $rows[] = ['client_id' => $cid, 'currency' => $c, 'invoiced' => $a, 'collected' => (float)($byCur[$c]['p'] ?? 0), 'expenses' => $e, 'profit' => $a - $e, 'margin' => safe_div($a - $e, $a)];
        }
    }
    usort($rows, fn($x, $y) => $y['profit'] <=> $x['profit']);
    return $rows;
}
function finance_monthly(array $clientIds, int $months = 12): array {
    $out = []; $in = in_list($clientIds); $demo = mode() === 'demo' ? 1 : 0;
    $start = (new DateTimeImmutable(today()))->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
    for ($i = 0; $i < $months; $i++) {
        $m = $start->modify("+$i months"); $f = $m->format('Y-m-01'); $t = $m->format('Y-m-t');
        foreach (q_all("SELECT currency, SUM(amount) a FROM client_invoices WHERE client_id IN ($in) AND status <> 'void' AND issue_date BETWEEN ? AND ? GROUP BY currency", [$f, $t]) as $r) $out[$r['currency']][$m->format('Y-m')]['rev'] = (float)$r['a'];
        foreach (q_all("SELECT currency, SUM(amount) a FROM expenses WHERE (client_id IN ($in) OR (client_id IS NULL AND is_demo = ?)) AND date BETWEEN ? AND ? GROUP BY currency", [$demo, $f, $t]) as $r) $out[$r['currency']][$m->format('Y-m')]['exp'] = (float)$r['a'];
    }
    foreach ($out as $c => $ms) { $full = []; for ($i = 0; $i < $months; $i++) { $k = $start->modify("+$i months")->format('Y-m'); $full[$k] = ['rev' => $ms[$k]['rev'] ?? 0.0, 'exp' => $ms[$k]['exp'] ?? 0.0]; } $out[$c] = $full; }
    return $out;
}
function expense_categories(): array { return ['tools' => 'أدوات واشتراكات', 'ai' => 'AI', 'hosting' => 'استضافة ودومين', 'freelancers' => 'فريلانسرز', 'content' => 'تصوير ومحتوى', 'transport' => 'مواصلات', 'office' => 'مكتب وتشغيل', 'other' => 'أخرى']; }
