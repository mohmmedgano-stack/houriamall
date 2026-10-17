<?php
$accounts = accounts_for(scope_client_ids());
$result = null;
if (is_post()) {
    $aid = (int)in_int('account');
    $cid = account_client_id($aid);
    if (!$cid || !can_client($cid)) throw new Forbidden('account');
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('اختار ملف CSV أو XLSX');
    if ($file['size'] > 25 * 1024 * 1024) throw new InvalidArgumentException('الملف أكبر من 25 ميجا. قسّمه على فترات.');
    $rows = read_table_file($file['tmp_name'], $file['name']);
    $result = import_performance($aid, $rows, ['date_start' => in_date('date_start'), 'date_end' => in_date('date_end')]);
    record_import($cid, 'performance', $file['name'], $result);
    audit('import.performance', 'ad_account', $aid, ['file' => $file['name'], 'inserted' => $result['inserted'], 'updated' => $result['updated'], 'skipped' => $result['skipped']]);
    if (function_exists('emit_event')) emit_event('performance.imported', $cid, (string)$aid, $result);
    $_SESSION['import_result'] = $result;
    redirect(url('import'));
}
$result = $_SESSION['import_result'] ?? null; unset($_SESSION['import_result']);
$batches = q_all("SELECT b.*, c.name client_name, u.name uname FROM import_batches b LEFT JOIN clients c ON c.id = b.client_id LEFT JOIN users u ON u.id = b.created_by
    WHERE b.client_id IN (" . in_list(scope_client_ids()) . ") ORDER BY b.id DESC LIMIT 20");
layout_start('استيراد بيانات الأداء'); ?>
<?php if ($result): ?>
<div class="card" style="margin-bottom:16px;border-color:var(--ok)"><h2>نتيجة الاستيراد</h2>
  <div class="kpis"><?= kpi('صفوف', number_format($result['rows'])) ?><?= kpi('جديد', number_format($result['inserted'])) ?><?= kpi('اتحدّث', number_format($result['updated'])) ?><?= kpi('اتجاهل', number_format($result['skipped'])) ?></div>
  <p class="small">المستوى: <b><?= h($result['level']) ?></b> · العملة: <b class="ltr"><?= h($result['currency']) ?></b><?= $result['breakdowns'] ? ' · تقسيم حسب: <b>' . h(implode('، ', $result['breakdowns'])) . '</b>' : '' ?></p>
  <?php if ($result['errors']): ?><details class="box"><summary><?= count($result['errors']) ?> ملاحظة</summary><ul class="small"><?php foreach (array_slice($result['errors'], 0, 50) as $e) echo '<li>' . h($e) . '</li>'; ?></ul></details><?php endif; ?>
  <a class="btn sm" href="<?= h(url('dashboard')) ?>">افتح الـ Dashboard</a>
</div>
<?php endif; ?>
<div class="grid g2">
<div class="card"><h2><?= icon('upload') ?> ارفع تقرير من Ads Manager</h2>
  <?php if (!$accounts): echo empty_state('مفيش حسابات إعلانية', ' ضيف حساب لعميل الأول.', '<a class="btn" href="' . h(url('clients')) . '">العملاء</a>'); else: ?>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <label class="f">الحساب الإعلاني</label><select name="account" required><?php foreach ($accounts as $a) echo '<option value="' . (int)$a['id'] . '">' . h($a['client_name'] . ' — ' . $a['name'] . ' (' . $a['currency'] . ')') . '</option>'; ?></select>
    <label class="f">الملف (CSV أو XLSX)</label><input type="file" name="file" accept=".csv,.xlsx,.tsv,.txt" required>
    <details class="box" style="margin-top:12px"><summary>الملف مفيهوش عمود تاريخ؟</summary>
      <div class="fgrid"><div><label class="f">من</label><input type="date" name="date_start"></div><div><label class="f">إلى</label><input type="date" name="date_end"></div></div>
      <div class="hint">لو صدّرت التقرير بالفترة كاملة من غير تقسيم "Day"، حدد الفترة اللي بيغطيها.</div></details>
    <button class="btn" style="margin-top:14px">استيراد</button>
  </form><?php endif; ?></div>
<div class="card"><h2>إزاي تصدّر التقرير صح</h2>
  <ol class="small" style="padding-inline-start:18px;margin:0">
    <li>من Ads Manager افتح المستوى اللي عايزه (Campaigns / Ad sets / Ads).</li>
    <li>من <b>Breakdown ← By time ← Day</b> عشان الرسم اليومي والمقارنة يشتغلوا. (أو صدّر الفترة كاملة وحدد تواريخها هنا.)</li>
    <li>اختياري: <b>By delivery ← Age / Gender / Region / Placement</b> عشان Audience Tester.</li>
    <li><b>Reports ← Export table data</b> واختار CSV أو XLSX.</li>
  </ol>
  <p class="small muted">بنتعرف على الأعمدة بالعربي والإنجليزي. عمود الصرف لازم يكون بنفس عملة الحساب (مثلاً "Amount spent (EGP)")، ولو مختلف الملف بيترفض عشان الأرقام متتخلطش. لو رفعت نفس الفترة تاني، الأرقام بتتحدّث مش بتتكرر.</p>
  <p class="small muted">الأعمدة المدعومة: Campaign/Ad set/Ad name و IDs، Day أو Reporting starts/ends، Amount spent، Impressions، Reach، Clicks (all)، Link clicks، Results + Result indicator، Leads، Purchases، Purchases conversion value، Landing page views، 3-second plays، ThruPlays، Video plays at 25/50/75/100%، Objective، Delivery، Body، Title، Call to action.</p>
</div></div>
<div class="card" style="margin-top:16px"><h2>آخر عمليات الاستيراد</h2>
  <?php if (!$batches): echo empty_state('مفيش استيراد لسه'); else: ?>
  <div class="tbl"><table class="t cards"><thead><tr><th>الملف</th><th>العميل</th><th>النوع</th><th class="n">صفوف</th><th class="n">جديد</th><th class="n">تحديث</th><th class="n">اتجاهل</th><th>بواسطة</th><th>الوقت</th></tr></thead><tbody>
  <?php foreach ($batches as $b): ?><tr><td class="main-cell"><?= h($b['filename']) ?></td><td data-l="العميل"><?= h($b['client_name']) ?></td><td data-l="النوع"><?= h($b['kind']) ?></td>
    <td class="n" data-l="صفوف"><?= (int)$b['rows_total'] ?></td><td class="n" data-l="جديد"><?= (int)$b['inserted'] ?></td><td class="n" data-l="تحديث"><?= (int)$b['updated'] ?></td><td class="n" data-l="اتجاهل"><?= (int)$b['skipped'] ?></td>
    <td data-l="بواسطة"><?= h($b['uname']) ?></td><td data-l="الوقت" class="ltr"><?= h(fmt_dt($b['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php layout_end();
