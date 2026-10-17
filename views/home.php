<?php
$ids = scope_client_ids();
if (is_post()) home_widgets($ids);
layout_start('الرئيسية');
if (function_exists('home_widgets')) { home_widgets($ids); layout_end(); return; } ?>
<div class="kpis">
  <?= kpi('العملاء', number_format(count($ids))) ?>
  <?= kpi('الحسابات الإعلانية', number_format((int)q_val('SELECT COUNT(*) FROM ad_accounts WHERE client_id IN (' . in_list($ids) . ')'))) ?>
</div>
<?php layout_end();
