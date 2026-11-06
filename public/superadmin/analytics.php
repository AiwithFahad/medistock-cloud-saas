<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

$tenantGrowth = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM tenants
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym"
)->fetchAll();
$subStatus = $pdo->query('SELECT status, COUNT(*) AS c FROM subscriptions GROUP BY status ORDER BY c DESC')->fetchAll();
$topPlans = $pdo->query(
    'SELECT p.name, COUNT(*) AS subs, COALESCE(SUM(CASE WHEN s.billing_cycle = "yearly" THEN p.yearly_price / 12 ELSE p.monthly_price END), 0) AS mrr
       FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
      WHERE s.status IN ("active","grace") GROUP BY p.name ORDER BY mrr DESC'
)->fetchAll();
$payMethods = $pdo->query(
    "SELECT method, COUNT(*) AS c, COALESCE(SUM(amount),0) AS total FROM payments WHERE status = 'verified' GROUP BY method"
)->fetchAll();

admin_header('Platform Analytics', 'superadmin/analytics.php');
?>
<h3 class="text-navy fw-bold mb-3">Revenue &amp; Subscription Analytics</h3>
<div class="row g-3 mb-4">
  <div class="col-lg-8"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy">New pharmacies — last 12 months</h6><canvas id="growthChart" height="140"></canvas>
  </div></div></div>
  <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy">Subscriptions by status</h6><canvas id="statusChart" height="180"></canvas>
  </div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">MRR by plan</h6>
    <table class="table table-sm align-middle mb-0"><thead><tr><th>Plan</th><th>Active subs</th><th class="text-end">MRR contribution</th></tr></thead>
    <tbody><?php foreach ($topPlans as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= (int)$r['subs'] ?></td><td class="text-end"><strong><?= money($r['mrr']) ?></strong></td></tr><?php endforeach; ?></tbody></table>
  </div></div></div>
  <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Verified payments by method</h6>
    <table class="table table-sm align-middle mb-0"><thead><tr><th>Method</th><th>Count</th><th class="text-end">Total</th></tr></thead>
    <tbody><?php foreach ($payMethods as $r): ?><tr><td><?= e(ucfirst($r['method'])) ?></td><td><?= (int)$r['c'] ?></td><td class="text-end"><strong><?= money($r['total']) ?></strong></td></tr><?php endforeach; ?>
    <?php if (!$payMethods): ?><tr><td colspan="3" class="text-muted text-center">No verified payments yet.</td></tr><?php endif; ?></tbody></table>
  </div></div></div>
</div>
<script>
new Chart(document.getElementById('growthChart'), {type:'line',
  data:{labels:<?= json_encode(array_column($tenantGrowth, 'ym')) ?>, datasets:[{label:'New pharmacies', data:<?= json_encode(array_map('intval', array_column($tenantGrowth, 'c'))) ?>, borderColor:'#059669', backgroundColor:'rgba(5,150,105,.15)', fill:true, tension:.3}]},
  options:{plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}}}});
new Chart(document.getElementById('statusChart'), {type:'doughnut',
  data:{labels:<?= json_encode(array_column($subStatus, 'status')) ?>, datasets:[{data:<?= json_encode(array_map('intval', array_column($subStatus, 'c'))) ?>, backgroundColor:['#059669','#0ea5e9','#f59e0b','#ef4444','#64748b','#8b5cf6']}]},
  options:{plugins:{legend:{position:'bottom'}}}});
</script>
<?php admin_footer(); ?>
