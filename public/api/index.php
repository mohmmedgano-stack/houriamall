<?php
// Houria Mall API — single entry point: /api/index.php?action=<name>
declare(strict_types=1);
define('HOURIA_API', true);
require __DIR__ . '/lib.php';

const STATUSES = ['pending', 'confirmed', 'in_preparation', 'ready_for_delivery', 'completed', 'cancelled'];
const PAYMENT_STATUSES = ['pending_verification', 'deposit_paid', 'fully_paid', 'rejected'];

set_exception_handler(function (Throwable $e) {
    error_log('[houria] ' . $e->getMessage());
    fail(500, 'server_error', 'حصل خطأ في السيرفر، جرّب تاني');
});

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ───────────── View helpers ─────────────

function media_url(string $file): string
{
    return '/api/index.php?action=media&f=' . rawurlencode($file);
}

function reserved_counts(): array
{
    $rows = db()->query("SELECT product_id, COUNT(*) AS c FROM reservations WHERE status != 'cancelled' GROUP BY product_id")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['product_id']] = (int)$r['c'];
    return $out;
}

function all_products(): array
{
    $counts = reserved_counts();
    $rows = db()->query('SELECT data FROM products ORDER BY created_at DESC')->fetchAll();
    return array_map(function ($r) use ($counts) {
        $p = json_decode($r['data'], true);
        $p['reservedCount'] = $counts[$p['id']] ?? 0;
        return $p;
    }, $rows);
}

