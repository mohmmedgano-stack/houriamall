<?php
declare(strict_types=1);

/** Route file downloads. Every branch checks its own permission; data is always scoped to the user's clients. */
function export_dispatch(string $kind): void {
    $fmt = in_str('format') === 'xlsx' ? 'xlsx' : 'csv';
    switch ($kind) {
        case 'perf':
            require_perm('analytics.view');
            $f = perf_filters();
            $rows = perf_rows($f['clients'], $f['from'], $f['to'], ['account' => $f['account'], 'campaign' => $f['campaign'], 'goal' => $f['goal']]);
            $items = entity_table($rows, $f['level']);
            $head = ['Level', 'Name', 'Campaign', 'Account', 'Goal', 'Currency', 'From', 'To'];
            foreach (TABLE_METRICS as $k) $head[] = metric_label($k);
            $out = [$head];
            foreach ($items as $it) {
                $line = [$f['level'], $it['name'], $it['campaign_name'], $it['account_name'], $it['goal'], $it['currency'], $f['from'], $f['to']];
                foreach (TABLE_METRICS as $k) $line[] = $it['m'][$k] === null ? 'N/A' : round((float)$it['m'][$k], 4);
                $out[] = $line;
            }
            audit('export.perf', null, null, ['rows' => count($items), 'format' => $fmt]);
            $name = 'performance-' . $f['level'] . '-' . $f['from'] . '_' . $f['to'] . '.' . $fmt;
            $fmt === 'xlsx' ? send_xlsx($name, $out, 'Performance') : send_csv($name, $out);
        default:
            if (function_exists('export_more') && export_more($kind, $fmt)) return;
            http_response_code(404); echo 'Unknown export';
    }
}

/** Exports added by later modules. Returns true when handled. */
function export_more(string $kind, string $fmt): bool {
    if ($kind === 'leads') {
        require_perm('crm.view');
        [$w, $p] = crm_filters_sql();
        $rows = lead_export_rows($w, $p);
        audit('export.leads', null, null, ['rows' => count($rows) - 1, 'format' => $fmt]);   // personal data export is always logged
        $name = 'leads-' . today() . '.' . $fmt;
        $fmt === 'xlsx' ? send_xlsx($name, $rows, 'Leads') : send_csv($name, $rows);
    }
    if (function_exists('export_reports') && export_reports($kind, $fmt)) return true;
    return false;
}
