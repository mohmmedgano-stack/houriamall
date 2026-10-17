<?php
$r = q_one('SELECT * FROM reports WHERE id = ?', [(int)in_int('id')]);
if (!$r || !can_client((int)$r['client_id'])) throw new Forbidden('report');
$d = json_decode($r['data_json'], true);
layout_start('تقرير'); ?>
<div class="row no-print" style="margin-bottom:12px">
  <a class="btn ghost sm" href="<?= h(url('reports')) ?>">← التقارير</a><span class="sp"></span>
  <a class="btn sm" href="<?= h(url('export', ['kind' => 'report', 'id' => $r['id'], 'format' => 'pdf'])) ?>"><?= icon('download') ?> PDF</a>
  <a class="btn sm ghost" href="<?= h(url('export', ['kind' => 'report', 'id' => $r['id'], 'format' => 'xlsx'])) ?>"><?= icon('download') ?> Excel</a>
  <a class="btn sm ghost" href="<?= h(url('export', ['kind' => 'report', 'id' => $r['id'], 'format' => 'csv'])) ?>"><?= icon('download') ?> CSV</a>
  <button class="btn sm ghost" onclick="window.print()"><?= icon('print') ?> طباعة</button>
  <?php if (can('reports.manage')): ?><form method="post" action="<?= h(url('reports')) ?>" data-confirm="تمسح التقرير؟"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost">حذف</button></form><?php endif; ?>
</div>
<div class="card report-doc"><?= report_html($r, $d) ?></div>
<style>.report-doc table{border-collapse:collapse;margin:8px 0}.report-doc td{border-color:var(--line)!important;font-variant-numeric:tabular-nums}.report-doc h1{margin:0 0 4px}.report-doc h2{margin:22px 0 8px;border-bottom:1px solid var(--line);padding-bottom:4px}
.report-doc td[style*="eef2f0"]{background:var(--panel2)!important}.report-doc li{margin:4px 0}@media (max-width:860px){.report-doc{overflow-x:auto}}</style>
<?php layout_end();
