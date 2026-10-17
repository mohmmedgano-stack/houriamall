<?php
/**
 * Command line tools (server only):
 *   php bin/cli.php create-user "Name" email@x.com 'Password123' owner|admin|buyer|sales|viewer
 *   php bin/cli.php reset-password email@x.com 'NewPassword123'
 *   php bin/cli.php backup /path/to/backup.sqlite
 *   php bin/cli.php sync <ad_account_id> [days]
 *   php bin/cli.php worker            (same as worker.php, verbose)
 *   php bin/cli.php setup-key         (prints the first-run setup key)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/bootstrap.php';
$cmd = $argv[1] ?? '';
try {
    switch ($cmd) {
        case 'create-user':
            [$name, $email, $pw, $role] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? 'owner'];
            $id = create_user($name, $email, $pw, $role);
            if ($role === 'owner') @unlink(DATA_DIR . '/setup.key');
            echo "created user #$id ($role)\n"; break;
        case 'reset-password':
            $u = q_one('SELECT * FROM users WHERE email = ?', [$argv[2] ?? '']);
            if (!$u) throw new InvalidArgumentException('no such user');
            if ($e = validate_password($argv[3] ?? '')) throw new InvalidArgumentException($e);
            q('UPDATE users SET password_hash = ?, active = 1 WHERE id = ?', [password_hash($argv[3], PASSWORD_DEFAULT), $u['id']]);
            q('DELETE FROM login_attempts');
            echo "password reset\n"; break;
        case 'backup':
            $to = $argv[2] ?? DATA_DIR . '/backup-' . gmdate('Ymd-His') . '.sqlite';
            db()->exec('VACUUM INTO ' . db()->quote($to));
            echo "backup written to $to\n"; break;
        case 'sync':
            $s = meta_sync_account((int)($argv[2] ?? 0), (new DateTimeImmutable(today()))->modify('-' . (int)($argv[3] ?? 30) . ' days')->format('Y-m-d'), today());
            echo json_encode($s), "\n"; break;
        case 'worker':
            $argv[] = '-v'; require __DIR__ . '/../worker.php'; break;
        case 'setup-key':
            echo needs_setup() ? setup_key() . "\n" : "setup already done\n"; break;
        default:
            echo file_get_contents(__FILE__, false, null, 0, 700);
    }
} catch (Throwable $e) { fwrite(STDERR, 'error: ' . $e->getMessage() . "\n"); exit(1); }
