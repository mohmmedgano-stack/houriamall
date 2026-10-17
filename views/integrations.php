<?php
if (is_post()) {
    $a = in_str('a');
    switch ($a) {
        case 'meta_app':
            $patch = ['app_id' => preg_replace('/\D/', '', in_str('app_id')), 'login_config_id' => preg_replace('/\D/', '', in_str('login_config_id')) ?: null];
            if (in_str('app_secret') !== '') $patch['app_secret'] = in_str('app_secret');
            if (preg_match('/^v\d+\.\d+$/', in_str('graph_version'))) $patch['graph_version'] = in_str('graph_version');
            meta_save_config($patch); audit('meta.app_config'); flash('اتحفظت بيانات تطبيق Meta ✅'); break;
        case 'meta_token':
            $tok = in_str('token');
            if (strlen($tok) < 20) throw new InvalidArgumentException('التوكن قصير — انسخه كامل');
            $cfg = meta_config();
            $patch = ['access_token' => $tok, 'token_expires_at' => null, 'token_type' => in_str('token_type') === 'system' ? 'system_user' : 'user'];
            if ($patch['token_type'] === 'user' && !empty($cfg['app_id']) && !empty($cfg['app_secret'])) {
                try { $patch = array_merge($patch, meta_exchange_long_lived($tok)); } catch (MetaError $e) { flash('مقدرتش أحوّله لتوكن طويل الأجل: ' . $e->getMessage(), 'warn'); }
            }
            meta_save_config($patch, 'testing');
            $t = meta_test_connection();
            audit('meta.token_saved');
            $t['ok'] ? flash('اتربط Meta ✅ باسم ' . $t['name']) : flash('التوكن اتحفظ بس الاختبار فشل: ' . ($t['error'] ?? 'صلاحيات ناقصة: ' . implode(', ', $t['missing'] ?? [])), 'err');
            break;
        case 'meta_test': $t = meta_test_connection(); $t['ok'] ? flash('الاتصال شغال ✅ (' . $t['name'] . ')') : flash('الاتصال فشل: ' . ($t['error'] ?? 'صلاحيات ناقصة'), 'err'); break;
        case 'meta_disconnect': meta_disconnect(); flash('اتفصل الربط مع Meta. البيانات القديمة لسه موجودة، والمزامنة وقفت.'); break;
        case 'meta_link':
            $acct = json_decode((string)($_POST['acct'] ?? ''), true);
            if (!is_array($acct) || empty($acct['id'])) throw new InvalidArgumentException('اختار حساب');
            $id = meta_link_account((int)in_int('client_id'), $acct);
            flash('اتربط الحساب ✅ أول مزامنة هتسحب آخر 30 يوم في الخلفية خلال دقايق، أو دوس "مزامنة الآن".'); break;
        case 'meta_sync':
            $aid = (int)in_int('account_id');
            if (!can_client((int)account_client_id($aid))) throw new Forbidden('account');
            @set_time_limit(300);
            try { $s = meta_sync_account($aid, (new DateTimeImmutable(today()))->modify('-' . max(1, min(90, (int)in_int('days', 30))) . ' days')->format('Y-m-d'), today());
                flash("اتزامن ✅ {$s['campaigns']} حملة، {$s['ads']} إعلان، {$s['rows']} صف بيانات، {$s['breakdown_rows']} صف تقسيم جمهور"); }
            catch (MetaError $e) { flash('المزامنة فشلت: ' . $e->getMessage(), 'err'); }
            break;
        case 'meta_leads':
            $aid = (int)in_int('account_id');
            if (!can_client((int)account_client_id($aid))) throw new Forbidden('account');
            try { $s = meta_sync_leads($aid, time() - 30 * 86400); flash("اتسحبت الليدز ✅ {$s['new']} جديد، {$s['duplicates']} مكرر، {$s['existing']} موجود قبل كده"); }
            catch (MetaError $e) { flash('سحب الليدز فشل: ' . $e->getMessage(), 'err'); }
            break;
        case 'ai':
            ai_save_config(['provider' => in_str('provider'), 'model' => in_str('model'), 'api_key' => in_str('api_key'), 'base_url' => in_str('base_url') ?: null,
                'monthly_calls' => in_int('monthly_calls', 500), 'enabled' => in_str('enabled') === '1']);
            flash('اتحفظت إعدادات الـ AI ✅'); break;
        case 'ai_test':
            [$t, $e] = ai_complete('test', 'Reply with exactly: OK', 'ping', 10);
            $e ? flash('اختبار الـ AI فشل: ' . $e, 'err') : flash('الـ AI شغال ✅ (' . mb_substr($t, 0, 20) . ')'); break;
        case 'mail':
            $m = in_str('mail_from');
            if ($m !== '' && !filter_var($m, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('إيميل غير صحيح');
            set_setting('mail_from', $m ?: null); flash('اتحفظ ✅'); break;
    }
    redirect(url('integrations'));
}
$mi = meta_integration(); $mc = meta_config();
$ac = ai_config();
$base = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
$redirectUri = $base . '/?r=oauth';
$accounts = null; $accErr = null;
if (in_str('list') === '1' && !empty($mc['access_token'])) { try { $accounts = meta_ad_accounts(); } catch (MetaError $e) { $accErr = $e->getMessage(); } }
$linked = q_all("SELECT a.*, c.name cname FROM ad_accounts a JOIN clients c ON c.id = a.client_id WHERE a.platform = 'meta' AND a.external_id IS NOT NULL AND a.client_id IN (" . in_list(scope_client_ids()) . ') ORDER BY c.name');
$statusBadge = fn($s) => ['connected' => '<span class="badge b-ok">متصل ومختبر</span>', 'error' => '<span class="badge b-bad">فيه مشكلة</span>', 'expired' => '<span class="badge b-bad">الصلاحية انتهت</span>',
    'testing' => '<span class="badge b-warn">جاري الاختبار</span>', 'disconnected' => '<span class="badge">مفصول</span>', 'configured' => '<span class="badge b-info">متظبط</span>'][$s] ?? '<span class="badge">مش متظبط</span>';
layout_start('التكاملات'); ?>
<div class="grid g2">
<div class="stack">
<div class="card"><h2>Meta Marketing API <?= $statusBadge($mi['status'] ?? '') ?></h2>
  <?php if ($mi && $mi['last_error']): ?><div class="flash err small"><?= h($mi['last_error']) ?></div><?php endif; ?>
  <?php if (!empty($mc['access_token'])): ?>
    <p class="small">متصل باسم <b><?= h($mc['user_name'] ?? '—') ?></b> · توكن <?= ($mc['token_type'] ?? '') === 'system_user' ? 'System User' : 'مستخدم' ?> <span class="ltr"><?= h(mask_secret($mc['access_token'])) ?></span>
      <?= !empty($mc['token_expires_at']) ? ' · بينتهي ' . h(fmt_dt($mc['token_expires_at'], 'Y-m-d')) : '' ?><br>آخر اختبار ناجح: <?= h(fmt_dt($mi['last_ok_at'] ?? null)) ?><br>
      الصلاحيات: <span class="ltr small"><?= h($mc['scopes'] ?? '—') ?></span></p>
    <?php if (!str_contains((string)($mc['scopes'] ?? ''), 'leads_retrieval')): ?><p class="hint">سحب الليدز من Lead Ads محتاج صلاحية <code>leads_retrieval</code>.</p><?php endif; ?>
    <div class="row"><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="meta_test"><button class="btn sm ghost">اختبار الاتصال</button></form>
      <a class="btn sm" href="<?= h(url('integrations', ['list' => 1])) ?>#accs">عرض الحسابات الإعلانية</a>
      <form method="post" data-confirm="هتوقف المزامنة وتمسح التوكن. البيانات القديمة هتفضل. متأكد؟"><?= csrf_field() ?><input type="hidden" name="a" value="meta_disconnect"><button class="btn sm ghost">فصل الربط</button></form></div>
  <?php else: ?>
    <p class="muted small">لسه مفيش ربط. لحد ما تربط، استخدم الاستيراد من CSV/Excel — كل الأدوات بتشتغل بيه.</p>
  <?php endif; ?>
  <details class="box" style="margin-top:12px" <?= empty($mc['app_id']) ? 'open' : '' ?>><summary>1) بيانات تطبيق Meta</summary>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="meta_app">
      <div class="fgrid"><div><label class="f">App ID</label><input type="text" name="app_id" dir="ltr" value="<?= h($mc['app_id'] ?? '') ?>"></div>
        <div><label class="f">App Secret</label><input type="password" name="app_secret" dir="ltr" autocomplete="off" placeholder="<?= !empty($mc['app_secret']) ? 'محفوظ ' . h(mask_secret($mc['app_secret'])) . ' — سيبه فاضي' : '' ?>"></div>
        <div><label class="f">Login Configuration ID (اختياري)</label><input type="text" name="login_config_id" dir="ltr" value="<?= h($mc['login_config_id'] ?? '') ?>"></div>
        <div><label class="f">Graph API version</label><input type="text" name="graph_version" dir="ltr" value="<?= h($mc['graph_version'] ?? 'v23.0') ?>"></div></div>
      <div class="hint">Redirect URI اللي تحطه في Facebook Login: <code class="ltr"><?= h($redirectUri) ?></code></div>
      <button class="btn sm" style="margin-top:10px">حفظ</button></form></details>
  <details class="box" style="margin-top:8px"><summary>2) اربط بحساب فيسبوك</summary>
    <?php if (!empty($mc['app_id']) && !empty($mc['app_secret'])): ?><a class="btn" style="margin:10px 0" href="<?= h(url('oauth', ['start' => 1])) ?>">تسجيل الدخول بفيسبوك</a><?php else: ?><p class="muted small">حط App ID و App Secret الأول.</p><?php endif; ?>
    <p class="small muted">أو الصق توكن (الأفضل: System User Token من Business Manager لأنه مش بينتهي):</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="meta_token">
      <textarea name="token" dir="ltr" style="min-height:60px" placeholder="EAAB..." autocomplete="off"></textarea>
      <select name="token_type" style="margin-top:6px"><option value="system">System User Token</option><option value="user">User Token (هيتحول لطويل الأجل)</option></select>
      <button class="btn sm" style="margin-top:8px">حفظ واختبار</button></form></details>
  <details class="box" style="margin-top:8px"><summary>إزاي أجهز تطبيق Meta</summary><ol class="small" style="padding-inline-start:18px">
    <li>من developers.facebook.com اعمل App نوعه <b>Business</b> (أو استخدم تطبيقك الحالي وضيفله Marketing API).</li>
    <li>ضيف المنتجات: <b>Marketing API</b> و <b>Facebook Login for Business</b>.</li>
    <li>الصلاحيات: <code>ads_read</code>, <code>read_insights</code>, <code>leads_retrieval</code>, <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_ads</code>, <code>business_management</code>.</li>
    <li>في Facebook Login حط الـ Redirect URI اللي فوق، وفي App Settings حط الدومين وسياسة الخصوصية.</li>
    <li>لحساباتك وحسابات عملاءك اللي انت Admin عليها، الوصول العادي (Standard Access) كفاية. لو هتربط حسابات ناس تانية محتاج Advanced Access ومراجعة التطبيق.</li>
    <li>أسهل طريقة ثابتة: Business Settings ← System Users ← اعمل System User واديله صلاحية على الحسابات الإعلانية والصفحات، واعمل Generate Token بالصلاحيات اللي فوق والصقه هنا.</li></ol></details>
