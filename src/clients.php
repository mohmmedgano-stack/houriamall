<?php
declare(strict_types=1);

/** Validate client form data. Returns [clean row, error|null]. */
function client_from_input(array $in): array {
    $row = [
        'name' => trim((string)($in['name'] ?? '')),
        'industry' => trim((string)($in['industry'] ?? '')),
        'contact_name' => trim((string)($in['contact_name'] ?? '')),
        'contact_phone' => trim((string)($in['contact_phone'] ?? '')),
        'currency' => strtoupper(trim((string)($in['currency'] ?? 'EGP'))),
        'timezone' => trim((string)($in['timezone'] ?? 'Africa/Cairo')) ?: 'Africa/Cairo',
        'notes' => trim((string)($in['notes'] ?? '')),
    ];
    if ($row['name'] === '' || mb_strlen($row['name']) > 120) return [$row, 'اسم العميل مطلوب (لحد 120 حرف)'];
    if (!preg_match('/^[A-Z]{3}$/', $row['currency'])) return [$row, 'العملة لازم تكون كود من 3 حروف زي EGP'];
    if (!in_array($row['timezone'], DateTimeZone::listIdentifiers(), true)) return [$row, 'المنطقة الزمنية مش صحيحة'];
    return [$row, null];
}

function client_create(array $in, bool $demo = false): int {
    [$row, $err] = client_from_input($in);
    if ($err) throw new InvalidArgumentException($err);
    $id = db_insert('clients', $row + ['status' => 'active', 'is_demo' => $demo ? 1 : 0, 'created_at' => now()]);
    audit('client.create', 'client', $id, ['name' => $row['name']]);
    return $id;
}
function client_update(int $id, array $in): void {
    [$row, $err] = client_from_input($in);
    if ($err) throw new InvalidArgumentException($err);
    db_update('clients', $id, $row + ['updated_at' => now()]);
    audit('client.update', 'client', $id);
}
function client_set_status(int $id, string $status): void {
    if (!in_array($status, ['active', 'paused', 'archived'], true)) throw new InvalidArgumentException('حالة غير معروفة');
    db_update('clients', $id, ['status' => $status, 'updated_at' => now()]);
    audit('client.status', 'client', $id, ['status' => $status]);
}
/** Delete a client and everything under it (cascade). Only for demo data or explicit owner action. */
function client_delete(int $id): void {
    $c = q_one('SELECT * FROM clients WHERE id = ?', [$id]);
    if (!$c) return;
    q('DELETE FROM clients WHERE id = ?', [$id]);
    audit('client.delete', 'client', $id, ['name' => $c['name'], 'demo' => (int)$c['is_demo']]);
}

function client_logo_save(int $id, array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return 'مقدرتش أرفع الشعار';
    if ($file['size'] > 2 * 1024 * 1024) return 'الشعار لازم يكون أقل من 2 ميجا';
    $info = @getimagesize($file['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) return 'الشعار لازم يكون PNG أو JPG أو WEBP';
    $dir = APP_ROOT . '/uploads/logos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'c' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $ok = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], "$dir/$name") : copy($file['tmp_name'], "$dir/$name");
    if (!$ok) return 'حصلت مشكلة في حفظ الشعار';
    db_update('clients', $id, ['logo_path' => 'uploads/logos/' . $name]);
    return null;
}

/* ---------- ad accounts ---------- */
function account_save(?int $id, int $clientId, array $in): int {
    $row = [
        'client_id' => $clientId,
        'platform' => in_array($in['platform'] ?? 'meta', ['meta', 'google', 'tiktok'], true) ? (string)($in['platform'] ?? 'meta') : 'meta',
        'name' => trim((string)($in['name'] ?? '')),
        'external_id' => preg_replace('/[^0-9a-zA-Z_\-]/', '', str_replace('act_', '', (string)($in['external_id'] ?? ''))) ?: null,
        'currency' => strtoupper(trim((string)($in['currency'] ?? 'EGP'))),
        'timezone' => trim((string)($in['timezone'] ?? '')) ?: null,
    ];
    if ($row['name'] === '') throw new InvalidArgumentException('اسم الحساب مطلوب');
    if (!preg_match('/^[A-Z]{3}$/', $row['currency'])) throw new InvalidArgumentException('العملة لازم تكون كود من 3 حروف');
    if ($row['external_id'] && q_val('SELECT id FROM ad_accounts WHERE platform = ? AND external_id = ? AND id <> ?', [$row['platform'], $row['external_id'], $id ?? 0]))
        throw new InvalidArgumentException('رقم الحساب ده مربوط بعميل تاني قبل كده');
    if ($id) {
        if ($row['currency'] !== (string)q_val('SELECT currency FROM ad_accounts WHERE id = ?', [$id])
            && q_val('SELECT 1 FROM performance_snapshots WHERE ad_account_id = ? LIMIT 1', [$id]))
            throw new InvalidArgumentException('مينفعش تغيّر عملة حساب عليه بيانات أداء — ده هيبوّظ الأرقام القديمة');
        db_update('ad_accounts', $id, $row + ['updated_at' => now()]);
        audit('account.update', 'ad_account', $id);
        return $id;
    }
    $id = db_insert('ad_accounts', $row + ['status' => 'active', 'created_at' => now()]);
    audit('account.create', 'ad_account', $id, ['name' => $row['name']]);
    return $id;
}
function accounts_for(array $clientIds): array {
    return $clientIds ? q_all('SELECT a.*, c.name client_name FROM ad_accounts a JOIN clients c ON c.id = a.client_id WHERE a.client_id IN (' . in_list($clientIds) . ') ORDER BY c.name, a.name') : [];
}
function campaigns_for(array $clientIds): array {
    return $clientIds ? q_all('SELECT cp.*, a.client_id, a.currency, a.name account_name FROM campaigns cp JOIN ad_accounts a ON a.id = cp.ad_account_id
        WHERE a.client_id IN (' . in_list($clientIds) . ') ORDER BY cp.name') : [];
}
