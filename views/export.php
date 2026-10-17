<?php
// File downloads. Each kind checks its own permission and client scope.
$kind = in_str('kind');
if ($kind === 'backup') {
    if (!is_post()) redirect(url('settings'));
    require_perm('settings.manage');
    $tmp = tempnam(sys_get_temp_dir(), 'mbcc');
    @unlink($tmp);
    db()->exec('VACUUM INTO ' . db()->quote($tmp));
    audit('backup.download');
    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="command-center-backup-' . gmdate('Ymd-His') . '.sqlite"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp);
    exit;
}
if (function_exists('export_dispatch')) { export_dispatch($kind); exit; }
http_response_code(404); echo 'Unknown export';