</div>
<div class="card" id="accs"><h2>الحسابات المربوطة بالمزامنة</h2>
  <?php if (!$linked) echo '<p class="muted small">مفيش حسابات Meta مربوطة.</p>'; ?>
  <?php foreach ($linked as $l): ?><div style="padding:7px 0;border-bottom:1px solid var(--line)"><div class="row small"><span class="status-dot <?= $l['last_sync_error'] ? 'bad' : ($l['last_sync_at'] ? 'ok' : 'warn') ?>"></span><b><?= h($l['name']) ?></b><span class="muted ltr">act_<?= h($l['external_id']) ?> · <?= h($l['currency']) ?></span><span class="muted"><?= h($l['cname']) ?></span><span class="sp"></span>
    <span class="muted"><?= $l['sync_enabled'] ? 'آخر مزامنة ' . h(fmt_dt($l['last_sync_at'])) : 'المزامنة متوقفة' ?></span></div>
    <?php if ($l['last_sync_error']): ?><div class="small" style="color:var(--bad)"><?= h($l['last_sync_error']) ?></div><?php endif; ?>
    <?php if (!empty($mc['access_token'])): ?><div class="row" style="margin-top:4px"><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="meta_sync"><input type="hidden" name="account_id" value="<?= (int)$l['id'] ?>"><select name="days" style="width:110px"><option value="3">3 أيام</option><option value="30" selected>30 يوم</option><option value="90">90 يوم</option></select><button class="btn sm ghost">مزامنة الآن</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="meta_leads"><input type="hidden" name="account_id" value="<?= (int)$l['id'] ?>"><button class="btn sm ghost">سحب الليدز</button></form></div><?php endif; ?></div><?php endforeach; ?>
  <?php if ($accErr): ?><div class="flash err" style="margin-top:10px"><?= h($accErr) ?></div><?php endif; ?>
  <?php if ($accounts !== null): ?><h3 style="font-size:14px;margin-top:16px">الحسابات المتاحة في Meta (<?= count($accounts) ?>)</h3>
    <?php $linkedIds = array_column($linked, 'external_id'); foreach ($accounts as $acct): ?><form method="post" class="row small" style="padding:6px 0;border-bottom:1px dashed var(--line)"><?= csrf_field() ?><input type="hidden" name="a" value="meta_link"><input type="hidden" name="acct" value="<?= h(json_encode($acct)) ?>">
      <span style="flex:1"><b><?= h($acct['name']) ?></b> <span class="muted ltr">act_<?= h($acct['id']) ?> · <?= h($acct['currency']) ?></span><?= $acct['status'] !== 1 ? ' <span class="badge b-warn">مش نشط</span>' : '' ?></span>
      <?php if (in_array($acct['id'], $linkedIds, true)): ?><span class="badge b-ok">مربوط</span><?php else: ?><select name="client_id" style="max-width:170px"><?= select_opts(client_opts(), '') ?></select><button class="btn sm">ربط</button><?php endif; ?></form><?php endforeach; ?>
  <?php endif; ?>
