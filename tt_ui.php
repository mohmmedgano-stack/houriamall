<?php
// TikTok section of the dashboard (actions + pages). Included from index.php after login.
declare(strict_types=1);

function tt_status_badge(string $s): string {
    $map = [
        'sent'    => ['ok',   'اترد',     'اترد على الكومنت'],
        'pending' => ['warn', 'جاري',     'جاري الرد'],
        'retry'   => ['warn', 'هيتعاد',   'خطأ مؤقت من تيك توك — هيتعاد لوحده'],
        'failed'  => ['bad',  'فشل',      'فشل — السبب مكتوب تحت الكومنت'],
    ];
    [$c, $t, $tip] = $map[$s] ?? ['off', $s, $s];
    return '<span class="badge ' . $c . '" title="' . h($tip) . '">' . $t . '</span>';
}

function tt_avatar(array $acc): string {
    return $acc['avatar'] ? '<img src="' . h($acc['avatar']) . '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">' : '<img alt="">';
}

function tt_handle_action(string $a): void {
    tt_db();
    $back = back_url();
    switch ($a) {
        case 'tt_save_settings':
            set_setting('tt_app_id', trim((string)($_POST['tt_app_id'] ?? '')));
            if (trim((string)($_POST['tt_app_secret'] ?? '')) !== '') set_setting('tt_app_secret', trim((string)$_POST['tt_app_secret']));
            set_setting('tt_auth_url', trim((string)($_POST['tt_auth_url'] ?? '')));
            flash('اتحفظت إعدادات تيك توك');
            redirect('?p=tt');

        case 'tt_toggle':
            db_run('UPDATE tt_accounts SET active = 1 - active WHERE id = ?', [(string)($_POST['id'] ?? '')]);
            redirect($back);

        case 'tt_unlink':
            $id = (string)($_POST['id'] ?? '');
            db_run('DELETE FROM tt_rules WHERE account_id = ?', [$id]);
            db_run('DELETE FROM tt_accounts WHERE id = ?', [$id]);
            flash('اتشال الحساب');
            redirect('?p=tt');

        case 'tt_rule_save':
            $acc = tt_account((string)($_POST['account'] ?? ''));
            if (!$acc) redirect('?p=tt');
            $id = (int)($_POST['id'] ?? 0);
            $scope = (string)($_POST['scope'] ?? 'video');
            $vid = $scope === 'video' ? trim((string)($_POST['manual_id'] ?? '')) ?: trim((string)($_POST['video_id'] ?? '')) : '';
            $wait = $scope === 'next' ? 1 : 0;
            $mode = ($_POST['match_mode'] ?? 'all') === 'keywords' ? 'keywords' : 'all';
            $kw = trim((string)($_POST['keywords'] ?? ''));
            $replies = trim((string)($_POST['replies'] ?? ''));
            $err = null;
            if ($scope === 'video' && $vid === '') $err = 'اختار الفيديو';
            elseif ($mode === 'keywords' && !keywords_of($kw)) $err = 'اكتب كلمة مفتاحية واحدة على الأقل';
            elseif (!variants($replies)) $err = 'اكتب الرد';
            if ($err) { flash($err, 'err'); $_SESSION['tt_draft'] = $_POST; redirect('?p=tt_rule&acc=' . urlencode($acc['id']) . ($id ? '&id=' . $id : '')); }
            $label = $scope === 'video' ? mb_substr(trim((string)($_POST['video_label'] ?? '')), 0, 80) : '';
            $thumb = $scope === 'video' ? (string)($_POST['video_thumb'] ?? '') : '';
            $wt = $wait ? mb_substr(trim((string)($_POST['wait_text'] ?? '')), 0, 200) : '';
            $name = trim((string)($_POST['name'] ?? '')) ?: ($wait ? ($wt !== '' ? 'فيديو جاي: ' . mb_substr($wt, 0, 30) : 'الفيديو الجاي') : ($scope === 'all' ? 'كل الفيديوهات' : ($label ?: 'فيديو ' . $vid)));
            $bf = ($scope === 'video' && !empty($_POST['backfill'])) ? 1 : 0;
            $vals = [$vid, $label, $thumb, $name, isset($_POST['active']) ? 1 : 0, $mode, $kw, $replies, !empty($_POST['like_comment']) ? 1 : 0, $wait, $wt, $bf];
            if ($id) {
                $st = db()->prepare('SELECT * FROM tt_rules WHERE id = ? AND account_id = ?'); $st->execute([$id, $acc['id']]);
                $old = fetch_one($st);
                if (!$old) redirect('?p=tt_acc&id=' . urlencode($acc['id']));
                $restartBf = $bf && (!(int)$old['backfill'] || $old['video_id'] !== $vid);
                db_run('UPDATE tt_rules SET video_id=?, video_label=?, video_thumb=?, name=?, active=?, match_mode=?, keywords=?, replies=?, like_comment=?, wait_next=?, wait_text=?, backfill=?'
                    . ($restartBf ? ", bf_done = 0, bf_cursor = ''" : '') . ' WHERE id = ?', array_merge($vals, [$id]));
            } else {
                db_run('INSERT INTO tt_rules (video_id, video_label, video_thumb, name, active, match_mode, keywords, replies, like_comment, wait_next, wait_text, backfill, since_ts, account_id, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [time() - 60, $acc['id'], now()]));
            }
            unset($_SESSION['tt_draft']);
            if (!(int)$acc['active']) flash('اتحفظت القاعدة ✅ — متنساش تفعّل الحساب عشان يبدأ يرد');
            else flash('اتحفظت القاعدة ✅ الردود بتبدأ في خلال دقيقة');
            redirect('?p=tt_acc&id=' . urlencode($acc['id']));

        case 'tt_rule_toggle':
            db_run('UPDATE tt_rules SET active = 1 - active WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            redirect($back);

        case 'tt_rule_delete':
            db_run('DELETE FROM tt_rules WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            flash('اتمسحت القاعدة');
            redirect($back);
    }
    redirect('?p=tt');
}

function tt_render(string $p): void {
    tt_db();
    $ready = setting('tt_app_id') && setting('tt_app_secret');

    if ($p === 'tt') {
        $accs = db()->query('SELECT a.*, (SELECT COUNT(*) FROM tt_rules r WHERE r.account_id = a.id) rc FROM tt_accounts a ORDER BY active DESC, username')->fetchAll();
        $logs = db()->query('SELECT l.*, a.username acc FROM tt_log l LEFT JOIN tt_accounts a ON a.id = l.account_id ORDER BY l.created_at DESC LIMIT 100')->fetchAll();
        $base = base_url();
        top('تيك توك'); ?>
        <div class="row" style="justify-content:space-between;margin-bottom:10px">
          <h1 style="margin:0">🎵 الرد على كومنتات تيك توك</h1>
          <?php if ($ready): ?><a class="btn" href="tt_oauth.php">＋ ربط حساب تيك توك</a><?php endif; ?>
        </div>
        <p class="muted">الأداة بتشيّك على الكومنتات الجديدة كل دقيقة وبترد عليها تحت الفيديو. تيك توك مش بيسمح بإرسال رسالة خاصة لصاحب الكومنت، فخلّي الرد يقوله يبعتلك أو يكلمك واتساب.</p>
        <?php if ($e = setting('tt_last_error')): ?><p class="muted" style="color:var(--bad)">آخر خطأ من تيك توك: <?= h($e) ?></p><?php endif; ?>

        <?php if (!$accs): ?><div class="card"><p class="muted">مفيش حسابات تيك توك متربطة لسه.<?= $ready ? '' : ' حط بيانات تطبيق تيك توك تحت الأول.' ?></p></div><?php endif; ?>
        <div class="grid" style="margin-bottom:16px">
        <?php foreach ($accs as $acc): ?>
          <div class="card" style="margin:0">
            <div class="pg"><?= tt_avatar($acc) ?><div style="min-width:0">
              <strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($acc['display_name'] ?: $acc['username'] ?: $acc['id']) ?></strong>
              <?= $acc['username'] ? '<span class="muted" dir="ltr">@' . h($acc['username']) . '</span> ' : '' ?>
              <?= (int)$acc['active'] === 1 ? '<span class="badge ok">شغال</span>' : '<span class="badge off">واقف</span>' ?> <span class="muted"><?= (int)$acc['rc'] ?> قاعدة</span></div></div>
            <?php if ($acc['problem']): ?><p class="muted" style="color:var(--bad);margin:8px 0 0">⚠️ <?= h($acc['problem']) ?> — دوس "ربط حساب تيك توك" تاني.</p><?php endif; ?>
            <div class="row" style="margin-top:12px">
              <a class="btn sm" href="?p=tt_acc&id=<?= urlencode($acc['id']) ?>">إدارة الردود</a>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="tt_toggle"><input type="hidden" name="id" value="<?= h($acc['id']) ?>"><input type="hidden" name="back" value="?p=tt"><button class="btn sm <?= (int)$acc['active'] === 1 ? 'red' : 'sec' ?>"><?= (int)$acc['active'] === 1 ? 'إيقاف' : 'تفعيل' ?></button></form>
            </div>
          </div>
        <?php endforeach; ?>
        </div>

        <div class="card tbl">
          <h2>آخر الردود على تيك توك</h2>
          <?php if (!$logs): ?><p class="muted">لسه مفيش.</p><?php else: ?>
          <table class="log"><tr class="hd"><th>الوقت</th><th>الحساب</th><th>صاحب الكومنت</th><th>الكومنت</th><th>الرد</th></tr>
          <?php foreach ($logs as $l): ?><tr><td class="when" style="white-space:nowrap"><?= h(fmt_time($l['created_at'])) ?></td><td class="when" dir="ltr">@<?= h($l['acc']) ?></td><td class="who"><?= h($l['username'] ?: '—') ?></td>
            <td class="msg"><div class="clip"><?= h($l['text']) ?></div><?php if ($l['error']): ?><div class="muted" style="color:var(--bad)"><?= h($l['error']) ?></div><?php endif; ?></td>
            <td class="st" data-l="الرد"><?= tt_status_badge($l['status']) ?></td></tr><?php endforeach; ?>
          </table><?php endif; ?>
        </div>

        <details class="card" <?= $ready ? '' : 'open' ?>>
          <summary>⚙️ بيانات تطبيق تيك توك (TikTok API for Business)</summary>
          <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="a" value="tt_save_settings">
            <label class="f">App ID</label><input type="text" name="tt_app_id" value="<?= h(setting('tt_app_id', '')) ?>" dir="ltr">
            <label class="f">Secret</label><input type="password" name="tt_app_secret" dir="ltr" autocomplete="off" placeholder="<?= setting('tt_app_secret') ? '•••••••• (محفوظ — سيبه فاضي لو مش هتغيره)' : '' ?>">
            <label class="f">رابط التفويض <span class="muted">(اختياري — انسخه من صفحة التطبيق في بوابة المطورين لو زرار الربط مشتغلش)</span></label>
            <input type="text" name="tt_auth_url" value="<?= h(setting('tt_auth_url', '')) ?>" dir="ltr" placeholder="https://www.tiktok.com/v2/auth/authorize?...">
            <div style="margin-top:14px"><button class="btn">حفظ</button></div>
          </form>
          <h2 style="margin-top:18px">روابط هتحطها في تطبيق تيك توك</h2>
          <label class="f">Redirect URL</label><div class="copy"><?= h($base . '/tt_oauth.php') ?></div>
          <label class="f">Privacy Policy URL</label><div class="copy"><?= h($base . '/privacy.php') ?></div>
          <label class="f">Terms of Service URL</label><div class="copy"><?= h($base . '/terms.php') ?></div>
          <label class="f">الصلاحيات (Scopes) المطلوبة</label><div class="copy"><?= h(TT_SCOPES) ?></div>
        </details>
        <?php bottom(); return;
    }

    if ($p === 'tt_acc') {
        $acc = tt_account((string)($_GET['id'] ?? ''));
        if (!$acc) redirect('?p=tt');
        $st = db()->prepare("SELECT * FROM tt_rules WHERE account_id = ? ORDER BY (video_id = ''), id DESC"); $st->execute([$acc['id']]);
        $rules = $st->fetchAll();
        $st = db()->prepare('SELECT * FROM tt_log WHERE account_id = ? ORDER BY created_at DESC LIMIT 30'); $st->execute([$acc['id']]);
        $logs = $st->fetchAll();
        $back = '?p=tt_acc&id=' . urlencode($acc['id']);
        top('تيك توك · ' . ($acc['username'] ?: $acc['id'])); ?>
        <p><a href="?p=tt">→ حسابات تيك توك</a></p>
        <div class="row" style="justify-content:space-between;margin-bottom:14px">
          <div class="pg"><?= tt_avatar($acc) ?><div><h1 style="margin:0"><?= h($acc['display_name'] ?: $acc['username']) ?></h1>
            <?= (int)$acc['active'] === 1 ? '<span class="badge ok">الرد التلقائي شغال</span>' : '<span class="badge off">الرد التلقائي واقف</span>' ?></div></div>
          <div class="row">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="tt_toggle"><input type="hidden" name="id" value="<?= h($acc['id']) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
              <button class="btn <?= (int)$acc['active'] === 1 ? 'red' : '' ?>"><?= (int)$acc['active'] === 1 ? 'إيقاف الحساب' : 'تفعيل الحساب' ?></button></form>
            <a class="btn sec" href="?p=tt_rule&acc=<?= urlencode($acc['id']) ?>">＋ قاعدة رد جديدة</a>
          </div>
        </div>
        <?php if ($acc['problem']): ?><div class="flash err">⚠️ <?= h($acc['problem']) ?> — <a href="tt_oauth.php">اربط الحساب تاني</a></div><?php endif; ?>
        <div class="card">
          <h2>قواعد الرد</h2>
          <p class="muted">الأولوية: قاعدة الفيديو نفسه ← بعدين قاعدة "كل الفيديوهات". وجوه كل نوع، قواعد الكلمات المفتاحية قبل "أي كومنت".</p>
          <?php if (!$rules): ?><p>مفيش قواعد لسه. <a href="?p=tt_rule&acc=<?= urlencode($acc['id']) ?>">اعمل أول قاعدة</a></p><?php endif; ?>
          <?php foreach ($rules as $r): ?>
            <div class="rule">
              <div class="row" style="justify-content:space-between">
                <div class="row" style="gap:10px"><?php if ($r['video_thumb']): ?><img src="<?= h($r['video_thumb']) ?>" alt="" style="width:38px;height:52px;object-fit:cover;border-radius:6px" onerror="this.remove()"><?php endif; ?>
                  <div><strong><?= h($r['name']) ?></strong> <?= (int)$r['active'] === 1 ? '<span class="badge ok">شغالة</span>' : '<span class="badge off">واقفة</span>' ?></div></div>
                <div class="row">
                  <a class="btn sec sm" href="?p=tt_rule&acc=<?= urlencode($acc['id']) ?>&id=<?= (int)$r['id'] ?>">تعديل</a>
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="tt_rule_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="btn sec sm"><?= (int)$r['active'] === 1 ? 'إيقاف' : 'تشغيل' ?></button></form>
                  <form method="post" data-confirm="تمسح القاعدة دي؟"><?= csrf_field() ?><input type="hidden" name="a" value="tt_rule_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="btn red sm">مسح</button></form>
                </div>
              </div>
              <div class="muted" style="margin-top:6px">
                📍 <?= (int)$r['wait_next'] === 1 ? '<span class="badge warn">' . h($r['wait_text'] !== '' ? '⏳ مستنية فيديو فيه: «' . mb_substr($r['wait_text'], 0, 30) . '»' : '⏳ مستنية الفيديو الجاي') . '</span>' : ($r['video_id'] ? 'فيديو: ' . h($r['video_label'] ?: $r['video_id']) : 'كل الفيديوهات (آخر 10)') ?> ·
                🔎 <?= $r['match_mode'] === 'keywords' ? 'كلمات: ' . h(implode('، ', keywords_of($r['keywords']))) : 'أي كومنت' ?> ·
                💬 <?= count(variants($r['replies'])) ?> صيغة رد<?= (int)$r['like_comment'] === 1 ? ' · ❤️ لايك' : '' ?>
                <?= (int)$r['backfill'] === 1 ? ((int)$r['bf_done'] === 1 ? ' · ✅ خلص الكومنتات القديمة' : ' · ⏳ بيرد على القديم') : '' ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="card tbl">
          <h2>آخر الردود</h2>
          <?php if (!$logs): ?><p class="muted">لسه مفيش.</p><?php else: ?>
          <table class="log"><tr class="hd"><th>الوقت</th><th>صاحب الكومنت</th><th>الكومنت</th><th>الرد</th></tr>
          <?php foreach ($logs as $l): ?><tr><td class="when" style="white-space:nowrap"><?= h(fmt_time($l['created_at'])) ?></td><td class="who"><?= h($l['username'] ?: '—') ?></td>
            <td class="msg"><div class="clip"><?= h($l['text']) ?></div><?php if ($l['reply_text']): ?><div class="muted">↩︎ <?= h($l['reply_text']) ?></div><?php endif; ?><?php if ($l['error']): ?><div class="muted" style="color:var(--bad)"><?= h($l['error']) ?></div><?php endif; ?></td>
            <td class="st" data-l="الرد"><?= tt_status_badge($l['status']) ?></td></tr><?php endforeach; ?>
          </table><?php endif; ?>
        </div>
        <?php bottom(); return;
    }

    if ($p === 'tt_rule') {
        $acc = tt_account((string)($_GET['acc'] ?? ''));
        if (!$acc) redirect('?p=tt');
        $r = ['id' => 0, 'name' => '', 'video_id' => '', 'video_label' => '', 'video_thumb' => '', 'active' => 1, 'match_mode' => 'all', 'keywords' => '', 'replies' => '', 'like_comment' => 0, 'wait_next' => 0, 'wait_text' => '', 'backfill' => 0];
        if (!empty($_GET['id'])) {
            $st = db()->prepare('SELECT * FROM tt_rules WHERE id = ? AND account_id = ?'); $st->execute([(int)$_GET['id'], $acc['id']]);
            $r = fetch_one($st) ?: $r;
        }
        $scope = (int)$r['wait_next'] === 1 ? 'next' : ($r['id'] && $r['video_id'] === '' ? 'all' : 'video');
        if (!empty($_SESSION['tt_draft'])) {
            $d = $_SESSION['tt_draft']; unset($_SESSION['tt_draft']);
            foreach (['name', 'match_mode', 'keywords', 'replies', 'wait_text', 'video_label'] as $k) $r[$k] = $d[$k] ?? $r[$k];
            $r['video_id'] = $d['manual_id'] ?? '' ?: ($d['video_id'] ?? $r['video_id']);
            $scope = $d['scope'] ?? $scope;
        }
        // newest videos for the picker (up to 60)
        $videos = []; $verr = null; $cur = '';
        for ($i = 0; $i < 3; $i++) {
            $vl = tt_videos($acc, 20, $cur);
            if (isset($vl['error'])) { $verr = $vl['error']; break; }
            $videos = array_merge($videos, $vl['videos']);
            if (!$vl['has_more'] || $vl['cursor'] === '') break;
            $cur = $vl['cursor'];
        }
        top($r['id'] ? 'تعديل قاعدة' : 'قاعدة جديدة'); ?>
        <p><a href="?p=tt_acc&id=<?= urlencode($acc['id']) ?>">→ <?= h($acc['display_name'] ?: $acc['username']) ?></a></p>
        <h1><?= $r['id'] ? 'تعديل قاعدة رد' : 'قاعدة رد جديدة' ?> · تيك توك</h1>
        <form method="post" class="card" id="ttf"><?= csrf_field() ?>
          <input type="hidden" name="a" value="tt_rule_save"><input type="hidden" name="account" value="<?= h($acc['id']) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="video_label" id="vlabel" value="<?= h($r['video_label']) ?>"><input type="hidden" name="video_thumb" id="vthumb" value="<?= h($r['video_thumb']) ?>">
          <label class="f">اسم القاعدة <span class="muted">(اختياري)</span></label><input type="text" name="name" value="<?= h($r['name']) ?>">

          <label class="f">الرد يشتغل على</label>
          <label class="opt"><input type="radio" name="scope" value="video" <?= $scope === 'video' ? 'checked' : '' ?>><div><strong>🎬 فيديو معيّن</strong></div></label>
          <label class="opt"><input type="radio" name="scope" value="all" <?= $scope === 'all' ? 'checked' : '' ?>><div><strong>كل الفيديوهات</strong><div class="muted">آخر 10 فيديوهات على الحساب</div></div></label>
          <label class="opt"><input type="radio" name="scope" value="next" <?= $scope === 'next' ? 'checked' : '' ?>><div><strong>⏰ فيديو لسه منزلش</strong><div class="muted">القاعدة تستنى، وأول ما الفيديو ينزل تتربط بيه وتبدأ ترد لوحدها</div></div></label>

          <div id="waitbox">
            <label class="f">كلمة أو جملة من وصف الفيديو الجاي <span class="muted">(اختياري)</span></label>
            <input type="text" name="wait_text" value="<?= h($r['wait_text']) ?>" placeholder="مثلاً: عصارة فواكه">
            <p class="muted">لو كتبتها، القاعدة تتربط بالفيديو اللي في وصفه الكلام ده. لو سبتها فاضية، تتربط بأول فيديو ينزل.</p>
          </div>

          <div id="vidbox">
            <?php if ($verr): ?><p class="flash err">مقدرتش أجيب الفيديوهات: <?= h($verr) ?></p><?php endif; ?>
            <?php if ($videos): ?>
            <label class="f">اختار الفيديو</label>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;max-height:430px;overflow:auto;padding:2px">
              <?php foreach ($videos as $v): $vid = tt_vid($v); $cap = trim((string)($v['caption'] ?? '')); $when = isset($v['create_time']) ? date('Y-m-d', (int)$v['create_time'] + 3 * 3600) : ''; ?>
              <label class="vpick" style="border:2px solid var(--line);border-radius:10px;padding:4px;cursor:pointer;display:block">
                <input type="radio" name="video_id" value="<?= h($vid) ?>" data-label="<?= h(mb_substr($cap, 0, 80) ?: 'فيديو ' . $when) ?>" data-thumb="<?= h($v['thumbnail_url'] ?? '') ?>" <?= $r['video_id'] === $vid ? 'checked' : '' ?> style="display:none">
                <img src="<?= h($v['thumbnail_url'] ?? '') ?>" alt="" loading="lazy" style="width:100%;aspect-ratio:9/14;object-fit:cover;border-radius:7px;background:var(--line);display:block">
                <div class="muted clip" style="font-size:12px;line-height:1.4;margin-top:4px"><?= h($cap ?: '—') ?></div>
                <div class="muted" style="font-size:11px"><?= h($when) ?></div>
              </label>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <details style="margin-top:10px" <?= ($r['video_id'] && !in_array($r['video_id'], array_map('tt_vid', $videos), true)) ? 'open' : '' ?>><summary>أو حط ID الفيديو بإيدك</summary>
              <input type="text" name="manual_id" dir="ltr" style="margin-top:8px" value="<?= h(($r['video_id'] && !in_array($r['video_id'], array_map('tt_vid', $videos), true)) ? $r['video_id'] : '') ?>" placeholder="الرقم اللي في آخر رابط الفيديو">
            </details>
            <label class="opt" style="margin-top:12px"><input type="checkbox" name="backfill" value="1" <?= (int)$r['backfill'] === 1 ? 'checked' : '' ?>><div><strong>رد كمان على الكومنتات القديمة</strong><div class="muted">على دفعات، من الأحدث للأقدم، ومش هيكرر رد على كومنت اترد عليه من الأداة</div></div></label>
          </div>

          <label class="f">يرد على</label>
          <label class="opt"><input type="radio" name="match_mode" value="all" <?= $r['match_mode'] !== 'keywords' ? 'checked' : '' ?>><div><strong>أي كومنت</strong></div></label>
          <label class="opt"><input type="radio" name="match_mode" value="keywords" <?= $r['match_mode'] === 'keywords' ? 'checked' : '' ?>><div><strong>الكومنتات اللي فيها كلمات معيّنة</strong></div></label>
          <div id="kwbox"><input type="text" name="keywords" value="<?= h($r['keywords']) ?>" placeholder="السعر، بكام، تفاصيل"></div>

          <label class="f">الرد</label>
          <textarea name="replies" placeholder="أهلاً {name} 🌸 السعر 350ج — ابعتلنا رسالة أو كلمنا واتساب 01xxxxxxxxx"><?= h($r['replies']) ?></textarea>
          <p class="muted">اكتب <code>{name}</code> عشان يتحط اسم صاحب الكومنت. لو عايز أكتر من صيغة (عشان الردود متبقاش شبه بعض)، افصل بينهم بسطر فيه <code>---</code> والأداة هتختار واحدة عشوائي. أقصى طول للرد 150 حرف.</p>

          <label class="opt" style="margin-top:10px"><input type="checkbox" name="like_comment" value="1" <?= (int)$r['like_comment'] === 1 ? 'checked' : '' ?>><div><strong>❤️ اعمل لايك على الكومنت</strong></div></label>
          <label class="opt"><input type="checkbox" name="active" value="1" <?= (int)$r['active'] === 1 ? 'checked' : '' ?>><div><strong>القاعدة شغالة</strong></div></label>
          <div style="margin-top:16px"><button class="btn">حفظ القاعدة</button></div>
        </form>
        <script>
        (function(){const f=document.getElementById('ttf');
          function sync(){const s=f.querySelector('input[name=scope]:checked').value;
            f.querySelector('#vidbox').style.display=s==='video'?'':'none';f.querySelector('#waitbox').style.display=s==='next'?'':'none';
            f.querySelector('#kwbox').style.display=f.querySelector('input[name=match_mode]:checked').value==='keywords'?'':'none';
            f.querySelectorAll('.vpick').forEach(l=>{const i=l.querySelector('input');l.style.borderColor=i.checked?'var(--acc)':'var(--line)';if(i.checked){f.querySelector('#vlabel').value=i.dataset.label;f.querySelector('#vthumb').value=i.dataset.thumb}})}
          f.addEventListener('change',sync);sync();
          f.addEventListener('input',e=>{if(e.target.name==='manual_id'&&e.target.value.trim()){f.querySelectorAll('input[name=video_id]').forEach(i=>i.checked=false);f.querySelector('#vlabel').value='';f.querySelector('#vthumb').value='';sync()}});
          f.querySelector('textarea').addEventListener('input',e=>{const t=e.target;t.style.borderColor=t.value.split(/^\s*-{3,}\s*$/m).some(x=>x.trim().length>150)?'var(--bad)':''});
        })();
        </script>
        <?php bottom(); return;
    }
}
