<?php
/**
 * MediStock Cloud — Super Admin: platform reports.
 * Verified revenue by month, tenant growth, plan distribution, top
 * pharmacies by verified payments, subscription status mix — and a CSV
 * export of all payments. Revenue figures count VERIFIED payments only.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

// CSV export runs before any HTML output.
if ((string)input('export', '') === 'payments') {
    $rows = $pdo->query(
        'SELECT p.id, t.name AS pharmacy, p.method, p.amount, p.currency, p.status, p.reference, p.verified_by, p.created_at
           FROM payments p LEFT JOIN tenants t ON t.id = p.tenant_id ORDER BY p.id DESC'
    )->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="medistock-payments.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Pharmacy', 'Method', 'Amount', 'Currency', 'Status', 'Reference', 'Verified by', 'Date']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['pharmacy'], $r['method'], $r['amount'], $r['currency'], $r['status'], $r['reference'], $r['verified_by'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

$revenue = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n, SUM(amount) AS total
       FROM payments WHERE status = 'verified' GROUP BY ym ORDER BY ym DESC LIMIT 12"
)->fetchAll();
$growth = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n FROM tenants WHERE deleted_at IS NULL GROUP BY ym ORDER BY ym DESC LIMIT 12"
)->fetchAll();
$plans = $pdo->query(
    'SELECT p.name, COUNT(DISTINCT s.tenant_id) AS tenants FROM subscription_plans p
       LEFT JOIN subscriptions s ON s.plan_id = p.id AND s.status IN ("trial","active","grace")
      GROUP BY p.id ORDER BY p.sort_order'
)->fetchAll();
$top = $pdo->query(
    'SELECT t.name, COUNT(*) AS payments, SUM(p.amount) AS total FROM payments p JOIN tenants t ON t.id = p.tenant_id
      WHERE p.status = "verified" GROUP BY p.tenant_id ORDER BY total DESC LIMIT 10'
)->fetchAll();
$mix = $pdo->query('SELECT status, COUNT(*) AS n FROM subscriptions GROUP BY status')->fetchAll();
$mrr = (float)$pdo->query(
    'SELECT COALESCE(SUM(CASE WHEN s.billing_cycle = "yearly" THEN p.yearly_price / 12 ELSE p.monthly_price END), 0)
       FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.status = "active"'
)->fetchColumn();

admin_header('Platform Reports', 'super-admin/reports.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-graph-up-arrow text-emerald"></i> Platform Reports</h4>
  <a class="btn btn-outline-secondary btn-sm" href="reports.php?export=payments"><i class="bi bi-download"></i> Export payments CSV</a>
</div>

<div class="row g-3 mb-1">
  <?php stat_card('bi-arrow-repeat', 'MRR (active subs)', money($mrr), 'emerald', 'yearly plans counted monthly'); ?>
  <?php stat_card('bi-building', 'Pharmacies', (int)$pdo->query('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL')->fetchColumn(), 'sky'); ?>
  <?php stat_card('bi-people', 'Platform users', (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn(), 'amber'); ?>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3">Verified revenue by month</h6>
      <?php if (!$revenue): ?><p class="text-muted small mb-0">No verified payments yet.</p><?php endif; ?>
      <?php foreach ($revenue as $r): ?>
        <div class="kv-row"><span><?= e(date('F Y', strtotime($r['ym'] . '-01'))) ?> <small class="text-muted">(<?= (int)$r['n'] ?> payments)</small></span><strong><?= money((float)$r['total']) ?></strong></div>
      <?php endforeach; ?>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3">Tenant registrations by month</h6>
      <?php foreach ($growth as $r): ?>
        <div class="kv-row"><span><?= e(date('F Y', strtotime($r['ym'] . '-01'))) ?></span><strong><?= (int)$r['n'] ?></strong></div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3">Plan distribution (current subscriptions)</h6>
      <?php foreach ($plans as $p): ?>
        <div class="kv-row"><span><?= e($p['name']) ?></span><strong><?= (int)$p['tenants'] ?> pharmacies</strong></div>
      <?php endforeach; ?>
      <hr>
      <h6 class="mb-2">Subscription status mix</h6>
      <?php foreach ($mix as $m): ?>
        <div class="kv-row"><span><?= status_badge($m['status']) ?></span><strong><?= (int)$m['n'] ?></strong></div>
      <?php endforeach; ?>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3">Top pharmacies by verified payments</h6>
      <?php if (!$top): ?><p class="text-muted small mb-0">No verified payments yet.</p><?php endif; ?>
      <?php foreach ($top as $t): ?>
        <div class="kv-row"><span><?= e($t['name']) ?> <small class="text-muted">(<?= (int)$t['payments'] ?> payments)</small></span><strong><?= money((float)$t['total']) ?></strong></div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
