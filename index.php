<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

$a = $_POST['a'] ?? null;
$p = $_GET['p'] ?? 'home';

/* ================= Layout ================= */
function top(string $title, bool $nav = true): void {
    $flashes = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
    $cur = $_GET['p'] ?? 'home'; ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f4f5f7;--card:#fff;--ink:#16181d;--mute:#6b7280;--line:#e5e7eb;--acc:#1b74e4;--acc2:#e8f1fd;--ok:#16a34a;--okbg:#e8f7ee;--bad:#dc2626;--badbg:#fdecec;--warn:#b45309;--warnbg:#fff4e0}
@media (prefers-color-scheme:dark){:root{--bg:#0f1115;--card:#181b21;--ink:#eceef2;--mute:#9aa1ad;--line:#2a2f38;--acc:#4b95f0;--acc2:#1a2a40;--ok:#4ade80;--okbg:#14301f;--bad:#f87171;--badbg:#3a1a1a;--warn:#fbbf24;--warnbg:#3a2c10}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Cairo,system-ui,Tahoma,sans-serif;font-size:15px;line-height:1.7}
a{color:var(--acc);text-decoration:none}
header{background:var(--card);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5}
.hwrap{max-width:1000px;margin:auto;padding:10px 16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.brand{font-weight:700;font-size:17px}.brand span{color:var(--acc)}
nav{display:flex;gap:4px;margin-inline-start:auto;flex-wrap:wrap}
nav a{padding:6px 12px;border-radius:8px;color:var(--mute);font-weight:600}nav a.on,nav a:hover{background:var(--acc2);color:var(--acc)}
main{max-width:1000px;margin:auto;padding:20px 16px 60px}
h1{font-size:22px;margin:0 0 14px}h2{font-size:17px;margin:0 0 10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px}
.muted{color:var(--mute);font-size:13.5px}
.btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:10px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer;background:var(--acc);color:#fff}
.btn.sec{background:var(--acc2);color:var(--acc)}.btn.red{background:var(--badbg);color:var(--bad)}.btn.sm{padding:5px 11px;font-size:13.5px}
.btn:disabled{opacity:.5}
input[type=text],input[type=password],textarea,select{width:100%;font:inherit;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:9px 12px}
textarea{min-height:110px;resize:vertical}
label.f{display:block;font-weight:600;margin:14px 0 6px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
.flash{padding:10px 14px;border-radius:10px;margin-bottom:12px;font-weight:600}.flash.ok{background:var(--okbg);color:var(--ok)}.flash.err{background:var(--badbg);color:var(--bad)}
.badge{display:inline-block;padding:1px 9px;border-radius:99px;font-size:12.5px;font-weight:700;background:var(--acc2);color:var(--acc)}
.badge.ok{background:var(--okbg);color:var(--ok)}.badge.bad{background:var(--badbg);color:var(--bad)}.badge.off{background:var(--line);color:var(--mute)}.badge.warn{background:var(--warnbg);color:var(--warn)}
.pg{display:flex;gap:12px;align-items:center}.pg img{width:46px;height:46px;border-radius:50%;object-fit:cover;background:var(--line)}
.steps{list-style:none;padding:0;margin:0}.steps li{padding:8px 0;border-bottom:1px dashed var(--line);display:flex;gap:10px;align-items:flex-start}.steps li:last-child{border:0}
.dot{flex:none;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:13px;font-weight:700;background:var(--line);color:var(--mute)}.dot.ok{background:var(--okbg);color:var(--ok)}
.copy{direction:ltr;text-align:left;font-family:ui-monospace,monospace;font-size:13px;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:6px 10px;word-break:break-all;cursor:pointer}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:right;padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--mute);font-weight:600;font-size:13px}
.tbl{overflow-x:auto}
.opt{border:1px solid var(--line);border-radius:10px;padding:10px 12px;display:flex;gap:10px;align-items:flex-start;cursor:pointer;margin-bottom:8px}.opt:has(input:checked){border-color:var(--acc);background:var(--acc2)}
.opt input{margin-top:6px}
details summary{cursor:pointer;font-weight:600;color:var(--acc)}
.rule{border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:10px}
.clip{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.stat{font-size:26px;font-weight:700}
</style>
</head>
<body>
<header><div class="hwrap"><div class="brand">🤖 Gano <span>Auto Reply</span></div>
<?php if ($nav): ?><nav>
<a href="?" class="<?= in_array($cur, ['home', 'page', 'rule'], true) ? 'on' : '' ?>">الصفحات</a>
<a href="?p=logs" class="<?= $cur === 'logs' ? 'on' : '' ?>">السجل</a>
<a href="?p=settings" class="<?= $cur === 'settings' ? 'on' : '' ?>">الإعدادات</a>
<form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="a" value="logout"><button class="btn sec sm" style="margin-inline-start:6px">خروج</button></form>
</nav><?php endif; ?>
</div></header>
<main>
<?php foreach ($flashes as [$t, $m]): ?><div class="flash <?= h($t) ?>"><?= h($m) ?></div><?php endforeach;
}

function bottom(): void { ?>
</main>
<script>
document.querySelectorAll('.copy').forEach(el=>el.addEventListener('click',()=>{navigator.clipboard&&navigator.clipboard.writeText(el.textContent.trim());const o=el.textContent;el.textContent='✔ اتنسخ';setTimeout(()=>el.textContent=o,900)}));
document.querySelectorAll('form[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!confirm(f.dataset.confirm))e.preventDefault()}));
</script>
</body></html>
<?php }

function status_badge(string $s): string {
    $map = ['sent' => ['ok', 'اتبعت'], 'failed' => ['bad', 'فشل'], 'skipped' => ['off', '—'], 'expired' => ['warn', 'عدّى 7 أيام'], 'pending' => ['warn', 'جاري']];
    [$c, $t] = $map[$s] ?? ['off', $s];
    return '<span class="badge ' . $c . '">' . $t . '</span>';
}

function fmt_time(?string $utc): string {
    if (!$utc) return '—';
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('Africa/Cairo'));
        return $d->format('Y-m-d H:i');
    } catch (Throwable $e) { return $utc; }
}

/* ================= First run ================= */
if (!setting('admin_hash')) {
    if ($a === 'setup') {
        check_csrf();
        $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');
        if (mb_strlen($pw) < 8) flash('الباسورد لازم يكون 8 حروف على الأقل', 'err');
        elseif ($pw !== $pw2) flash('الباسوردين مش زي بعض', 'err');
        else {
            set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
            set_setting('verify_token', bin2hex(random_bytes(16)));
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            flash('تمام ✅ دلوقتي حط بيانات تطبيق فيسبوك');
            redirect('?p=settings');
        }
    }
    top('تجهيز الأداة', false); ?>
    <div class="card" style="max-width:440px;margin:30px auto">
      <h1>أهلاً 👋 اعمل باسورد للوحة التحكم</h1>
      <p class="muted">الباسورد ده هتدخل بيه على الأداة بعد كده.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="setup">
        <label class="f">الباسورد</label><input type="password" name="password" required minlength="8" autocomplete="new-password">
        <label class="f">أكّد الباسورد</label><input type="password" name="password2" required minlength="8" autocomplete="new-password">
        <div style="margin-top:16px"><button class="btn">ابدأ</button></div>
      </form>
    </div>
    <?php bottom(); exit;
}

/* ================= Login ================= */
if (empty($_SESSION['auth'])) {
    if ($a === 'login') {
        check_csrf();
        $fails = (int)setting('login_fails', '0'); $last = (int)setting('login_last', '0');
        if ($fails >= 5 && time() - $last < 600) {
            flash('محاولات كتير غلط، استنى 10 دقايق', 'err');
        } elseif (password_verify((string)($_POST['password'] ?? ''), (string)setting('admin_hash'))) {
            set_setting('login_fails', '0');
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            redirect('?');
        } else {
            set_setting('login_fails', (string)($fails + 1)); set_setting('login_last', (string)time());
            flash('الباسورد غلط', 'err');
        }
        redirect('?');
    }
    top('دخول', false); ?>
    <div class="card" style="max-width:400px;margin:40px auto">
      <h1>تسجيل الدخول</h1>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="login">
        <label class="f">الباسورد</label><input type="password" name="password" required autofocus autocomplete="current-password">
        <div style="margin-top:16px"><button class="btn">دخول</button></div>
      </form>
    </div>
    <?php bottom(); exit;
}

/* ================= Actions ================= */
if ($a) {
    check_csrf();
    switch ($a) {
        case 'logout':
            $_SESSION = []; session_destroy(); redirect('?');

        case 'save_settings':
            set_setting('app_id', trim((string)($_POST['app_id'] ?? '')));
            if (trim((string)($_POST['app_secret'] ?? '')) !== '') set_setting('app_secret', trim((string)$_POST['app_secret']));
            $ver = trim((string)($_POST['graph_version'] ?? ''));
            if (preg_match('/^v\d+\.\d+$/', $ver)) set_setting('graph_version', $ver);
            if (setting('app_id') && setting('app_secret')) {
                $e = subscribe_app_webhook();
                if ($e) flash('اتحفظت الإعدادات، بس ربط الويب هوك فشل: ' . $e, 'err');
                else flash('اتحفظت الإعدادات واتربط الويب هوك ✅');
            } else flash('اتحفظت الإعدادات');
            redirect('?p=settings');

        case 'subscribe_app':
            $e = subscribe_app_webhook();
            $e ? flash('فشل ربط الويب هوك: ' . $e, 'err') : flash('اتربط الويب هوك ✅');
            redirect('?p=settings');

        case 'change_password':
            $pw = (string)($_POST['password'] ?? '');
            if (mb_strlen($pw) < 8) flash('الباسورد لازم 8 حروف على الأقل', 'err');
            else { set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT)); flash('اتغير الباسورد ✅'); }
            redirect('?p=settings');

        case 'import_token':
            $res = import_pages((string)($_POST['token'] ?? ''));
            isset($res['error']) ? flash('فشل: ' . $res['error'], 'err') : flash('تم ربط ' . $res['count'] . ' صفحة ✅');
            redirect('?');

        case 'page_toggle':
            $pg = get_page((string)($_POST['id'] ?? ''));
            if (!$pg) redirect('?');
            if ((int)$pg['active'] === 1) {
                db()->prepare('UPDATE pages SET active = 0 WHERE id = ?')->execute([$pg['id']]);
                graph('DELETE', $pg['id'] . '/subscribed_apps', [], $pg['token']);
                flash('اتوقف الرد التلقائي على ' . $pg['name']);
            } else {
                $r = graph('POST', $pg['id'] . '/subscribed_apps', ['subscribed_fields' => 'feed'], $pg['token']);
                if ($e = gerr($r)) flash('مقدرتش أفعّل الصفحة: ' . $e, 'err');
                else { db()->prepare('UPDATE pages SET active = 1 WHERE id = ?')->execute([$pg['id']]); flash('اتفعّل الرد التلقائي على ' . $pg['name'] . ' ✅'); }
            }
            redirect($_POST['back'] ?? '?');

        case 'rule_save':
            $pageId = (string)($_POST['page_id'] ?? '');
            $pg = get_page($pageId);
            if (!$pg) redirect('?');
            $id = (int)($_POST['id'] ?? 0);
            $scope = $_POST['scope'] ?? 'post';
            $postId = ''; $postLabel = '';
            if ($scope === 'post') {
                $manual = trim((string)($_POST['post_manual'] ?? ''));
                $postId = $manual !== '' ? $manual : trim((string)($_POST['post_id'] ?? ''));
                if (preg_match('/^\d+$/', $postId)) $postId = $pageId . '_' . $postId;
                $postLabel = $manual !== '' ? ('بوست ' . $postId) : trim((string)($_POST['post_label'] ?? ''));
                if ($postId === '') { flash('اختار البوست أو اكتب الـ ID بتاعه', 'err'); redirect('?p=rule&page=' . urlencode($pageId) . ($id ? '&id=' . $id : '')); }
            }
            $mode = ($_POST['match_mode'] ?? 'all') === 'keywords' ? 'keywords' : 'all';
            $kw = trim((string)($_POST['keywords'] ?? ''));
            $sc = isset($_POST['send_comment']) ? 1 : 0; $sd = isset($_POST['send_dm']) ? 1 : 0;
            $cr = trim((string)($_POST['comment_replies'] ?? '')); $dm = trim((string)($_POST['dm_messages'] ?? ''));
            $err = null;
            if ($mode === 'keywords' && !keywords_of($kw)) $err = 'اكتب كلمة واحدة على الأقل';
            elseif (!$sc && !$sd) $err = 'لازم تختار رد كومنت أو رسالة خاصة أو الاتنين';
            elseif ($sc && !variants($cr)) $err = 'اكتب نص الرد على الكومنت';
            elseif ($sd && !variants($dm)) $err = 'اكتب نص الرسالة الخاصة';
            if ($err) { $_SESSION['rule_draft'] = $_POST; flash($err, 'err'); redirect('?p=rule&page=' . urlencode($pageId) . ($id ? '&id=' . $id : '')); }
            $name = trim((string)($_POST['name'] ?? '')) ?: ($postLabel ?: 'كل البوستات');
            $vals = [$postId, $postLabel, $name, isset($_POST['active']) ? 1 : 0, $mode, $kw, $sc, $cr, $sd, $dm];
            if ($id) {
                db()->prepare('UPDATE rules SET post_id=?, post_label=?, name=?, active=?, match_mode=?, keywords=?, send_comment=?, comment_replies=?, send_dm=?, dm_messages=? WHERE id=? AND page_id=?')
                    ->execute(array_merge($vals, [$id, $pageId]));
            } else {
                db()->prepare('INSERT INTO rules (post_id, post_label, name, active, match_mode, keywords, send_comment, comment_replies, send_dm, dm_messages, page_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array_merge($vals, [$pageId, now()]));
            }
            flash('اتحفظت القاعدة ✅' . ((int)$pg['active'] !== 1 ? ' — متنساش تفعّل الصفحة' : ''));
            redirect('?p=page&id=' . urlencode($pageId));

        case 'rule_toggle':
            db()->prepare('UPDATE rules SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
            redirect($_POST['back'] ?? '?');

        case 'rule_delete':
            db()->prepare('DELETE FROM rules WHERE id = ?')->execute([(int)$_POST['id']]);
            flash('اتمسحت القاعدة');
            redirect($_POST['back'] ?? '?');

        case 'rule_backfill':
            $st = db()->prepare('SELECT * FROM rules WHERE id = ?'); $st->execute([(int)$_POST['id']]);
            $rule = $st->fetch();
            $pg = $rule ? get_page($rule['page_id']) : null;
            if (!$rule || !$pg || !$rule['post_id']) redirect('?');
            @set_time_limit(300);
            $stats = ['done' => 0, 'dup' => 0, 'nomatch' => 0, 'fail' => 0]; $after = null; $loops = 0;
            do {
                $params = ['filter' => 'toplevel', 'fields' => 'id,message,from{id,name},created_time', 'limit' => 50];
                if ($after) $params['after'] = $after;
                $res = graph('GET', $rule['post_id'] . '/comments', $params, $pg['token']);
                if ($e = gerr($res)) { flash('فشل جلب الكومنتات: ' . $e, 'err'); break; }
                foreach ($res['data'] ?? [] as $c) {
                    if (($c['from']['id'] ?? '') === $pg['id']) continue;
                    $out = process_comment($pg, $rule['post_id'], (string)$c['id'], (string)($c['from']['name'] ?? ''), (string)($c['message'] ?? ''), isset($c['created_time']) ? strtotime($c['created_time']) : null);
                    if ($out['status'] === 'duplicate') $stats['dup']++;
                    elseif ($out['status'] === 'no_rule') $stats['nomatch']++;
                    elseif (!empty($out['errors'])) $stats['fail']++;
                    else $stats['done']++;
                }
                $after = $res['paging']['cursors']['after'] ?? null;
                $next = isset($res['paging']['next']);
            } while ($next && $after && ++$loops < 6);
            flash("الكومنتات القديمة: اترد على {$stats['done']} — كان مترد عليها {$stats['dup']} — مش مطابقة {$stats['nomatch']} — فيها مشكلة {$stats['fail']}");
            redirect('?p=page&id=' . urlencode($rule['page_id']));
    }
    redirect('?');
}

/* ================= Views ================= */
$appReady = setting('app_id') && setting('app_secret');
$webhookOk = (bool)setting('webhook_ok');
$pages = db()->query('SELECT p.*, (SELECT COUNT(*) FROM rules r WHERE r.page_id = p.id) AS rc FROM pages p ORDER BY active DESC, name')->fetchAll();

switch ($p) {

/* ---------- Settings ---------- */
case 'settings':
    top('الإعدادات'); $base = base_url(); ?>
    <h1>الإعدادات</h1>
    <div class="card">
      <h2>بيانات تطبيق فيسبوك (Meta App)</h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="save_settings">
        <label class="f">App ID</label><input type="text" name="app_id" value="<?= h(setting('app_id', '')) ?>" dir="ltr" inputmode="numeric">
        <label class="f">App Secret</label><input type="password" name="app_secret" placeholder="<?= setting('app_secret') ? '•••••••• (محفوظ — سيبه فاضي لو مش هتغيره)' : '' ?>" dir="ltr" autocomplete="off">
        <label class="f">Graph API Version</label><input type="text" name="graph_version" value="<?= h(graph_version()) ?>" dir="ltr" style="max-width:160px">
        <div style="margin-top:16px"><button class="btn">حفظ وربط الويب هوك</button></div>
      </form>
    </div>
    <div class="card">
      <h2>روابط هتحتاجها في إعدادات التطبيق على Meta</h2>
      <p class="muted">اضغط على أي رابط عشان تنسخه.</p>
      <label class="f">Webhook Callback URL</label><div class="copy"><?= h($base . '/webhook.php') ?></div>
      <label class="f">Verify Token</label><div class="copy"><?= h(setting('verify_token', '')) ?></div>
      <label class="f">Valid OAuth Redirect URI</label><div class="copy"><?= h($base . '/oauth.php') ?></div>
      <label class="f">Privacy Policy URL</label><div class="copy"><?= h($base . '/privacy.php') ?></div>
      <div class="row" style="margin-top:14px">
        <span>حالة الويب هوك: <?= $webhookOk ? '<span class="badge ok">متربط · ' . h(fmt_time(setting('webhook_ok'))) . '</span>' : '<span class="badge off">لسه</span>' ?></span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="subscribe_app"><button class="btn sec sm" <?= $appReady ? '' : 'disabled' ?>>إعادة الربط</button></form>
      </div>
      <p class="muted" style="margin-top:8px">آخر إشارة وصلت من فيسبوك: <?= h(fmt_time(setting('last_webhook'))) ?></p>
    </div>
    <div class="card">
      <h2>تغيير باسورد اللوحة</h2>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="change_password">
        <input type="password" name="password" minlength="8" required placeholder="الباسورد الجديد" style="max-width:280px" autocomplete="new-password"><button class="btn sec">تغيير</button>
      </form>
    </div>
    <?php bottom(); break;

/* ---------- Logs ---------- */
case 'logs':
    top('السجل');
    $names = array_column($pages, 'name', 'id');
    $rows = db()->query('SELECT * FROM log ORDER BY created_at DESC LIMIT 300')->fetchAll(); ?>
    <h1>سجل الردود</h1>
    <div class="card tbl">
      <?php if (!$rows): ?><p class="muted">لسه مفيش ردود. أول ما حد يعلّق على بوست عليه قاعدة هيظهر هنا.</p>
      <?php else: ?>
      <table><tr><th>الوقت</th><th>الصفحة</th><th>صاحب الكومنت</th><th>الكومنت</th><th>رد الكومنت</th><th>الرسالة الخاصة</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr><td style="white-space:nowrap"><?= h(fmt_time($r['created_at'])) ?></td><td><?= h($names[$r['page_id']] ?? $r['page_id']) ?></td><td><?= h($r['from_name'] ?: '—') ?></td>
        <td><div class="clip"><?= h($r['message']) ?></div><?php if ($r['error']): ?><div class="muted" style="color:var(--bad)"><?= h($r['error']) ?></div><?php endif; ?></td>
        <td><?= status_badge($r['comment_status']) ?></td><td><?= status_badge($r['dm_status']) ?></td></tr>
      <?php endforeach; ?></table>
      <?php endif; ?>
    </div>
    <?php bottom(); break;

/* ---------- One page: rules ---------- */
case 'page':
    $pg = get_page((string)($_GET['id'] ?? ''));
    if (!$pg) redirect('?');
    $st = db()->prepare('SELECT * FROM rules WHERE page_id = ? ORDER BY (post_id = \'\'), id DESC'); $st->execute([$pg['id']]);
    $rules = $st->fetchAll();
    $st = db()->prepare('SELECT * FROM log WHERE page_id = ? ORDER BY created_at DESC LIMIT 15'); $st->execute([$pg['id']]);
    $logs = $st->fetchAll();
    $back = '?p=page&id=' . urlencode($pg['id']);
    top($pg['name']); ?>
    <div class="row" style="justify-content:space-between;margin-bottom:14px">
      <div class="pg"><img src="<?= h($pg['picture']) ?>" alt=""><div><h1 style="margin:0"><?= h($pg['name']) ?></h1>
        <?= (int)$pg['active'] === 1 ? '<span class="badge ok">الرد التلقائي شغال</span>' : '<span class="badge off">الرد التلقائي واقف</span>' ?></div></div>
      <div class="row">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="page_toggle"><input type="hidden" name="id" value="<?= h($pg['id']) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <button class="btn <?= (int)$pg['active'] === 1 ? 'red' : '' ?>"><?= (int)$pg['active'] === 1 ? 'إيقاف الصفحة' : 'تفعيل الصفحة' ?></button></form>
        <a class="btn sec" href="?p=rule&page=<?= urlencode($pg['id']) ?>">＋ قاعدة رد جديدة</a>
      </div>
    </div>
    <div class="card">
      <h2>قواعد الرد</h2>
      <p class="muted">الأولوية: قاعدة البوست نفسه ← بعدين قاعدة "كل البوستات". وجوه كل نوع، قواعد الكلمات المفتاحية قبل قاعدة "أي كومنت".</p>
      <?php if (!$rules): ?><p>مفيش قواعد لسه. <a href="?p=rule&page=<?= urlencode($pg['id']) ?>">اعمل أول قاعدة</a></p><?php endif; ?>
      <?php foreach ($rules as $r): ?>
        <div class="rule">
          <div class="row" style="justify-content:space-between">
            <div><strong><?= h($r['name']) ?></strong>
              <?= (int)$r['active'] === 1 ? '<span class="badge ok">شغالة</span>' : '<span class="badge off">واقفة</span>' ?></div>
            <div class="row">
              <a class="btn sec sm" href="?p=rule&page=<?= urlencode($pg['id']) ?>&id=<?= (int)$r['id'] ?>">تعديل</a>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="rule_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="btn sec sm"><?= (int)$r['active'] === 1 ? 'إيقاف' : 'تشغيل' ?></button></form>
              <?php if ($r['post_id']): ?>
              <form method="post" data-confirm="هيرد على الكومنتات القديمة اللي على البوست ده (لحد 300 كومنت). الرسالة الخاصة بتتبعت بس للكومنتات اللي عمرها أقل من 7 أيام. نكمل؟"><?= csrf_field() ?><input type="hidden" name="a" value="rule_backfill"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sec sm">رد على القديم</button></form>
              <?php endif; ?>
              <form method="post" data-confirm="تمسح القاعدة دي؟"><?= csrf_field() ?><input type="hidden" name="a" value="rule_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="btn red sm">مسح</button></form>
            </div>
          </div>
          <div class="muted" style="margin-top:6px">
            📍 <?= $r['post_id'] ? 'بوست: ' . h($r['post_label'] ?: $r['post_id']) : 'كل البوستات' ?> ·
            🔎 <?= $r['match_mode'] === 'keywords' ? 'كلمات: ' . h(implode('، ', keywords_of($r['keywords']))) : 'أي كومنت' ?> ·
            <?= (int)$r['send_comment'] === 1 ? '💬 رد كومنت (' . count(variants($r['comment_replies'])) . ' صيغة)' : '' ?>
            <?= (int)$r['send_dm'] === 1 ? ' ✉️ رسالة خاصة (' . count(variants($r['dm_messages'])) . ' صيغة)' : '' ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card tbl">
      <h2>آخر الردود على الصفحة</h2>
      <?php if (!$logs): ?><p class="muted">لسه مفيش.</p><?php else: ?>
      <table><tr><th>الوقت</th><th>صاحب الكومنت</th><th>الكومنت</th><th>كومنت</th><th>رسالة</th></tr>
      <?php foreach ($logs as $l): ?><tr><td style="white-space:nowrap"><?= h(fmt_time($l['created_at'])) ?></td><td><?= h($l['from_name'] ?: '—') ?></td><td><div class="clip"><?= h($l['message']) ?></div><?php if ($l['error']): ?><div class="muted" style="color:var(--bad)"><?= h($l['error']) ?></div><?php endif; ?></td><td><?= status_badge($l['comment_status']) ?></td><td><?= status_badge($l['dm_status']) ?></td></tr><?php endforeach; ?>
      </table><?php endif; ?>
    </div>
    <?php bottom(); break;

/* ---------- Rule editor ---------- */
case 'rule':
    $pg = get_page((string)($_GET['page'] ?? ''));
    if (!$pg) redirect('?');
    $id = (int)($_GET['id'] ?? 0);
    $r = ['id' => 0, 'name' => '', 'post_id' => '', 'post_label' => '', 'active' => 1, 'match_mode' => 'all', 'keywords' => '', 'send_comment' => 1, 'comment_replies' => '', 'send_dm' => 1, 'dm_messages' => ''];
    if ($id) { $st = db()->prepare('SELECT * FROM rules WHERE id = ? AND page_id = ?'); $st->execute([$id, $pg['id']]); $r = $st->fetch() ?: $r; }
    $draft = $_SESSION['rule_draft'] ?? null; unset($_SESSION['rule_draft']);
    if ($draft) {
        foreach (['name', 'match_mode', 'keywords', 'comment_replies', 'dm_messages', 'post_label'] as $k) $r[$k] = $draft[$k] ?? $r[$k];
        $r['post_id'] = ($draft['scope'] ?? 'post') === 'post' ? (($draft['post_manual'] ?? '') ?: ($draft['post_id'] ?? '')) : '';
        $r['send_comment'] = isset($draft['send_comment']) ? 1 : 0; $r['send_dm'] = isset($draft['send_dm']) ? 1 : 0; $r['active'] = isset($draft['active']) ? 1 : 0;
    }
    $scopeAll = $id && !$r['post_id'];

    // Fetch recent posts (organic + ad posts)
    $posts = []; $postErr = null;
    $fields = 'id,message,created_time,full_picture';
    $res = graph('GET', $pg['id'] . '/posts', ['fields' => $fields, 'limit' => 30], $pg['token']);
    if ($e = gerr($res)) $postErr = $e; else foreach ($res['data'] ?? [] as $x) $posts[$x['id']] = $x + ['ad' => false];
    $res2 = graph('GET', $pg['id'] . '/ads_posts', ['fields' => $fields, 'limit' => 30, 'include_inline_create' => 'true'], $pg['token']);
    if (!gerr($res2)) foreach ($res2['data'] ?? [] as $x) if (!isset($posts[$x['id']])) $posts[$x['id']] = $x + ['ad' => true];
    uasort($posts, fn($a, $b) => strcmp($b['created_time'] ?? '', $a['created_time'] ?? ''));
    $inList = isset($posts[$r['post_id']]);

    top($id ? 'تعديل قاعدة' : 'قاعدة جديدة'); ?>
    <p><a href="?p=page&id=<?= urlencode($pg['id']) ?>">→ <?= h($pg['name']) ?></a></p>
    <h1><?= $id ? 'تعديل قاعدة رد' : 'قاعدة رد جديدة' ?></h1>
    <form method="post" id="rf"><?= csrf_field() ?>
      <input type="hidden" name="a" value="rule_save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="page_id" value="<?= h($pg['id']) ?>">
      <input type="hidden" name="post_label" id="post_label" value="<?= h($r['post_label']) ?>">

      <div class="card">
        <h2>١. على أنهي بوست؟</h2>
        <label class="opt"><input type="radio" name="scope" value="post" <?= $scopeAll ? '' : 'checked' ?>><div><strong>بوست معيّن</strong><div class="muted">رد مخصوص للبوست ده بس</div></div></label>
        <label class="opt"><input type="radio" name="scope" value="all" <?= $scopeAll ? 'checked' : '' ?>><div><strong>كل بوستات الصفحة</strong><div class="muted">رد عام لأي بوست ملوش قاعدة خاصة</div></div></label>
        <div id="postbox">
          <label class="f">اختار البوست</label>
          <?php if ($postErr): ?><p class="muted" style="color:var(--bad)">مقدرتش أجيب البوستات: <?= h($postErr) ?></p><?php endif; ?>
          <select name="post_id" id="post_sel">
            <option value="">— اختار —</option>
            <?php foreach ($posts as $pid => $x):
                $lbl = trim(preg_replace('/\s+/u', ' ', (string)($x['message'] ?? ''))); $lbl = $lbl !== '' ? mb_substr($lbl, 0, 70) : 'بوست بدون نص';
                $lbl = ($x['ad'] ? '[إعلان] ' : '') . $lbl . ' · ' . substr((string)($x['created_time'] ?? ''), 0, 10); ?>
              <option value="<?= h($pid) ?>" data-label="<?= h($lbl) ?>" <?= $r['post_id'] === $pid ? 'selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <details style="margin-top:10px" <?= ($r['post_id'] && !$inList) ? 'open' : '' ?>><summary>البوست مش موجود في القايمة؟ اكتب الـ ID</summary>
            <input type="text" name="post_manual" dir="ltr" placeholder="مثال: 1234567890_9876543210" value="<?= h(($r['post_id'] && !$inList) ? $r['post_id'] : '') ?>" style="margin-top:8px">
            <p class="muted">لبوستات الإعلانات: ده الـ Post ID بتاع الإعلان (Effective Object Story ID).</p>
          </details>
        </div>
      </div>

      <div class="card">
        <h2>٢. يرد على أنهي كومنتات؟</h2>
        <label class="opt"><input type="radio" name="match_mode" value="all" <?= $r['match_mode'] !== 'keywords' ? 'checked' : '' ?>><div><strong>أي كومنت</strong></div></label>
        <label class="opt"><input type="radio" name="match_mode" value="keywords" <?= $r['match_mode'] === 'keywords' ? 'checked' : '' ?>><div><strong>الكومنتات اللي فيها كلمات معينة بس</strong></div></label>
        <div id="kwbox"><label class="f">الكلمات (افصل بينهم بفاصلة)</label>
          <input type="text" name="keywords" value="<?= h($r['keywords']) ?>" placeholder="السعر، بكام، تفاصيل، متاح">
          <p class="muted">مش بيفرق بين أ/ا، ة/ه، ى/ي والتشكيل.</p></div>
      </div>

      <div class="card">
        <h2>٣. الرد</h2>
        <label class="opt"><input type="checkbox" name="send_comment" <?= (int)$r['send_comment'] === 1 ? 'checked' : '' ?>><div><strong>💬 رد على الكومنت (علني)</strong></div></label>
        <textarea name="comment_replies" placeholder="تم إرسال التفاصيل في الرسائل يا {name} ❤️&#10;---&#10;بعتنالك كل التفاصيل على الخاص 🙏"><?= h($r['comment_replies']) ?></textarea>
        <label class="opt" style="margin-top:14px"><input type="checkbox" name="send_dm" <?= (int)$r['send_dm'] === 1 ? 'checked' : '' ?>><div><strong>✉️ رسالة خاصة على الماسنجر</strong><div class="muted">بتتبعت مرة واحدة لكل كومنت، وفي خلال 7 أيام من الكومنت</div></div></label>
        <textarea name="dm_messages" placeholder="أهلاً {name} 👋&#10;السعر ... والتوصيل ...&#10;للحجز ابعت رقم موبايلك"><?= h($r['dm_messages']) ?></textarea>
        <p class="muted">💡 تقدر تكتب أكتر من صيغة وتفصل بينهم بسطر فيه <code>---</code> لوحده، والأداة هتختار واحدة عشوائي كل مرة عشان فيسبوك ما يعتبرهاش سبام. واكتب <code>{name}</code> عشان يتحط اسم صاحب الكومنت.</p>
      </div>

      <div class="card">
        <label class="f" style="margin-top:0">اسم القاعدة (اختياري)</label><input type="text" name="name" value="<?= h($r['name']) ?>" placeholder="مثلاً: عرض الشقق — بوست الأسعار">
        <label class="opt" style="margin-top:12px"><input type="checkbox" name="active" <?= (int)$r['active'] === 1 ? 'checked' : '' ?>><div><strong>القاعدة شغالة</strong></div></label>
        <div style="margin-top:10px"><button class="btn">حفظ القاعدة</button></div>
      </div>
    </form>
    <script>
    const f=document.getElementById('rf');
    function sync(){f.querySelector('#postbox').style.display=f.scope.value==='post'?'':'none';f.querySelector('#kwbox').style.display=f.match_mode.value==='keywords'?'':'none'}
    f.addEventListener('change',sync);sync();
    const s=document.getElementById('post_sel');s.addEventListener('change',()=>{const o=s.selectedOptions[0];document.getElementById('post_label').value=o?o.dataset.label||'':''});
    </script>
    <?php bottom(); break;

/* ---------- Home ---------- */
default:
    top('الصفحات');
    $hasPages = count($pages) > 0; $hasActive = (bool)array_filter($pages, fn($x) => (int)$x['active'] === 1);
    $today = (int)db()->query("SELECT COUNT(*) FROM log WHERE created_at >= datetime('now','-1 day')")->fetchColumn();
    $total = (int)db()->query('SELECT COUNT(*) FROM log')->fetchColumn(); ?>
    <?php if (!($appReady && $webhookOk && $hasPages && $hasActive)): ?>
    <div class="card">
      <h2>خطوات التشغيل</h2>
      <ul class="steps">
        <li><span class="dot <?= $appReady ? 'ok' : '' ?>"><?= $appReady ? '✓' : '1' ?></span><div>حط بيانات تطبيق فيسبوك — <a href="?p=settings">الإعدادات</a></div></li>
        <li><span class="dot <?= $webhookOk ? 'ok' : '' ?>"><?= $webhookOk ? '✓' : '2' ?></span><div>ربط الويب هوك (بيحصل لوحده لما تحفظ الإعدادات)</div></li>
        <li><span class="dot <?= $hasPages ? 'ok' : '' ?>"><?= $hasPages ? '✓' : '3' ?></span><div>اربط صفحاتك من الزرار اللي تحت</div></li>
        <li><span class="dot <?= $hasActive ? 'ok' : '' ?>"><?= $hasActive ? '✓' : '4' ?></span><div>ادخل على الصفحة، اعمل قاعدة رد، وفعّل الصفحة</div></li>
      </ul>
    </div>
    <?php else: ?>
    <div class="grid" style="margin-bottom:16px">
      <div class="card" style="margin:0"><div class="muted">ردود آخر 24 ساعة</div><div class="stat"><?= $today ?></div></div>
      <div class="card" style="margin:0"><div class="muted">إجمالي الردود</div><div class="stat"><?= $total ?></div></div>
    </div>
    <?php endif; ?>

    <div class="row" style="justify-content:space-between;margin-bottom:12px">
      <h1 style="margin:0">صفحاتك</h1>
      <a class="btn <?= $appReady ? '' : 'sec' ?>" href="<?= $appReady ? 'oauth.php' : '?p=settings' ?>">🔗 <?= $hasPages ? 'تحديث / إضافة صفحات' : 'ربط صفحات فيسبوك' ?></a>
    </div>
    <details class="card"><summary>ربط بتوكن (بديل لو زرار الربط مش شغال)</summary>
      <p class="muted">هات User Access Token من Graph API Explorer بالصلاحيات: pages_show_list, pages_read_engagement, pages_read_user_content, pages_manage_engagement, pages_manage_metadata, pages_messaging, business_management</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="import_token">
        <textarea name="token" dir="ltr" style="min-height:70px" required placeholder="EAAB..."></textarea>
        <div style="margin-top:10px"><button class="btn sec">ربط</button></div></form>
    </details>

    <?php if (!$hasPages): ?><div class="card"><p class="muted">مفيش صفحات متربطة لسه.</p></div><?php endif; ?>
    <div class="grid">
    <?php foreach ($pages as $pg): ?>
      <div class="card" style="margin:0">
        <div class="pg"><img src="<?= h($pg['picture']) ?>" alt="" loading="lazy"><div style="min-width:0">
          <strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($pg['name']) ?></strong>
          <?= (int)$pg['active'] === 1 ? '<span class="badge ok">شغال</span>' : '<span class="badge off">واقف</span>' ?> <span class="muted"><?= (int)$pg['rc'] ?> قاعدة</span></div></div>
        <div class="row" style="margin-top:12px">
          <a class="btn sm" href="?p=page&id=<?= urlencode($pg['id']) ?>">إدارة الردود</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="page_toggle"><input type="hidden" name="id" value="<?= h($pg['id']) ?>"><button class="btn sm <?= (int)$pg['active'] === 1 ? 'red' : 'sec' ?>"><?= (int)$pg['active'] === 1 ? 'إيقاف' : 'تفعيل' ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php bottom();
}