</div>
</div>

<div class="stack">
<div class="card"><h2>الذكاء الاصطناعي <?= $statusBadge(ai_integration()['status'] ?? '') ?><?= !empty($ac['enabled']) ? ' <span class="badge b-ok">مفعّل</span>' : '' ?></h2>
  <p class="muted small">بيستخدم في: ملخص التقارير، وتوليد الإعلانات. كل حاجة بتشتغل من غيره. مش بيقدر يعدّل ميزانيات أو يطلق حملات — بيرجع نص بس، والملخصات اللي فيها أرقام مش موجودة في البيانات بتترفض أوتوماتيك.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="ai">
    <div class="fgrid"><div><label class="f">المزود</label><select name="provider"><?= select_opts(['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI / متوافق'], $ac['provider'] ?? 'anthropic') ?></select></div>
      <div><label class="f">الموديل</label><input type="text" name="model" dir="ltr" value="<?= h($ac['model'] ?? 'claude-sonnet-5-5') ?>"></div>
      <div><label class="f">API Key</label><input type="password" name="api_key" dir="ltr" autocomplete="off" placeholder="<?= !empty($ac['api_key']) ? 'محفوظ ' . h(mask_secret($ac['api_key'])) : '' ?>"></div>
      <div><label class="f">Base URL (لـ OpenAI-compatible)</label><input type="text" name="base_url" dir="ltr" value="<?= h($ac['base_url'] ?? '') ?>"></div>
      <div><label class="f">حد الاستخدام الشهري (طلبات)</label><input type="number" name="monthly_calls" value="<?= (int)($ac['monthly_calls'] ?? 500) ?>"></div></div>
    <label class="check" style="margin-top:8px"><input type="checkbox" name="enabled" value="1" <?= !empty($ac['enabled']) ? 'checked' : '' ?>> مفعّل</label>
    <div class="row" style="margin-top:10px"><button class="btn sm">حفظ</button></div></form>
  <?php if (!empty($ac['api_key'])): ?><form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="a" value="ai_test"><button class="btn sm ghost">اختبار</button></form><?php endif; ?>
  <p class="small muted">الاستخدام الشهر ده: <?= ai_calls_this_month() ?> طلب · <?php $tk = q_one("SELECT SUM(input_tokens) i, SUM(output_tokens) o FROM ai_calls WHERE created_at >= ?", [gmdate('Y-m-01')]); echo number_format((int)$tk['i']) . ' input / ' . number_format((int)$tk['o']) . ' output tokens'; ?></p>
