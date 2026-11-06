<?php
/**
 * MediStock Cloud — Stock alerts (inventory workspace).
 * Automatic expiry buckets (expired / ≤30 / ≤60 / ≤90 days), expired
 * write-off through the audited StockService::adjust() (respects the
 * stock.adjust permission and the negative-stock policy), and low-stock /
 * reorder alerts.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['inventory_manager', 'admin', 'owner']);
$tid = tenant_id();
$pdo = db();

if (is_post()) {
    csrf_check();
    try {
        $batchId = (int)input('batch_id', 0);
        $stmt = $pdo->prepare('SELECT quantity, expiry_date FROM batches WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$batchId, $tid]);
        $batch = $stmt->fetch();
        if (!$batch) {
            throw new RuntimeException('Batch not found.');
        }
        if (strtotime((string)$batch['expiry_date']) >= strtotime(date('Y-m-d'))) {
            throw new RuntimeException('Write-off here is only for EXPIRED batches.');
        }
        StockService::adjust($tid, $batchId, -(int)$batch['quantity'], 'Expired stock write-off', (int)$user['id']);
        flash('success', 'Expired batch written off — movement recorded in the audit ledger.');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('inventory/alerts.php');
}

$buckets = [
    'Expired — write off' => ['expiry_date < CURDATE()', 'danger'],
    'Expiring ≤ 30 days' => ['expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)', 'warning'],
    'Expiring 31–60 days' => ['expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)', 'info'],
    'Expiring 61–90 days' => ['expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 61 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)', 'secondary'],
];
$bucketRows = [];
foreach ($buckets as $label => [$cond, $tone]) {
    $stmt = $pdo->prepare(
        "SELECT b.id, b.batch_number, b.expiry_date, b.quantity, b.purchase_price, m.name, br.name AS branch_name
           FROM batches b JOIN medicines m ON m.id = b.medicine_id JOIN branches br ON br.id = b.branch_id
          WHERE b.tenant_id = ? AND b.quantity > 0 AND b.deleted_at IS NULL AND {$cond}
          ORDER BY b.expiry_date ASC LIMIT 25"
    );
    $stmt->execute([$tid]);
    $bucketRows[$label] = [$tone, $stmt->fetchAll()];
}

$low = $pdo->prepare(
    'SELECT m.name, m.strength, m.reorder_level, s.name AS supplier_name,
            COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS available
       FROM medicines m
       LEFT JOIN batches b ON b.medicine_id = m.id AND b.tenant_id = m.tenant_id AND b.deleted_at IS NULL
       LEFT JOIN suppliers s ON s.id = m.default_supplier_id
      WHERE m.tenant_id = ? AND m.deleted_at IS NULL AND m.status = "active"
      GROUP BY m.id HAVING available <= m.reorder_level ORDER BY available ASC'
);
$low->execute([$tid]);
$low = $low->fetchAll();

admin_header('Stock Alerts', 'inventory/alerts.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-bell text-emerald"></i> Stock Alerts</h4>
  <a class="btn btn-outline-secondary btn-sm" href="<?= url('inventory/purchase-orders.php') ?>"><i class="bi bi-clipboard2-check"></i> Create purchase order</a>
</div>

<?php foreach ($bucketRows as $label => [$tone, $rows]): ?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <h6 class="mb-3"><span class="badge text-bg-<?= e($tone) ?>"><?= e($label) ?></span> <span class="text-muted small"><?= count($rows) ?> batch(es) with stock</span></h6>
  <?php if (!$rows): ?><p class="text-muted small mb-0">None.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Medicine</th><th>Batch</th><th>Branch</th><th>Expiry</th><th class="text-end">Qty</th><th class="text-end">Value at risk</th><?php if ($tone === 'danger'): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $b): ?>
      <tr><td><?= e($b['name']) ?></td><td><?= e($b['batch_number']) ?></td><td class="small"><?= e($b['branch_name']) ?></td>
      <td class="small"><?= e(fmt_date($b['expiry_date'])) ?></td><td class="text-end"><?= (int)$b['quantity'] ?></td>
      <td class="text-end"><?= money((float)$b['quantity'] * (float)$b['purchase_price']) ?></td>
      <?php if ($tone === 'danger'): ?><td class="text-end">
        <?php if (can('stock.adjust')): ?>
        <form method="post" data-confirm="Write off this expired batch? The quantity goes to zero and the movement is recorded permanently."><?= csrf_field() ?><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i> Write off</button></form>
        <?php endif; ?>
      </td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>
<?php endforeach; ?>

<div class="card shadow-sm"><div class="card-body">
  <h6 class="mb-3"><i class="bi bi-exclamation-triangle text-warning"></i> Low stock & reorder suggestions</h6>
  <?php if (!$low): ?><p class="text-muted small mb-0">No medicine is at or below its reorder level.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Medicine</th><th class="text-end">Available</th><th class="text-end">Reorder level</th><th class="text-end">Suggested order</th><th>Default supplier</th></tr></thead>
    <tbody>
    <?php foreach ($low as $r): $suggest = max((int)$r['reorder_level'] * 2 - (int)$r['available'], (int)$r['reorder_level']); ?>
      <tr><td><strong><?= e($r['name']) ?></strong> <?= e($r['strength'] ?? '') ?></td>
      <td class="text-end"><span class="badge text-bg-danger"><?= (int)$r['available'] ?></span></td>
      <td class="text-end"><?= (int)$r['reorder_level'] ?></td>
      <td class="text-end"><strong><?= $suggest ?></strong></td>
      <td class="small"><?= e($r['supplier_name'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>
<?php admin_footer(); ?>