function get_product(string $id): ?array
{
    $st = db()->prepare('SELECT data FROM products WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetchColumn();
    if ($d === false) return null;
    $p = json_decode($d, true);
    $c = db()->prepare("SELECT COUNT(*) FROM reservations WHERE product_id = ? AND status != 'cancelled'");
    $c->execute([$id]);
    $p['reservedCount'] = (int)$c->fetchColumn();
    return $p;
}

function public_settings(): array
{
    $s = kv_get('settings') ?? [];
    unset($s['adminUsername'], $s['adminPassword']);
    return $s;
}

/** What a customer may see about their own booking. */
function public_reservation(array $r): array
{
    unset($r['paymentProofFile'], $r['paymentProofUrl'], $r['merchantNotes']);
    $r['hasProofImage'] = !empty($r['hasProofImage']);
    return $r;
}

function admin_reservation(array $r, string $token): array
{
    if (!empty($r['paymentProofFile'])) {
        $r['paymentProofUrl'] = '/api/index.php?action=proof&f=' . rawurlencode($r['paymentProofFile']) . '&t=' . $token;
    }
    unset($r['paymentProofFile']);
    return $r;
}

function booking_window_state(array $p): string
{
    $now = time();
    $start = !empty($p['bookingStartsAt']) ? strtotime((string)$p['bookingStartsAt']) : false;
    $end = !empty($p['bookingEndsAt']) ? strtotime((string)$p['bookingEndsAt']) : false;
    if ($start && $now < $start) return 'not_started';
    if ($end && $now >= $end) return 'ended';
    return 'open';
}

function money(float $v): string
{
    return number_format($v, 0, '.', ',') . ' ج.م';
}

// ───────────── Routes ─────────────

switch ($action) {

    // Quick check that PHP, SQLite and storage work on the server
    case 'health':
        $dir = data_dir();
        db();
        send_json([
            'ok' => true,
            'php' => PHP_VERSION,
            'sqlite' => extension_loaded('pdo_sqlite'),
            'gd' => extension_loaded('gd'),
            'storageOutsideSite' => !str_starts_with(realpath($dir) ?: $dir, realpath(dirname(__DIR__)) ?: dirname(__DIR__)),
            'time' => now_iso(),
        ]);

    // Everything the public site needs on load
    case 'bootstrap':
        send_json(['ok' => true, 'products' => all_products(), 'settings' => public_settings(), 'serverTime' => now_iso()]);

    // Customer creates a booking (one piece) with deposit proof
    case 'book':
        if ($method !== 'POST') fail(405, 'method', 'POST only');
        $b = body();
        $productId = str_field($b['productId'] ?? '', 80);
        $name = str_field($b['customerName'] ?? '', 120);
        $phone = str_field($b['customerPhone'] ?? '', 40);
        $phoneNorm = normalize_phone($phone);
        $notes = str_field($b['customerNotes'] ?? '', 1000);
        $methodPay = in_array($b['paymentMethod'] ?? '', ['vodafone_cash', 'instapay'], true) ? $b['paymentMethod'] : 'vodafone_cash';
        $ref = str_field($b['paymentReferenceNumber'] ?? '', 80);
        $sentOnWhatsApp = !empty($b['proofSentOnWhatsApp']);
        $proofDataUrl = is_string($b['proofImage'] ?? null) ? $b['proofImage'] : '';

        if ($name === '') fail(422, 'name', 'يرجى إدخال الاسم بالكامل');
        if (strlen($phoneNorm) < 10) fail(422, 'phone', 'يرجى إدخال رقم موبايل صحيح');
        if ($proofDataUrl === '' && !$sentOnWhatsApp && $ref === '') {
            fail(422, 'proof', 'ارفع صورة التحويل أو ابعتها على واتساب الأول، أو اكتب رقم العملية.');
        }

        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $p = get_product($productId);
            if (!$p) fail(404, 'product', 'المنتج ده مش موجود');
            if (($p['status'] ?? '') === 'closed') fail(409, 'closed', 'الحجز على المنتج ده مقفول');
            if (($p['status'] ?? '') === 'coming_soon') fail(409, 'not_started', 'الحجز على المنتج ده لسه مبدأش.');
            $w = booking_window_state($p);
            if ($w === 'ended') fail(409, 'ended', 'للأسف وقت الحجز على المنتج ده خلص.');
            if ($w === 'not_started') fail(409, 'not_started', 'الحجز على المنتج ده لسه مبدأش.');
            if ((int)$p['reservedCount'] >= (int)($p['reservationQuota'] ?? 0)) fail(409, 'sold_out', 'للأسف الكمية المتاحة للحجز خلصت.');

            $dup = $pdo->prepare("SELECT code FROM reservations WHERE product_id = ? AND phone_norm = ? AND status != 'cancelled' LIMIT 1");
            $dup->execute([$productId, $phoneNorm]);
            if ($dupCode = $dup->fetchColumn()) {
                fail(409, 'duplicate', "الرقم ده عنده حجز بالفعل لنفس المنتج (كود $dupCode). مسموح بقطعة واحدة بس لكل عميل.");
            }

            // Keep the code the customer already saw in their WhatsApp message when it's free
            $code = strtoupper(str_field($b['code'] ?? '', 20));
            $exists = $pdo->prepare('SELECT 1 FROM reservations WHERE code = ?');
            if (!preg_match('/^RSV-\d{6}$/', $code) || ($exists->execute([$code]) && $exists->fetchColumn())) {
                do {
                    $code = 'RSV-' . random_int(100000, 999999);
                    $exists->execute([$code]);
                } while ($exists->fetchColumn());
            }

            $proofFile = $proofDataUrl !== '' ? store_data_url_image($proofDataUrl, 'proof') : null;
            $price = (float)($p['price'] ?? 0);
            $deposit = min((float)($p['depositAmount'] ?? 0), $price);
            $now = now_iso();
            $methodLabel = $methodPay === 'instapay' ? 'إنستاباي' : 'فودافون كاش';
            $proofNote = $proofFile ? 'تم رفع صورة التحويل' : ($sentOnWhatsApp ? 'صورة التحويل اتبعتت على واتساب' : 'تم إدخال رقم العملية');

            $r = [
                'id' => rand_id('rsv'),
                'code' => $code,
                'productId' => $p['id'],
                'productNameAr' => $p['nameAr'] ?? '',
                'productNameEn' => $p['nameEn'] ?? '',
                'productImage' => $p['images'][0] ?? '',
                'totalPrice' => $price,
                'depositAmount' => $deposit,
                'remainingAmount' => max(0, $price - $deposit),
                'quantity' => 1,
                'customerName' => $name,
                'customerPhone' => $phone,
                'customerNotes' => $notes !== '' ? $notes : null,
                'paymentMethod' => $methodPay,
                'paymentReferenceNumber' => $ref !== '' ? $ref : null,
                'paymentProofFile' => $proofFile,
                'hasProofImage' => (bool)$proofFile,
                'proofSentOnWhatsApp' => $sentOnWhatsApp ?: null,
                'paymentStatus' => 'pending_verification',
                'reservationStatus' => 'pending',
                'createdAt' => $now,
                'estimatedDeliveryAr' => $p['estimatedArrivalAr'] ?? '',
                'estimatedDeliveryEn' => $p['estimatedArrivalEn'] ?? '',
                'history' => [[
                    'status' => 'pending',
                    'timestamp' => $now,
                    'noteAr' => "تم تسجيل الحجز (قطعة واحدة) ودفع مقدم " . money($deposit) . " $methodLabel — $proofNote. قيد المراجعة.",
                    'noteEn' => "Booking registered (1 piece), deposit via " . ($methodPay === 'instapay' ? 'InstaPay' : 'Vodafone Cash') . ". Under review.",
                ]],
            ];
            $ins = $pdo->prepare('INSERT INTO reservations (id, code, product_id, phone_norm, status, data, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$r['id'], $code, $p['id'], $phoneNorm, 'pending', json_encode($r, JSON_UNESCAPED_UNICODE), $now]);

            $n = [
                'id' => rand_id('notif'),
                'titleAr' => 'حجز جديد — مقدم قيد المراجعة',
                'titleEn' => 'New booking — deposit under review',
                'messageAr' => "$name حجز قطعة من \"{$r['productNameAr']}\" ودفع مقدم " . money($deposit) . " ($code).",
                'messageEn' => "$name booked one \"{$r['productNameEn']}\" ($code).",
                'reservationCode' => $code,
                'timestamp' => $now,
                'isRead' => false,
                'type' => 'new_booking',
            ];
            $pdo->prepare('INSERT INTO notifications (id, data, is_read, created_at) VALUES (?, ?, 0, ?)')
                ->execute([$n['id'], json_encode($n, JSON_UNESCAPED_UNICODE), $now]);

            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
            throw $e;
        }
        send_json(['ok' => true, 'reservation' => public_reservation($r)]);

    // Customer looks up their booking by code or full mobile number
    case 'track':
        $q = strtoupper(str_field($_GET['q'] ?? '', 40));
        $codes = array_filter(array_map(fn($c) => strtoupper(trim($c)), explode(',', (string)($_GET['codes'] ?? ''))));
        $pdo = db();
        if ($codes) {
            $codes = array_slice(array_values(array_filter($codes, fn($c) => preg_match('/^RSV-\d{6}$/', $c))), 0, 30);
            if (!$codes) send_json(['ok' => true, 'reservations' => []]);
            $in = implode(',', array_fill(0, count($codes), '?'));
            $st = $pdo->prepare("SELECT data FROM reservations WHERE code IN ($in) ORDER BY created_at DESC");
            $st->execute($codes);
        } elseif (preg_match('/^RSV-\d{6}$/', $q)) {
            $st = $pdo->prepare('SELECT data FROM reservations WHERE code = ?');
            $st->execute([$q]);
        } else {
            $phone = normalize_phone($q);
            if (strlen($phone) < 10) send_json(['ok' => true, 'reservations' => []]);
            $st = $pdo->prepare('SELECT data FROM reservations WHERE phone_norm = ? ORDER BY created_at DESC LIMIT 20');
            $st->execute([$phone]);
        }
        $list = array_map(fn($r) => public_reservation(json_decode($r['data'], true)), $st->fetchAll());
        send_json(['ok' => true, 'reservations' => $list]);

    // Product images (public)
    case 'media':
        $f = safe_file_name((string)($_GET['f'] ?? ''));
        if (!$f) { http_response_code(404); exit; }
        header('Cache-Control: public, max-age=31536000, immutable');
        serve_file(data_dir() . '/media/' . $f);

    // Transfer screenshots (admin only)
    case 'proof':
        require_admin();
        $f = safe_file_name((string)($_GET['f'] ?? ''));
        if (!$f) { http_response_code(404); exit; }
        header('Cache-Control: private, no-store');
        serve_file(data_dir() . '/proofs/' . $f);

    // ───────────── Admin ─────────────

    case 'login':
        if ($method !== 'POST') fail(405, 'method', 'POST only');
        $pdo = db();
        $ip = client_ip();
        $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - LOGIN_WINDOW]);
        $c = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
        $c->execute([$ip]);
        if ((int)$c->fetchColumn() >= LOGIN_MAX_ATTEMPTS) fail(429, 'too_many', 'محاولات كتير غلط، استنى ربع ساعة وجرّب تاني.');

        $b = body();
        $admin = kv_get('admin');
        $userOk = strcasecmp(trim((string)($b['username'] ?? '')), (string)$admin['username']) === 0;
        $passOk = password_verify((string)($b['password'] ?? ''), (string)$admin['hash']);
        if (!$userOk || !$passOk) {
            $pdo->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
            fail(401, 'bad_login', 'اسم المستخدم أو الباسورد غلط، جرّب تاني.');
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        $pdo->prepare('DELETE FROM sessions WHERE expires_at < ?')->execute([time()]);
        $token = bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO sessions (token, expires_at) VALUES (?, ?)')->execute([hash('sha256', $token), time() + SESSION_TTL]);
        send_json(['ok' => true, 'token' => $token, 'username' => $admin['username']]);

    case 'logout':
        $t = bearer_token();
        if ($t) db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([hash('sha256', $t)]);
        send_json(['ok' => true]);

    case 'admin_data':
        require_admin();
        $token = bearer_token();
        $res = array_map(fn($r) => admin_reservation(json_decode($r['data'], true), $token),
            db()->query('SELECT data FROM reservations ORDER BY created_at DESC')->fetchAll());
        $notifs = array_map(function ($r) {
            $n = json_decode($r['data'], true);
            $n['isRead'] = (bool)$r['is_read'];
            return $n;
        }, db()->query('SELECT data, is_read FROM notifications ORDER BY created_at DESC LIMIT 100')->fetchAll());
        $settings = public_settings();
        $settings['adminUsername'] = kv_get('admin')['username'];
        $settings['adminPassword'] = '';
        send_json(['ok' => true, 'products' => all_products(), 'reservations' => $res, 'notifications' => $notifs, 'settings' => $settings]);

    case 'product_save':
        require_admin();
        $p = body()['product'] ?? null;
        if (!is_array($p)) fail(422, 'product', 'بيانات المنتج ناقصة');
        $id = preg_match('/^[A-Za-z0-9_-]{3,64}$/', (string)($p['id'] ?? '')) ? (string)$p['id'] : rand_id('prod');
        if (str_field($p['nameAr'] ?? '') === '') fail(422, 'name', 'يرجى كتابة اسم المنتج بالعربية');
        $images = [];
        foreach ((array)($p['images'] ?? []) as $img) {
            if (!is_string($img) || $img === '') continue;
            if (str_starts_with($img, 'data:')) $images[] = media_url(store_data_url_image($img, 'media'));
            elseif (preg_match('#^(https?://|/api/index\.php\?action=media)#', $img)) $images[] = $img;
        }
        $p['id'] = $id;
        $p['images'] = array_slice($images, 0, 8);
        unset($p['reservedCount']);
        $existing = db()->prepare('SELECT created_at FROM products WHERE id = ?');
        $existing->execute([$id]);
        $created = $existing->fetchColumn() ?: now_iso();
        $p['createdAt'] = $p['createdAt'] ?? $created;
        db()->prepare('INSERT INTO products (id, data, created_at) VALUES (?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET data = excluded.data')
            ->execute([$id, json_encode($p, JSON_UNESCAPED_UNICODE), $created]);
        send_json(['ok' => true, 'product' => get_product($id)]);

    case 'product_delete':
        require_admin();
        $id = str_field(body()['id'] ?? '', 80);
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        send_json(['ok' => true]);

    case 'reservation_update':
        require_admin();
        $b = body();
        $id = str_field($b['id'] ?? '', 80);
        $st = db()->prepare('SELECT data FROM reservations WHERE id = ?');
        $st->execute([$id]);
        $d = $st->fetchColumn();
        if ($d === false) fail(404, 'not_found', 'الحجز ده مش موجود');
        $r = json_decode($d, true);
        $patch = is_array($b['patch'] ?? null) ? $b['patch'] : [];
        if (isset($patch['reservationStatus']) && in_array($patch['reservationStatus'], STATUSES, true)) $r['reservationStatus'] = $patch['reservationStatus'];
        if (isset($patch['paymentStatus']) && in_array($patch['paymentStatus'], PAYMENT_STATUSES, true)) $r['paymentStatus'] = $patch['paymentStatus'];
        if (array_key_exists('merchantNotes', $patch)) $r['merchantNotes'] = str_field($patch['merchantNotes'], 2000);
        if (isset($patch['history']) && is_array($patch['history'])) {
            $r['history'] = array_values(array_map(fn($h) => [
                'status' => in_array($h['status'] ?? '', STATUSES, true) ? $h['status'] : 'pending',
                'timestamp' => str_field($h['timestamp'] ?? now_iso(), 40),
                'noteAr' => str_field($h['noteAr'] ?? '', 500),
                'noteEn' => str_field($h['noteEn'] ?? '', 500),
            ], array_slice($patch['history'], -50)));
        }
        db()->prepare('UPDATE reservations SET data = ?, status = ? WHERE id = ?')
            ->execute([json_encode($r, JSON_UNESCAPED_UNICODE), $r['reservationStatus'], $id]);
        send_json(['ok' => true]);

    case 'reservation_delete':
        require_admin();
        $ids = array_slice(array_filter((array)(body()['ids'] ?? []), 'is_string'), 0, 500);
        $sel = db()->prepare('SELECT data FROM reservations WHERE id = ?');
        $del = db()->prepare('DELETE FROM reservations WHERE id = ?');
        $delN = db()->prepare("DELETE FROM notifications WHERE json_extract(data, '$.reservationCode') = ?");
        foreach ($ids as $id) {
            $sel->execute([$id]);
            if ($d = $sel->fetchColumn()) {
                $r = json_decode($d, true);
                if (!empty($r['paymentProofFile']) && ($f = safe_file_name($r['paymentProofFile']))) @unlink(data_dir() . '/proofs/' . $f);
                $delN->execute([$r['code'] ?? '']);
            }
            $del->execute([$id]);
        }
        send_json(['ok' => true, 'deleted' => count($ids)]);

    case 'settings_save':
        require_admin();
        $s = body()['settings'] ?? null;
        if (!is_array($s)) fail(422, 'settings', 'الإعدادات ناقصة');
        unset($s['adminUsername'], $s['adminPassword']);
        kv_set('settings', $s);
        send_json(['ok' => true, 'settings' => public_settings()]);

    case 'credentials_save':
        require_admin();
        $b = body();
        $admin = kv_get('admin');
        $username = str_field($b['username'] ?? '', 60);
        if ($username === '') fail(422, 'username', 'اكتب اسم المستخدم');
        if (!password_verify((string)($b['current'] ?? ''), (string)$admin['hash'])) fail(403, 'bad_password', 'الباسورد الحالي غلط');
        $new = (string)($b['new'] ?? '');
        $admin['username'] = $username;
        if ($new !== '') $admin['hash'] = password_hash($new, PASSWORD_DEFAULT);
        kv_set('admin', $admin);
        send_json(['ok' => true, 'username' => $username]);

    case 'notifications_read':
        require_admin();
        $b = body();
        if (!empty($b['all'])) db()->exec('UPDATE notifications SET is_read = 1');
        else {
            $st = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?');
            foreach (array_slice((array)($b['ids'] ?? []), 0, 200) as $id) if (is_string($id)) $st->execute([$id]);
        }
        send_json(['ok' => true]);

    default:
        fail(404, 'unknown_action', 'Unknown action');
}
