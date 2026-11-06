<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('stock.adjust');
$tid = tenant_id();
$pdo = db();

if (is_post()) {
    csrf_check();
    try {
        $batchId = (int)input('batch_id', 0);
        $direction = (string)input('direction', 'decrease');
        $qty = abs((int)input('quantity', 0));
        $delta = $direction === 'increase' ? $qty : -$qty;
        $reason = trim((string)input('reason', ''));
        if ($batchId <= 0 || $qty <= 0 || $reason === '') {
            throw new RuntimeException('Choose a batch, a quantity and a reason.');
        }
        StockService::adjust($tid, $batchId, $delta, $reason, (int)$user['id']);
        flash('success', 'Stock adjustment recorded and written to the movement ledger.');
        redirect('admin/adjustments.php');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('admin/adjustments.php');
    }
}

$batches = $pdo->prepare(
    'SELECT b.id, b.batch_number, b.quantity, b.expiry_date, m.name AS medicine, br.name AS branch
       FROM batches b JOIN medicines m ON m.id = b.medicine_id JOIN branches br ON br.id = b.branch_id
      WHERE b.tenant_id = ? ORDER BY m.name, b.expiry_date'
);
$batches->execute([$tid]);
$batchList = $batches->fetchAll();

$history = $pdo->prepare(
    'SELECT sm.*, m.name AS medicine, u.name AS actor FROM stock_movements sm
       JOIN medicines m ON m.id = sm.medicine_id LEFT JOIN users u ON u.id = sm.created_by
      WHERE sm.tenant_id = ? AND sm.type = "adjustment" ORDER BY sm.id DESC LIMIT 30'
);
$history->execute([$tid]);
$rows = $history->fetchAll();
$allowNegative = StockService::tenantSetting($tid, 'allow_negative_stock', '0') === '1';

admin_header('Stock Adjustments', 'admin/adjustments.php');
?>
<h4 class="mb-3"><i class="bi bi-sliders text-emerald"></i> Stock Adjustments</h4>
<?php if (!$allowNegative): ?>
  <div class="alert alert-info small"><i class="bi bi-info-circle"></i> Negative-stock policy is <strong>off</strong>: adjustments cannot drive a batch below zero. An owner can enable the authorized policy in Settings.</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">New adjustment</h6>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Batch *</label>
        <select class="form-select" name="batch_id" required>
          <option value="">— Select batch —</option>
          <?php foreach ($batchList as $b): ?>
            <option value="<?= (int)$b['id'] ?>"><?= e($b['medicine']) ?> · <?= e($b['batch_number']) ?> · <?= e($b['branch']) ?> · exp <?= fmt_date($b['expiry_date']) ?> · qty <?= (int)$b['quantity'] ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label">Direction *</label>
          <select class="form-select" name="direction"><option value="decrease">Decrease (−)</option><option value="increase">Increase (+)</option></select></div>
        <div class="col-6"><label class="form-label">Quantity *</label><input class="form-control" type="number" name="quantity" min="1" required></div>
      </div>
      <div class="mb-3"><label class="form-label">Reason *</label>
        <select class="form-select" name="reason" required>
          <option value="">— Select reason —</option>
          <option>Damaged stock</option><option>Expired write-off</option><option>Breakage / spillage</option>
          <option>Stock count correction</option><option>Theft / shrinkage</option><option>Supplier return</option><option>Other (see activity log)</option>
        </select></div>
      <button class="btn btn-emerald w-100" type="submit"><i class="bi bi-check-lg"></i> Record adjustment (transactional)</button>
    </form>
  </div></div></div>
  <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Recent adjustments</h6>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>When</th><th>Medicine</th><th>Change</th><th>Balance</th><th>Reason</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td><?= fmt_date($r['created_at'], 'd M H:i') ?></td><td><?= e($r['medicine']) ?></td>
        <td><span class="badge bg-<?= (int)$r['quantity'] < 0 ? 'danger' : 'success' ?>"><?= (int)$r['quantity'] > 0 ? '+' : '' ?><?= (int)$r['quantity'] ?></span></td>
        <td><?= (int)$r['balance_after'] ?></td><td><?= e($r['note'] ?? '') ?></td><td class="small"><?= e($r['actor'] ?? '') ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">No adjustments yet.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div></div></div>
</div>
<?php admin_footer(); ?>
