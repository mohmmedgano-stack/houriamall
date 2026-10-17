<?php
declare(strict_types=1);

function now(): string { return gmdate('Y-m-d H:i:s'); }
function today(): string { return local_date('Y-m-d'); }
function local_date(string $fmt, ?int $ts = null): string {
    $d = new DateTime('@' . ($ts ?? time()));
    $d->setTimezone(new DateTimeZone(app_tz()));
    return $d->format($fmt);
}
function ar_month(?string $date = null): string {
    $m = (int)local_date('n', $date ? strtotime($date) : null);
    return ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'][$m] . ' ' . local_date('Y', $date ? strtotime($date) : null);
}
function ar_date(?string $date = null): string {
    $ts = $date ? strtotime($date) : null;
    $days = ['Sunday' => 'الأحد', 'Monday' => 'الاتنين', 'Tuesday' => 'التلات', 'Wednesday' => 'الأربع', 'Thursday' => 'الخميس', 'Friday' => 'الجمعة', 'Saturday' => 'السبت'];
    return $days[local_date('l', $ts)] . ' ' . local_date('j', $ts) . ' ' . ar_month($date);
}
function app_tz(): string { return $GLOBALS['CC_USER']['timezone'] ?? 'Africa/Cairo'; }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }

/* ---------- request ---------- */
function in_str(string $k, string $default = ''): string {
    $v = $_POST[$k] ?? $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}
function in_int(string $k, ?int $default = null): ?int {
    $v = in_str($k);
    return preg_match('/^-?\d+$/', $v) ? (int)$v : $default;
}
function in_num(string $k): ?float {
    $v = str_replace([',', '٬'], '', to_latin_digits(in_str($k)));
    return is_numeric($v) ? (float)$v : null;
}
function in_date(string $k, ?string $default = null): ?string {
    $v = in_str($k);
    return valid_date($v) ? $v : $default;
}
function valid_date(?string $v): bool {
    if (!$v || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y);
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'); }

function to_latin_digits(string $s): string {
    return strtr($s, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '٫' => '.']);
}

/* ---------- session / csrf / flash ---------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('mbcc');
    session_set_cookie_params(['lifetime' => 0, 'path' => base_path() . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 12));
    session_start();
    header('Cache-Control: no-store, private');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function check_csrf(): void {
    $t = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('انتهت صلاحية الصفحة، ارجع وحدّثها وجرّب تاني.');
    }
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

class HttpRedirect extends Exception { public function __construct(public string $to) { parent::__construct($to); } }
function redirect(string $to): never {
    if (PHP_SAPI === 'cli' && !empty($GLOBALS['CC_TESTING'])) throw new HttpRedirect($to);
    header('Location: ' . $to);
    exit;
}
function url(string $route, array $params = []): string {
    $q = array_merge(['r' => $route], array_filter($params, fn($v) => $v !== null && $v !== ''));
    return base_path() . '/?' . http_build_query($q);
}
function base_path(): string {
    if (PHP_SAPI === 'cli') return '';
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
}
function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- formatting ---------- */
/** Format a number; null means "not available" and prints N/A. */
function fmt_num($v, int $dec = 0): string {
    if ($v === null || (is_float($v) && (is_nan($v) || is_infinite($v)))) return '<span class="na" title="البيانات غير متاحة">N/A</span>';
    return number_format((float)$v, $dec);
}
function fmt_money($v, ?string $cur, int $dec = 2): string {
    if ($v === null) return fmt_num(null);
    return '<bdi>' . number_format((float)$v, $dec) . '</bdi> <small class="cur">' . h($cur ?: '') . '</small>';
}
function fmt_pct($v, int $dec = 2): string {
    if ($v === null) return fmt_num(null);
    return number_format((float)$v * 100, $dec) . '%';
}
function fmt_plain($v, int $dec = 2): string { return $v === null ? 'N/A' : number_format((float)$v, $dec, '.', ''); }
function fmt_dt(?string $utc, string $fmt = 'Y-m-d H:i'): string {
    if (!$utc) return '—';
    try { $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone(app_tz())); return $d->format($fmt); }
    catch (Throwable $e) { return $utc; }
}
function pct_change(?float $cur, ?float $prev): ?float {
    if ($cur === null || $prev === null || $prev == 0.0) return null;
    return ($cur - $prev) / abs($prev);
}

/* ---------- pagination ---------- */
function paginate(string $countSql, array $params, int $per = 25): array {
    $total = (int)q_val($countSql, $params);
    $page = max(1, (int)in_int('page', 1));
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => ($page - 1) * $per];
}
function pager_html(array $p): string {
    if ($p['pages'] <= 1) return '<div class="pager muted">' . number_format($p['total']) . ' نتيجة</div>';
    $q = $_GET; $out = '<div class="pager"><span class="muted">' . number_format($p['total']) . ' نتيجة</span>';
    foreach ([['‹ السابق', $p['page'] - 1], ['التالي ›', $p['page'] + 1]] as [$lbl, $n]) {
        if ($n < 1 || $n > $p['pages']) { $out .= '<span class="btn sm ghost disabled">' . $lbl . '</span>'; continue; }
        $q['page'] = $n; $out .= '<a class="btn sm ghost" href="?' . h(http_build_query($q)) . '">' . $lbl . '</a>';
    }
    return $out . '<span class="muted">صفحة ' . $p['page'] . ' من ' . $p['pages'] . '</span></div>';
}
/** Whitelisted ORDER BY from ?sort=col&dir=asc|desc */
function sort_sql(array $allowed, string $default, string $defaultDir = 'desc'): string {
    $s = in_str('sort'); $d = strtolower(in_str('dir', $defaultDir)) === 'asc' ? 'ASC' : 'DESC';
    $col = $allowed[$s] ?? $allowed[$default];
    return " ORDER BY $col $d";
}
function sort_link(string $key, string $label): string {
    $q = $_GET; $cur = ($q['sort'] ?? '') === $key; $dir = $cur && strtolower($q['dir'] ?? '') === 'desc' ? 'asc' : 'desc';
    $q['sort'] = $key; $q['dir'] = $dir; unset($q['page']);
    return '<a class="sort' . ($cur ? ' on ' . h($q['dir'] === 'asc' ? 'desc' : 'asc') : '') . '" href="?' . h(http_build_query($q)) . '">' . h($label) . '</a>';
}

function norm_ar(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s) ?? $s;
    return strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
}

/** Egyptian-aware phone normalisation for duplicate detection: digits only, 01xxxxxxxxx → 201xxxxxxxxx. */
function norm_phone(?string $p): ?string {
    $d = preg_replace('/\D+/', '', to_latin_digits((string)$p)) ?? '';
    if ($d === '') return null;
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (preg_match('/^01\d{9}$/', $d)) $d = '2' . $d;
    if (preg_match('/^1\d{9}$/', $d)) $d = '20' . $d;
    return strlen($d) >= 7 ? $d : null;
}
function norm_email(?string $e): ?string {
    $e = mb_strtolower(trim((string)$e));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
}
function currencies(): array { return ['EGP', 'USD', 'SAR', 'AED', 'EUR', 'KWD', 'QAR', 'GBP']; }
