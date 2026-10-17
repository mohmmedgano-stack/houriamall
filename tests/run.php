<?php
/**
 * Test runner: php tests/run.php [filter]
 * Every test runs against a fresh temporary SQLite database — never the real data.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$tmp = __DIR__ . '/_tmp/' . getmypid();
@mkdir($tmp, 0777, true);
putenv('MBCC_DATA_DIR=' . $tmp);
$GLOBALS['CC_TESTING'] = true;
require __DIR__ . '/../src/bootstrap.php';
restore_exception_handler();
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$GLOBALS['__asserts'] = 0;
function ok($cond, string $msg = 'assertion failed'): void { $GLOBALS['__asserts']++; if (!$cond) throw new RuntimeException($msg); }
function eq($a, $b, string $msg = ''): void {
    $GLOBALS['__asserts']++;
    $same = is_float($a) || is_float($b) ? ($a !== null && $b !== null && abs((float)$a - (float)$b) < 1e-6) || ($a === null && $b === null) : $a === $b;
    if (!$same) throw new RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
}
function throws(callable $fn, string $cls = Throwable::class, string $msg = ''): void {
    $GLOBALS['__asserts']++;
    try { $fn(); } catch (Throwable $e) { if ($e instanceof $cls) return; throw new RuntimeException("$msg: expected $cls, got " . get_class($e) . ': ' . $e->getMessage()); }
    throw new RuntimeException("$msg: expected $cls to be thrown");
}
/** Fresh DB + an owner user logged in (in-process). */
function fresh(string $role = 'owner'): array {
    static $n = 0; $n++;
    db_reset($GLOBALS['__tmp'] . "/t$n.sqlite");
    $_SESSION = ['mode' => 'live'];
    unset($GLOBALS['__scope']);
    $id = create_user('Test ' . $role, "u$n@test.local", 'password123', $role);
    $u = q_one('SELECT * FROM users WHERE id = ?', [$id]);
    set_current_user($u);
    return $u;
}
function as_user(array $u): void { set_current_user($u); unset($GLOBALS['__scope']); }
$GLOBALS['__tmp'] = $tmp;

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/test_*.php');
foreach ($files as $f) require_once $f;
$pass = 0; $fail = 0; $failed = [];
foreach (get_defined_functions()['user'] as $fn) {
    if (!str_starts_with($fn, 'test_') || ($filter && !str_contains($fn, $filter))) continue;
    try { $fn(); $pass++; echo "  ✓ $fn\n"; }
    catch (Throwable $e) { $fail++; $failed[] = $fn; echo "  ✗ $fn — " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"; }
}
array_map('unlink', glob("$tmp/*") ?: []); @rmdir($tmp);
echo "\n$pass passed, $fail failed, {$GLOBALS['__asserts']} assertions\n";
exit($fail ? 1 : 0);