</div>
<div class="card"><h2>الإيميل</h2>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="mail"><input type="email" name="mail_from" dir="ltr" value="<?= h(setting('mail_from') ?? '') ?>" placeholder="alerts@ganoadsai.online" style="flex:1"><button class="btn sm">حفظ</button></form>
  <p class="hint">لو اتحدد، تنبيهات Watchdog اللي وسيلتها "إيميل" بتتبعت من خلال بريد السيرفر (PHP mail). لازم الإيميل ده يكون موجود على الدومين في Hostinger.</p></div>
<div class="card"><h2>Facebook Auto Reply</h2>
  <?php $as = ar_status(); ?><p class="small"><?= $as['connected'] ? '<span class="badge b-ok">متوصل</span> ' . $as['pages'] . ' صفحة' : '<span class="badge">مش متوصل</span>' ?> · <a href="<?= h(url('autoreply')) ?>">الإعدادات</a></p></div>
<div class="card"><h2>بيانات النظام</h2>
  <p class="small muted">Worker (cron): <?= ($ws = setting('worker_seen')) ? (time() - strtotime($ws . ' UTC') < 300 ? '<span class="badge b-ok">شغال</span>' : '<span class="badge b-bad">آخر تشغيل ' . h(fmt_dt($ws)) . '</span>') : '<span class="badge b-warn">لسه مااشتغلش — اتأكد من الـ Cron</span>' ?>
    <?php if ($we = setting('worker_error')): ?><br>آخر خطأ: <?= h(mb_substr($we, 0, 200)) ?><?php endif; ?>
    <br>استهلاك حدود Meta: <?= h(setting('meta_usage_pct') ?? '0') ?>%</p></div>
</div></div>
<?php layout_end();
