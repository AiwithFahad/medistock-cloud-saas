<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

$totals = [
    'tenants'         => (int)$pdo->query('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL')->fetchColumn(),
    'active_tenants'  => (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn(),
    'suspended'       => (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'suspended' AND deleted_at IS NULL")->fetchColumn(),
    'users'           => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn(),
    'active_subs'     => (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','grace')")->fetchColumn(),
    'trial_subs'      => (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'trial'")->fetchColumn(),
    'expired_subs'    => (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'expired'")->fetchColumn(),
    'pending_pay'     => (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn(),
];
$revenue = SubscriptionService::revenueStats();

$recentTenants = $pdo->query(
    'SELECT t.*, (SELECT name FROM subscription_plans p JOIN subscriptions s ON s.plan_id = p.id WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan_name
       FROM tenants t WHERE t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 8'
)->fetchAll();
$recentPayments = $pdo->query(
    'SELECT p.*, t.name AS tenant_name FROM payments p LEFT JOIN tenants t ON t.id = p.tenant_id ORDER BY p.id DESC LIMIT 8'
)->fetchAll();

// Revenue by month (verified payments) for the chart.
$revRows = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(amount) AS total FROM payments
      WHERE status = 'verified' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      GROUP BY ym ORDER BY ym"
)->fetchAll();
$revLabels = array_column($revRows, 'ym');
$revData = array_map('floatval', array_column($revRows, 'total'));
// Subscriptions by plan for the doughnut.
$planRows = $pdo->query(
    'SELECT p.name, COUNT(*) AS c FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
      WHERE s.status IN ("trial","active","grace") GROUP BY p.name ORDER BY c DESC'
)->fetchAll();

admin_header('Super Admin Dashboard', 'superadmin/index.php');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div><h3 class="mb-0 text-navy fw-bold">Platform Overview</h3><small class="text-muted">All figures are computed live from the platform database.</small></div>
  <a class="btn btn-emerald" href="<?= url('superadmin/tenants.php') ?>"><i class="bi bi-building"></i> Manage pharmacies</a>
</div>

<div class="row g-3 mb-3">
  <?php stat_card('bi-building', 'Total Pharmacies', number_format($totals['tenants']), 'emerald', $totals['active_tenants'] . ' active · ' . $totals['suspended'] . ' suspended'); ?>
  <?php stat_card('bi-people', 'Total Users', number_format($totals['users']), 'sky'); ?>
  <?php stat_card('bi-stars', 'Active Subscriptions', number_format($totals['active_subs']), 'emerald', $totals['trial_subs'] . ' on trial · ' . $totals['expired_subs'] . ' expired'); ?>
  <?php stat_card('bi-credit-card', 'Payment Transactions', number_format($revenue['transactions']), 'amber', $totals['pending_pay'] . ' pending verification'); ?>
</div>
<div class="row g-3 mb-4">
  <?php stat_card('bi-arrow-repeat', 'Monthly Recurring Revenue', money($revenue['mrr']), 'emerald'); ?>
  <?php stat_card('bi-calendar-range', 'Annual Recurring Revenue', money($revenue['arr']), 'sky'); ?>
  <?php stat_card('bi-cash-stack', 'Verified Revenue (all time)', money($revenue['verified_revenue']), 'emerald'); ?>
  <?php stat_card('bi-exclamation-triangle', 'Pending Payments', number_format($totals['pending_pay']), 'rose', 'Verify from Payments'); ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy">Verified revenue — last 6 months</h6>
    <canvas id="revChart" height="140"></canvas>
  </div></div></div>
  <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy">Subscriptions by plan</h6>
    <canvas id="planChart" height="180"></canvas>
  </div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Newest pharmacies</h6>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Pharmacy</th><th>Plan</th><th>Status</th><th>Joined</th></tr></thead>
      <tbody>
      <?php foreach ($recentTenants as $t): ?>
        <tr><td><strong><?= e($t['name']) ?></strong><br><small class="text-muted"><?= e($t['email']) ?></small></td>
        <td><?= e($t['plan_name'] ?? '—') ?></td><td><?= status_badge($t['status']) ?></td><td><?= fmt_date($t['created_at']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div></div></div>
  <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Latest payments</h6>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Tenant</th><th>Amount</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentPayments as $p): ?>
        <tr><td><?= e($p['tenant_name'] ?? '—') ?></td><td><?= money($p['amount']) ?></td><td><?= status_badge($p['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div></div></div>
</div>

<script>
new Chart(document.getElementById('revChart'), {type:'bar',
  data:{labels:<?= json_encode($revLabels) ?>, datasets:[{label:'Verified revenue', data:<?= json_encode($revData) ?>, backgroundColor:'#059669', borderRadius:6}]},
  options:{plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}}});
new Chart(document.getElementById('planChart'), {type:'doughnut',
  data:{labels:<?= json_encode(array_column($planRows, 'name')) ?>, datasets:[{data:<?= json_encode(array_map('intval', array_column($planRows, 'c'))) ?>, backgroundColor:['#059669','#0ea5e9','#f59e0b','#8b5cf6','#64748b']}]},
  options:{plugins:{legend:{position:'bottom'}}}});
</script>
<?php admin_footer(); ?>
