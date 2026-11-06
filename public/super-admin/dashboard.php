<?php
/**
 * MediStock Cloud — Super Admin dashboard (platform console home).
 * Platform-wide, REAL figures: tenant counts by status, verified-only
 * revenue, pending payments awaiting verification, registrations awaiting
 * approval, expiring subscriptions — plus the revenue chart fed by
 * api/charts.php?series=platform_revenue.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role(['super_admin']);
$pdo = db();

$one = static function (string $sql) use ($pdo) {
    return $pdo->query($sql)->fetchColumn();
};
$tenantsTotal = (int)$one('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL');
$tenantsActive = (int)$one('SELECT COUNT(*) FROM tenants WHERE status = "active" AND deleted_at IS NULL');
$tenantsPending = (int)$one('SELECT COUNT(*) FROM tenants WHERE status = "pending" AND deleted_at IS NULL');
$tenantsSuspended = (int)$one('SELECT COUNT(*) FROM tenants WHERE status = "suspended" AND deleted_at IS NULL');
$revenueTotal = (float)$one('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "verified"');
$revenueMonth = (float)$one('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "verified" AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")');
$pendingPayments = (int)$one('SELECT COUNT(*) FROM payments WHERE status = "pending"');
$pendingAmount = (float)$one('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "pending"');
$expiringSoon = (int)$one('SELECT COUNT(*) FROM subscriptions WHERE status IN ("trial","active") AND ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)');
$openTickets = (int)$one('SELECT COUNT(*) FROM support_tickets WHERE status = "open"');

$pendingApprovals = $pdo->query('SELECT id, name, email, city, country, created_at FROM tenants WHERE status = "pending" AND deleted_at IS NULL ORDER BY id DESC LIMIT 5')->fetchAll();
$recentTenants = $pdo->query(
    'SELECT t.id, t.name, t.email, t.status, t.created_at,
            (SELECT p.name FROM subscription_plans p JOIN subscriptions s ON s.plan_id = p.id WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan_name
       FROM tenants t WHERE t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 6'
)->fetchAll();
$awaiting = $pdo->query(
    'SELECT p.id, p.amount, p.method, p.created_at, t.name AS tenant_name FROM payments p LEFT JOIN tenants t ON t.id = p.tenant_id
      WHERE p.status = "pending" ORDER BY p.id DESC LIMIT 5'
)->fetchAll();

admin_header('Platform Dashboard', 'super-admin/dashboard.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0">Platform Dashboard</h4>
    <p class="text-muted small mb-0">MediStock Cloud control centre · <?= e(date('l, d F Y')) ?></p>
  </div>
  <a class="btn btn-emerald" href="<?= url('super-admin/reports.php') ?>"><i class="bi bi-graph-up-arrow"></i> Platform reports</a>
</div>

<?php if ($tenantsPending > 0): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center">
  <span><i class="bi bi-hourglass-split"></i> <strong><?= $tenantsPending ?> pharmacy registration(s)</strong> are awaiting approval.</span>
  <a class="btn btn-sm btn-warning" href="<?= url('super-admin/pharmacies.php?status=pending') ?>">Review</a>
</div>
<?php endif; ?>
<?php if ($pendingPayments > 0): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
  <span><i class="bi bi-credit-card"></i> <strong><?= $pendingPayments ?> payment(s)</strong> worth <?= money($pendingAmount) ?> are pending verification. Payments are never marked verified automatically.</span>
  <a class="btn btn-sm btn-info" href="<?= url('super-admin/payments.php?status=pending') ?>">Verify</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-1">
  <?php stat_card('bi-building', 'Pharmacies', $tenantsTotal, 'emerald', $tenantsActive . ' active · ' . $tenantsSuspended . ' suspended'); ?>
  <?php stat_card('bi-cash-stack', 'Verified revenue', money($revenueTotal), 'sky', money($revenueMonth) . ' this month'); ?>
  <?php stat_card('bi-hourglass-split', 'Pending payments', $pendingPayments, 'amber', money($pendingAmount) . ' awaiting verification'); ?>
  <?php stat_card('bi-alarm', 'Expiring ≤ 7 days', $expiringSoon, 'rose', $openTickets . ' open support tickets'); ?>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-graph-up-arrow text-emerald"></i> Verified platform revenue — last 12 months</h6>
      <div class="chart-box"><canvas data-chart-url="<?= e(url('api/charts.php?series=platform_revenue&months=12')) ?>" data-chart-type="bar"></canvas></div>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-2"><i class="bi bi-building-add text-emerald"></i> Newest pharmacies</h6>
      <?php foreach ($recentTenants as $t): ?>
        <div class="kv-row"><span><strong><?= e($t['name']) ?></strong> <small class="text-muted"><?= e($t['email']) ?> · <?= e($t['plan_name'] ?? 'No plan') ?> · <?= e(fmt_date($t['created_at'])) ?></small></span><?= status_badge($t['status']) ?></div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-lg-4">
    <?php if ($pendingApprovals): ?>
    <div class="card shadow-sm mb-3 border-warning"><div class="card-body">
      <h6 class="mb-2"><i class="bi bi-patch-check text-warning"></i> Awaiting approval</h6>
      <?php foreach ($pendingApprovals as $t): ?>
        <div class="kv-row"><span><strong><?= e($t['name']) ?></strong><br><small class="text-muted"><?= e($t['email']) ?> · <?= e(trim(($t['city'] ?? '') . ', ' . ($t['country'] ?? ''), ', ')) ?></small></span>
        <a class="btn btn-sm btn-emerald" href="<?= url('super-admin/pharmacies.php?status=pending') ?>">Review</a></div>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-2"><i class="bi bi-credit-card text-emerald"></i> Payments awaiting verification</h6>
      <?php if (!$awaiting): ?><p class="text-muted small mb-0">Nothing pending.</p><?php endif; ?>
      <?php foreach ($awaiting as $p): ?>
        <div class="kv-row"><span><?= e($p['tenant_name'] ?? '—') ?><br><small class="text-muted"><?= e(ucfirst($p['method'])) ?> · <?= e(fmt_date($p['created_at'], 'd M H:i')) ?></small></span><strong><?= money((float)$p['amount']) ?></strong></div>
      <?php endforeach; ?>
      <a class="btn btn-outline-secondary btn-sm mt-2" href="<?= url('super-admin/payments.php?status=pending') ?>">Open payments</a>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
