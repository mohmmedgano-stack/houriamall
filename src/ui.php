<?php
declare(strict_types=1);

function icon(string $n): string {
    $p = [
        'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-3"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.6c1.8.7 3 2.5 3.5 5.4"/>',
        'inbox' => '<path d="M3 13l3-8h12l3 8"/><path d="M3 13v6h18v-6h-5l-1.5 2.5h-5L8 13z"/>',
        'bell' => '<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M16 12.5h2"/><path d="M3 9h18"/>',
        'check' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7H4V5h11"/>',
        'pen' => '<path d="M4 20l4-1 11-11-3-3L5 16z"/><path d="M14 6l3 3"/>',
        'target' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><circle cx="12" cy="12" r=".8"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
        'star' => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        'coins' => '<ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3V7"/><path d="M15 11c3.3 0 6 1.3 6 3v4c0 1.7-2.7 3-6 3-2.1 0-4-.5-5-1.4"/>',
        'zap' => '<path d="M13 3L4 14h7l-1 7 9-11h-7z"/>',
        'msg' => '<path d="M4 5h16v11H9l-5 4z"/>',
        'plug' => '<path d="M9 3v5M15 3v5"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v4"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'upload' => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v4h16v-4"/>',
        'download' => '<path d="M12 4v12"/><path d="M7 11l5 5 5-5"/><path d="M4 16v4h16v-4"/>',
        'logout' => '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>',
        'more' => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
        'columns' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'print' => '<path d="M7 8V3h10v5"/><rect x="3" y="8" width="18" height="9" rx="2"/><path d="M7 14h10v7H7z"/>',
    ][$n] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Sidebar entries: [route, label, icon, permission] grouped by section. */
function nav_items(): array {
    return [
        '' => [['home', 'الرئيسية', 'home', null]],
        'التحليلات' => [
            ['dashboard', 'Dashboard', 'chart', 'analytics.view'], ['creatives', 'Creative Analyzer', 'image', 'creatives.view'],
            ['audience', 'Audience Tester', 'target', 'audience.view'], ['budgets', 'Budget Planner', 'wallet', 'budgets.view'],
            ['auditor', 'Campaign Auditor', 'check', 'audits.view'],
        ],
        'الليدز' => [['crm', 'Lead CRM', 'inbox', 'crm.view'], ['leadquality', 'Lead Quality', 'star', 'leadquality.view']],
        'المتابعة' => [['alerts', 'Campaign Watchdog', 'bell', 'alerts.view'], ['reports', 'Auto Reports', 'file', 'reports.view']],
        'الأتمتة والإبداع' => [
            ['automation', 'Automation Hub', 'zap', 'automation.view'], ['autoreply', 'Facebook Auto Reply', 'msg', 'autoreply.view'],
            ['copy', 'Ad Copy & Hooks', 'pen', 'copy.use'],
        ],
        'البيزنس' => [['clients', 'العملاء والحسابات', 'users', 'clients.view'], ['finance', 'Client & Profit', 'coins', 'finance.view']],
        'النظام' => [
            ['import', 'استيراد بيانات', 'upload', 'analytics.import'], ['integrations', 'التكاملات', 'plug', 'integrations.manage'],
            ['users', 'المستخدمين', 'shield', 'users.manage'], ['settings', 'الإعدادات', 'gear', null], ['auditlog', 'سجل العمليات', 'list', 'auditlog.view'],
        ],
    ];
}

function layout_start(string $title, array $opt = []): void {
    $u = current_user();
    $route = $GLOBALS['CC_ROUTE'] ?? 'home';
    $openAlerts = $u ? (int)q_val("SELECT COUNT(*) FROM alerts WHERE status = 'open' AND client_id IN (" . in_list(scope_client_ids()) . ')') : 0;
    $unread = $u ? (int)q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u['id']]) : 0;
    $flashes = take_flashes(); ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title) ?> · Command Center</title>
