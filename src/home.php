<?php
declare(strict_types=1);

function home_widget_defs(): array {
    return ['kpis' => ['أداء آخر 7 أيام', 'analytics.view'], 'alerts' => ['التنبيهات المفتوحة', 'alerts.view'], 'followups' => ['متابعات الليدز المتأخرة', 'crm.view'],
        'budgets' => ['الميزانيات القريبة من الحد', 'budgets.view'], 'pipeline' => ['الـ Pipeline', 'crm.view'], 'invoices' => ['فواتير مستحقة', 'finance.view'],
        'sync' => ['حالة البيانات والتكاملات', 'analytics.view']];
}
function home_prefs(): array {
    $p = json_decode((string)setting('home_widgets_' . uid(), ''), true);
    $defs = home_widget_defs();
    return is_array($p) ? array_values(array_filter($p, fn($k) => isset($defs[$k]))) : array_keys($defs);
}

function home_widgets(array $ids): void {
    if (is_post() && in_str('a') === 'widgets') {
        set_setting('home_widgets_' . uid(), json_encode(array_values(array_intersect(array_keys(home_widget_defs()), (array)($_POST['w'] ?? [])))));
        redirect(url('home'));
    }
    $defs = home_widget_defs(); $on = home_prefs();
    $vis = fn($k) => in_array($k, $on, true) && can($defs[$k][1]);
    $u = current_user();
    echo '<div class="row" style="margin-bottom:14px"><div><b style="font-size:18px">أهلاً ' . h(explode(' ', $u['name'])[0]) . ' 👋</b><div class="muted small">' . h(local_date('l j F Y')) . ' · ' . count($ids) . ' عميل' . (mode() === 'demo' ? ' (Demo)' : '') . '</div></div><span class="sp"></span>';
    echo '<details class="box" style="padding:6px 12px"><summary class="small">تخصيص الصفحة</summary><form method="post" style="margin-top:8px">' . csrf_field() . '<input type="hidden" name="a" value="widgets">';
    foreach ($defs as $k => [$l, $perm]) if (can($perm)) echo '<label class="check" style="display:flex;margin:4px 0"><input type="checkbox" name="w[]" value="' . $k . '"' . (in_array($k, $on, true) ? ' checked' : '') . '> ' . h($l) . '</label>';
    echo '<button class="btn sm" style="margin-top:6px">حفظ</button></form></details></div>';

    if (!$ids) {
        echo '<div class="card">' . empty_state('ابدأ بإضافة أول عميل', ' كل الأدوات بتشتغل على العملاء وحساباتهم الإعلانية.', can('clients.manage') ? '<a class="btn" href="' . h(url('clients')) . '">' . icon('plus') . ' إضافة عميل</a>' : '') . '</div>';
        if (is_admin() && mode() === 'live') echo '<p class="muted small" style="text-align:center">عايز تشوف المنصة مليانة الأول؟ من <a href="' . h(url('settings')) . '">الإعدادات</a> اعمل بيانات تجريبية (Demo) — بتفضل منفصلة تماماً.</p>';
        return;
    }

    if ($vis('kpis')) {
        $to = today(); $from = (new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
        [$pf, $pt] = previous_period($from, $to);
        $tot = totals_by_currency(perf_rows($ids, $from, $to)); $prev = totals_by_currency(perf_rows($ids, $pf, $pt));
        echo '<div class="card" style="margin-bottom:16px"><h2>' . icon('chart') . ' أداء آخر 7 أيام<span class="sp"></span><a class="btn sm ghost" href="' . h(url('dashboard', ['from' => $from, 'to' => $to])) . '">التفاصيل</a></h2>';
        if (!$tot) echo '<p class="muted">مفيش بيانات أداء في آخر 7 أيام. <a href="' . h(url('import')) . '">استورد بيانات</a>.</p>';
        foreach ($tot as $cur => $g) {
            $m = $g['m']; $p = $prev[$cur]['m'] ?? [];
            echo '<div class="kpis" style="margin-bottom:8px">';
            foreach (['spend', 'leads', 'cpl', 'purchases', 'roas', 'link_ctr', 'cpm'] as $k) {
                if (in_array($k, ['leads', 'cpl'], true) && $m['leads'] === null) continue;
                if (in_array($k, ['purchases', 'roas'], true) && $m['purchases'] === null) continue;
                echo kpi(metric_label($k) . ($k === 'spend' ? " ($cur)" : ''), fmt_metric($k, $m[$k], $cur), pct_change($m[$k] === null ? null : (float)$m[$k], isset($p[$k]) ? (float)$p[$k] : null), metric_lower_better($k));
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '<div class="grid g2">';
    if ($vis('alerts')) {
        $al = q_all("SELECT a.*, c.name cname FROM alerts a LEFT JOIN clients c ON c.id = a.client_id WHERE a.status = 'open' AND a.client_id IN (" . in_list($ids) . ") ORDER BY CASE a.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, a.id DESC LIMIT 6");
        echo '<div class="card"><h2>' . icon('bell') . ' التنبيهات المفتوحة<span class="sp"></span><a class="small" href="' . h(url('alerts')) . '">الكل</a></h2>';
        if (!$al) echo '<p class="muted small">مفيش تنبيهات مفتوحة 👌</p>';
        foreach ($al as $a) echo '<div class="row small" style="padding:5px 0;border-bottom:1px solid var(--line)">' . sev_badge($a['severity']) . '<span style="flex:1">' . h($a['title']) . '</span><span class="muted">' . h($a['cname']) . '</span></div>';
        echo '</div>';
    }
    if ($vis('followups')) {
        $where = 'l.client_id IN (' . in_list($ids) . ')' . (can('crm.view_all') ? '' : ' AND l.owner_user_id = ' . (int)uid());
        $over = q_all("SELECT l.*, c.name cname FROM leads l JOIN clients c ON c.id = l.client_id JOIN pipeline_stages s ON s.id = l.stage_id
            WHERE $where AND s.is_won = 0 AND s.is_lost = 0 AND l.duplicate_of IS NULL AND l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < ? ORDER BY l.next_follow_up_at LIMIT 6", [now()]);
        $n = (int)q_val("SELECT COUNT(*) FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id WHERE $where AND s.is_won = 0 AND s.is_lost = 0 AND l.duplicate_of IS NULL AND l.next_follow_up_at < ?", [now()]);
        echo '<div class="card"><h2>' . icon('inbox') . ' متابعات متأخرة ' . ($n ? '<span class="badge b-bad">' . $n . '</span>' : '') . '<span class="sp"></span><a class="small" href="' . h(url('crm', ['view' => 'overdue'])) . '">الكل</a></h2>';
        if (!$over) echo '<p class="muted small">مفيش متابعات متأخرة.</p>';
        foreach ($over as $l) echo '<div class="row small" style="padding:5px 0;border-bottom:1px solid var(--line)"><a href="' . h(url('lead', ['id' => $l['id']])) . '" style="flex:1">' . h($l['full_name'] ?: $l['phone'] ?: 'ليد #' . $l['id']) . '</a><span class="muted">' . h($l['cname']) . '</span><span class="badge b-warn ltr">' . h(fmt_dt($l['next_follow_up_at'], 'm-d H:i')) . '</span></div>';
        echo '</div>';
    }
    if ($vis('budgets')) {
        $bs = array_filter(budgets_for($ids), fn($b) => $b['period_start'] <= today() && $b['period_end'] >= today());
        echo '<div class="card"><h2>' . icon('wallet') . ' الميزانيات الحالية<span class="sp"></span><a class="small" href="' . h(url('budgets')) . '">الكل</a></h2>';
        if (!$bs) echo '<p class="muted small">مفيش ميزانيات شغالة النهارده.</p>';
        foreach (array_slice($bs, 0, 6) as $b) { $s = budget_status($b); $p = $s['pct_used'] ?? 0;
            echo '<div style="padding:5px 0"><div class="row small"><a href="' . h(url('budgets', ['view' => $b['id']])) . '">' . h($b['name']) . '</a><span class="muted">' . h($b['client_name']) . '</span><span class="sp"></span>' . round($p * 100) . '%</div><div class="bar ' . ($p > 1 ? 'bad' : ($p * 100 >= $b['alert_pct'] ? 'warn' : '')) . '"><i style="width:' . min(100, round($p * 100)) . '%"></i></div></div>'; }
        echo '</div>';
    }
    if ($vis('pipeline')) {
        $where = 'l.client_id IN (' . in_list($ids) . ') AND l.duplicate_of IS NULL' . (can('crm.view_all') ? '' : ' AND l.owner_user_id = ' . (int)uid());
        $st = q_all("SELECT s.name, s.color, COUNT(l.id) n FROM pipeline_stages s LEFT JOIN leads l ON l.stage_id = s.id AND $where GROUP BY s.id ORDER BY s.position");
        $max = max(1, ...array_map(fn($x) => (int)$x['n'], $st));
        echo '<div class="card"><h2>' . icon('list') . ' الـ Pipeline<span class="sp"></span><a class="small" href="' . h(url('crm', ['view' => 'board'])) . '">اللوحة</a></h2>';
        foreach ($st as $s) echo '<div class="row small" style="margin:4px 0"><span style="width:90px">' . h($s['name']) . '</span><div class="bar" style="flex:1"><i style="width:' . round($s['n'] / $max * 100) . '%;background:' . h($s['color']) . '"></i></div><b style="width:40px;text-align:end">' . (int)$s['n'] . '</b></div>';
        echo '</div>';
    }
    if ($vis('invoices')) {
        $inv = q_all("SELECT i.*, c.name cname FROM client_invoices i JOIN clients c ON c.id = i.client_id WHERE i.client_id IN (" . in_list($ids) . ") AND i.status <> 'paid' AND i.status <> 'void' ORDER BY i.due_date LIMIT 6");
        echo '<div class="card"><h2>' . icon('coins') . ' فواتير مستحقة<span class="sp"></span><a class="small" href="' . h(url('finance')) . '">الكل</a></h2>';
        if (!$inv) echo '<p class="muted small">مفيش فواتير مستحقة.</p>';
        foreach ($inv as $i) echo '<div class="row small" style="padding:5px 0;border-bottom:1px solid var(--line)"><span style="flex:1">' . h($i['cname']) . ' — ' . h($i['description'] ?: $i['number']) . '</span><b>' . fmt_money((float)$i['amount'] - (float)$i['paid_amount'], $i['currency'], 0) . '</b><span class="badge ' . ($i['due_date'] < today() ? 'b-bad' : 'b-warn') . ' ltr">' . h($i['due_date']) . '</span></div>';
        echo '</div>';
    }
    if ($vis('sync')) {
        $acc = q_all('SELECT a.*, c.name cname, (SELECT MAX(date_end) FROM performance_snapshots p WHERE p.ad_account_id = a.id) last_data FROM ad_accounts a JOIN clients c ON c.id = a.client_id WHERE a.client_id IN (' . in_list($ids) . ") AND a.status = 'active' ORDER BY last_data LIMIT 8");
        echo '<div class="card"><h2>' . icon('plug') . ' حالة البيانات</h2>';
        foreach ($acc as $a) {
            $age = $a['last_data'] ? (int)((strtotime(today()) - strtotime($a['last_data'])) / 86400) : null;
            echo '<div class="row small" style="padding:4px 0"><span class="status-dot ' . ($a['last_sync_error'] ? 'bad' : ($age === null || $age > 3 ? 'warn' : 'ok')) . '"></span><span style="flex:1">' . h($a['cname']) . ' — ' . h($a['name']) . '</span><span class="muted">' . ($a['sync_enabled'] ? 'Meta API' : 'يدوي') . ' · آخر بيانات ' . h($a['last_data'] ?: 'مفيش') . '</span></div>';
        }
        if (!$acc) echo '<p class="muted small">مفيش حسابات إعلانية.</p>';
        echo '</div>';
    }
    echo '</div>';
}
