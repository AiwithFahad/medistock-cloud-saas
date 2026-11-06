<?php
/**
 * MediStock Cloud — Pharmacist dashboard.
 * Prescription queue, expiry + low-stock warnings, today's dispensing
 * activity and stock availability — all live queries, tenant-scoped.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['pharmacist', 'admin', 'owner']);
$tid = tenant_id();
$pdo = db();

$one = static function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$pendingRx = (int)$one('SELECT COUNT(*) FROM prescriptions WHERE tenant_id = ? AND status IN ("pending","partially_dispensed") AND deleted_at IS NULL', [$tid]);
$dispensedToday = (int)$one('SELECT COUNT(*) FROM prescriptions WHERE tenant_id = ? AND status = "dispensed" AND DATE(dispensed_at) = CURDATE()', [$tid]);
$expiring30 = (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND quantity > 0 AND deleted_at IS NULL', [$tid]);
$expired = (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date < CURDATE() AND quantity > 0 AND deleted_at IS NULL', [$tid]);

$rxQueue = $pdo->prepare(
    'SELECT p.*, c.name AS customer_name, (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = p.id) AS line_count
       FROM prescriptions p LEFT JOIN customers c ON c.id = p.customer_id
      WHERE p.tenant_id = ? AND p.status IN ("pending","partially_dispensed") AND p.deleted_at IS NULL
      ORDER BY p.prescribed_at ASC LIMIT 6'
);
$rxQueue->execute([$tid]);
$rxQueue = $rxQueue->fetchAll();

$expiryList = $pdo->prepare(
    'SELECT b.batch_number, b.expiry_date, b.quantity, m.name FROM batches b JOIN medicines m ON m.id = b.medicine_id
      WHERE b.tenant_id = ? AND b.quantity > 0 AND b.deleted_at IS NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      ORDER BY b.expiry_date ASC LIMIT 6'
);
$expiryList->execute([$tid]);
$expiryList = $expiryList->fetchAll();

$recentDispensing = $pdo->prepare(
    'SELECT p.prescription_number, p.patient_name, p.dispensed_at, u.name AS dispensed_by_name
       FROM prescriptions p LEFT JOIN users u ON u.id = p.dispensed_by
      WHERE p.tenant_id = ? AND p.status = "dispensed" AND p.dispensed_at IS NOT NULL
      ORDER BY p.dispensed_at DESC LIMIT 6'
);
$recentDispensing->execute([$tid]);
$recentDispensing = $recentDispensing->fetchAll();

admin_header('Pharmacist Dashboard', 'pharmacist/dashboard.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0">Welcome, <?= e($user['name']) ?></h4>
    <p class="text-muted small mb-0">Prescriptions, dispensing and stock safety — <?= e(date('l, d F Y')) ?></p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= url('pharmacist/medicines.php') ?>"><i class="bi bi-search-heart"></i> Medicine search</a>
    <a class="btn btn-emerald" href="<?= url('pharmacist/prescriptions.php') ?>"><i class="bi bi-file-earmark-medical"></i> Prescriptions</a>
  </div>
</div>

<?php if ($expired > 0): ?>
<div class="alert alert-danger d-flex justify-content-between align-items-center">
  <span><i class="bi bi-exclamation-octagon"></i> <strong><?= $expired ?> expired batch<?= $expired === 1 ? '' : 'es' ?></strong> still hold stock. Expired stock can never be sold — review and write it off.</span>
  <a class="btn btn-sm btn-danger" href="<?= url('admin/expiry.php') ?>">Review</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-1">
  <?php stat_card('bi-file-earmark-medical', 'Prescriptions waiting', $pendingRx, 'emerald', 'pending / partial'); ?>
  <?php stat_card('bi-clipboard-check', 'Dispensed today', $dispensedToday, 'sky'); ?>
  <?php stat_card('bi-calendar-x', 'Expiring ≤ 30 days', $expiring30, 'amber', 'batches with stock'); ?>
  <?php stat_card('bi-exclamation-octagon', 'Expired batches', $expired, 'rose', 'never sellable'); ?>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0"><i class="bi bi-hourglass-split text-emerald"></i> Prescription queue</h6>
        <a class="btn btn-sm btn-outline-secondary" href="<?= url('pharmacist/prescriptions.php') ?>">Manage</a>
      </div>
      <?php if (!$rxQueue): ?><p class="text-muted small mb-0">No prescriptions waiting. </p><?php endif; ?>
      <?php foreach ($rxQueue as $rx): ?>
        <div class="kv-row">
          <span><strong><?= e($rx['prescription_number']) ?></strong> · <?= e($rx['patient_name'] ?? $rx['customer_name'] ?? '') ?><br>
          <small class="text-muted"><?= e($rx['doctor_name']) ?> · <?= (int)$rx['line_count'] ?> item(s) · <?= e(fmt_date($rx['prescribed_at'])) ?></small></span>
          <?= status_badge($rx['status']) ?>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-2"><i class="bi bi-calendar-x text-emerald"></i> Expiry warnings (next 30 days + expired)</h6>
      <?php if (!$expiryList): ?><p class="text-muted small mb-0">Nothing expiring soon.</p><?php endif; ?>
      <?php foreach ($expiryList as $b): $past = strtotime($b['expiry_date']) < strtotime(date('Y-m-d')); ?>
        <div class="kv-row">
          <span><?= e($b['name']) ?> <small class="text-muted">batch <?= e($b['batch_number']) ?> · <?= (int)$b['quantity'] ?> units</small></span>
          <span class="badge <?= $past ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $past ? 'EXPIRED' : 'exp' ?> <?= e(fmt_date($b['expiry_date'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-2"><i class="bi bi-clipboard-check text-emerald"></i> Recent dispensing</h6>
      <?php if (!$recentDispensing): ?><p class="text-muted small mb-0">No dispensing recorded yet.</p><?php endif; ?>
      <?php foreach ($recentDispensing as $d): ?>
        <div class="kv-row">
          <span><strong><?= e($d['prescription_number']) ?></strong> · <?= e($d['patient_name'] ?? '') ?></span>
          <small class="text-muted"><?= e($d['dispensed_by_name'] ?? '') ?> · <?= e(fmt_date($d['dispensed_at'], 'd M H:i')) ?></small>
        </div>
      <?php endforeach; ?>
      <a class="btn btn-outline-secondary btn-sm mt-2" href="<?= url('pharmacist/dispensing.php') ?>">All dispensing records</a>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