<script>try{var t=JSON.parse(localStorage.getItem('mbcc.theme')||'"auto"');if(t!=='auto')document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%230f766e'/><text x='16' y='21' font-size='13' text-anchor='middle' fill='white' font-family='monospace' font-weight='bold'>CC</text></svg>">
</head>
<body>
<?php if ($u && mode() === 'demo'): ?><div class="demo-banner">وضع DEMO — البيانات دي تجريبية ومنفصلة تماماً عن بياناتك الحقيقية</div><?php endif; ?>
<div class="shell">
  <aside class="side" aria-label="القائمة">
    <div class="brand"><span class="mark">CC</span><span>Command Center<small>MEDIA BUYING</small></span></div>
    <?php foreach (nav_items() as $sec => $items):
        $vis = array_filter($items, fn($i) => $i[3] === null || can($i[3]));
        if (!$vis) continue;
        if ($sec !== '') echo '<h6>' . h($sec) . '</h6>';
        foreach ($vis as [$r, $label, $ic]): ?>
          <a href="<?= h(url($r)) ?>" class="<?= $route === $r ? 'on' : '' ?>"><?= icon($ic) ?><span><?= h($label) ?></span><?php if ($r === 'alerts' && $openAlerts): ?><span class="cnt"><?= $openAlerts ?></span><?php endif; ?></a>
    <?php endforeach; endforeach; ?>
  </aside>
  <div class="scrim"></div>
  <div class="main">
    <header class="top">
      <button class="iconbtn hamb" data-nav-toggle aria-label="القائمة"><?= icon('menu') ?></button>
      <h1><?= h($title) ?></h1>
      <span class="sp"></span>
      <?php if ($u): ?>
      <form method="post" action="<?= h(url('mode')) ?>" class="mode hide-m" title="بيانات حقيقية أو تجريبية"><?= csrf_field() ?>
        <button name="mode" value="live" class="<?= mode() === 'live' ? 'on' : '' ?>">Live</button><button name="mode" value="demo" class="<?= mode() === 'demo' ? 'on demo' : '' ?>">Demo</button>
      </form>
      <a class="iconbtn" href="<?= h(url('notifications')) ?>" title="الإشعارات"><?= icon('bell') ?><?= $unread ? '<span class="dot"></span>' : '' ?></a>
      <button class="iconbtn" data-theme-toggle title="المظهر"><?= icon('sun') ?></button>
      <form method="post" action="<?= h(url('logout')) ?>" class="hide-m"><?= csrf_field() ?><button class="iconbtn" title="خروج (<?= h($u['name']) ?>)"><?= icon('logout') ?></button></form>
      <?php endif; ?>
    </header>
    <main class="content">
    <?php foreach ($flashes as [$t, $m]): ?><div class="flash <?= h($t) ?>"><?= h($m) ?></div><?php endforeach;
}

function layout_end(): void {
    $route = $GLOBALS['CC_ROUTE'] ?? 'home';
    $openAlerts = current_user() ? (int)q_val("SELECT COUNT(*) FROM alerts WHERE status = 'open' AND client_id IN (" . in_list(scope_client_ids()) . ')') : 0; ?>
    </main>
    <div class="foot">Media Buying Command Center v<?= APP_VERSION ?> · <?= mode() === 'demo' ? 'DEMO' : 'LIVE' ?> · <?= h(current_user()['name'] ?? '') ?></div>
  </div>
</div>
<nav class="bnav" aria-label="تنقل سريع">
  <a href="<?= h(url('home')) ?>" class="<?= $route === 'home' ? 'on' : '' ?>"><?= icon('home') ?>الرئيسية</a>
  <?php if (can('analytics.view')): ?><a href="<?= h(url('dashboard')) ?>" class="<?= $route === 'dashboard' ? 'on' : '' ?>"><?= icon('chart') ?>الأداء</a><?php endif; ?>
  <?php if (can('crm.view')): ?><a href="<?= h(url('crm')) ?>" class="<?= $route === 'crm' ? 'on' : '' ?>"><?= icon('inbox') ?>الليدز</a><?php endif; ?>
  <?php if (can('alerts.view')): ?><a href="<?= h(url('alerts')) ?>" class="<?= $route === 'alerts' ? 'on' : '' ?>"><?= icon('bell') ?>التنبيهات<?= $openAlerts ? '<span class="cnt">' . $openAlerts . '</span>' : '' ?></a><?php endif; ?>
  <button data-nav-toggle><?= icon('more') ?>المزيد</button>
</nav>
<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
<?php }

