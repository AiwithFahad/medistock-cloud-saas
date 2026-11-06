<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
$tid = tenant_id();
$pdo = db();

$days = (int)input('days', 90);
if (!in_array($days, [30, 60, 90], true)) {
    $days = 90;
}
$expiredStmt = $pdo->prepare(
    'SELECT b.*, m.name AS medicine, br.name AS branch, (b.quantity * b.purchase_price) AS loss_value
       FROM batches b JOIN medicines m ON m.id = b.medicine_id JOIN branches br ON br.id = b.branch_id
      WHERE b.tenant_id = ? AND b.quantity > 0 AND b.expiry_date < CURDATE() ORDER BY b.expiry_date'
);
$expiredStmt->execute([$tid]);
$expired = $expiredStmt->fetchAll();

$soonStmt = $pdo->prepare(
    'SELECT b.*, m.name AS medicine, br.name AS branch,
            DATEDIFF(b.expiry_date, CURDATE()) AS days_left
       FROM batches b JOIN medicines m ON m.id = b.medicine_id JOIN branches br ON br.id = b.branch_id
      WHERE b.tenant_id = ? AND b.quantity > 0 AND b.expiry_date >= CURDATE()
        AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ' . $days . ' DAY)
      ORDER BY b.expiry_date'
);
$soonStmt->execute([$tid]);
$soon = $soonStmt->fetchAll();
$lossTotal = array_sum(array_map(static fn($r) => (float)$r['loss_value'], $expired));

admin_header('Expiry Management', 'admin/expiry.php');
?>
<h4 class="mb-3"><i class="bi bi-calendar-x text-emerald"></i> Expiry Management</h4>
<div class="row g-3 mb-4">
  <?php stat_card('bi-calendar-x', 'Expired batches with stock', count($expired), 'rose', 'Write-off value ' . money($lossTotal)); ?>
  <?php stat_card('bi-hourglass-split', 'Expiring within ' . $days . ' days', count($soon), 'amber'); ?>
  <?php stat_card('bi-shield-check', 'Expired stock in POS', 'Blocked', 'emerald', 'Expiry is validated at sale time'); ?>
  <?php stat_card('bi-arrow-repeat', 'FEFO selling', 'On', 'sky', 'Earliest expiry sells first'); ?>
</div>

<div class="card shadow-sm mb-4"><div class="card-body">
  <h6 class="fw-bold text-danger mb-3"><i class="bi bi-exclamation-octagon"></i> Expired batches (cannot be sold)</h6>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Medicine</th><th>Batch</th><th>Branch</th><th>Expired</th><th class="text-end">Qty</th><th class="text-end">Value at risk</th><th class="text-end">Action</th></tr></thead>
    <tbody>
    <?php foreach ($expired as $b): ?>
      <tr><td><?= e($b['medicine']) ?></td><td><?= e($b['batch_number']) ?></td><td><?= e($b['branch']) ?></td>
      <td><span class="badge bg-danger"><?= fmt_date($b['expiry_date']) ?></span></td>
      <td class="text-end"><?= number_format((int)$b['quantity']) ?></td><td class="text-end"><?= money($b['loss_value']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-danger" href="<?= url('admin/adjustments.php') ?>">Write off</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$expired): ?><tr><td colspan="7" class="text-center text-muted py-3">No expired stock. Excellent.</td></tr><?php endif; ?>
    </tbody></table></div>
</div></div>

<div class="card shadow-sm"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold text-navy mb-0">Expiring soon</h6>
    <div class="btn-group btn-group-sm">
      <?php foreach ([30, 60, 90] as $d): ?><a class="btn btn-outline-secondary <?= $days === $d ? 'active' : '' ?>" href="expiry.php?days=<?= $d ?>"><?= $d ?> days</a><?php endforeach; ?>
    </div>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Medicine</th><th>Batch</th><th>Branch</th><th>Expiry</th><th>Days left</th><th class="text-end">Qty</th></tr></thead>
    <tbody>
    <?php foreach ($soon as $b): ?>
      <tr><td><?= e($b['medicine']) ?></td><td><?= e($b['batch_number']) ?></td><td><?= e($b['branch']) ?></td>
      <td><?= fmt_date($b['expiry_date']) ?></td>
      <td><span class="badge bg-<?= (int)$b['days_left'] <= 30 ? 'danger' : 'warning text-dark' ?>"><?= (int)$b['days_left'] ?> days</span></td>
      <td class="text-end"><?= number_format((int)$b['quantity']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$soon): ?><tr><td colspan="6" class="text-center text-muted py-3">Nothing expiring within <?= $days ?> days.</td></tr><?php endif; ?>
    </tbody></table></div>
</div></div>
<?php admin_footer(); ?>