function auth_page_start(string $title): void { ?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Command Center</title>
<script>try{var t=JSON.parse(localStorage.getItem('mbcc.theme')||'"auto"');if(t!=='auto')document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>"></head><body><div class="auth"><div class="card">
<div class="brand" style="padding:0 0 14px;color:var(--ink)"><span class="mark">CC</span><span>Media Buying Command Center</span></div>
<?php foreach (take_flashes() as [$t, $m]): ?><div class="flash <?= h($t) ?>"><?= h($m) ?></div><?php endforeach;
}
function auth_page_end(): void { echo '</div></div></body></html>'; }

/* ---------- reusable bits ---------- */
function kpi(string $label, string $valueHtml, ?float $change = null, bool $lowerIsBetter = false, string $tip = '', string $prevHtml = ''): string {
    $d = '';
    if ($change !== null) {
        $good = $lowerIsBetter ? $change < 0 : $change > 0;
        $cls = abs($change) < 0.005 ? 'flat' : ($good ? 'up' : 'down');
        $d = '<div class="d ' . $cls . '">' . ($change > 0 ? '▲' : ($change < 0 ? '▼' : '•')) . ' ' . number_format(abs($change) * 100, 1) . '% <span class="muted">عن الفترة السابقة' . ($prevHtml !== '' ? ' (' . $prevHtml . ')' : '') . '</span></div>';
    } elseif ($prevHtml !== '') $d = '<div class="d muted">السابقة: ' . $prevHtml . '</div>';
    return '<div class="kpi"><div class="l">' . h($label) . ($tip ? ' <span class="info" title="' . h($tip) . '">i</span>' : '') . '</div><div class="v">' . $valueHtml . '</div>' . $d . '</div>';
}
function empty_state(string $title, string $body = '', string $actionHtml = ''): string {
    return '<div class="empty"><b>' . h($title) . '</b>' . h($body) . ($actionHtml ? '<div style="margin-top:12px">' . $actionHtml . '</div>' : '') . '</div>';
}
function select_opts(array $opts, $sel, bool $blank = false, string $blankLabel = 'الكل'): string {
    $o = $blank ? '<option value="">' . h($blankLabel) . '</option>' : '';
    foreach ($opts as $v => $l) $o .= '<option value="' . h($v) . '"' . ((string)$v === (string)$sel ? ' selected' : '') . '>' . h($l) . '</option>';
    return $o;
}
function client_opts(): array { $o = []; foreach (scope_clients() as $c) $o[$c['id']] = $c['name']; return $o; }
function col_picker(string $tableId): string {
    return '<div class="colpick" data-cols-for="' . h($tableId) . '"><button class="btn sm ghost" type="button">' . icon('columns') . ' الأعمدة</button><div class="menu"></div></div>';
}
function sev_badge(string $s): string {
    return ['critical' => '<span class="badge b-bad">حرج</span>', 'warning' => '<span class="badge b-warn">تحذير</span>', 'info' => '<span class="badge b-info">معلومة</span>'][$s] ?? '<span class="badge">' . h($s) . '</span>';
}
function chart_canvas(array $spec, int $h = 0): string {
    return '<div class="chart"' . ($h ? ' style="height:' . $h . 'px"' : '') . '><canvas data-chart="' . h(json_encode($spec, JSON_UNESCAPED_UNICODE)) . '"></canvas></div>';
}
