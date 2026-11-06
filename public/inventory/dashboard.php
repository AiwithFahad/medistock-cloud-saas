<?php
/**
 * MediStock Cloud — Inventory Manager dashboard.
 * Current stock + valuation, receiving queue (open purchase orders),
 * pending transfers, expiry + low-stock alerts, reorder suggestions and
 * stock movement trends — all live from the database / JSON APIs.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['inventory_manager', 'admin', 'owner']);
$tid = tenant_id();
$pdo = db();

$one = static function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$units = (int)$one('SELECT COALESCE(SUM(quantity), 0) FROM batches WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]);
$value = (float)$one('SELECT COALESCE(SUM(quantity * purchase_price), 0) FROM batches WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]);
$openPos = (int)$one('SELECT COUNT(*) FROM purchase_orders WHERE tenant_id = ? AND status IN ("draft","ordered","partially_received") AND deleted_at IS NULL', [$tid]);
$pendingTransfers = (int)$one('SELECT COUNT(*) FROM stock_transfers WHERE tenant_id = ? AND status IN ("pending","in_transit")', [$tid]);
$expired = (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date < CURDATE() AND quantity > 0 AND deleted_at IS NULL', [$tid]);
$expiring30 = (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND quantity > 0 AND deleted_at IS NULL', [$tid]);

$reorder = $pdo->prepare(
    'SELECT m.id, m.name, m.strength, m.reorder_level, s.name AS supplier_name,
            COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS available
       FROM medicines m
       LEFT JOIN batches b ON b.medicine_id = m.id AND b.tenant_id = m.tenant_id AND b.deleted_at IS NULL
       LEFT JOIN suppliers s ON s.id = m.default_supplier_id
      WHERE m.tenant_id = ? AND m.deleted_at IS NULL AND m.status = "active"
      GROUP BY m.id HAVING available <= m.reorder_level ORDER BY available ASC LIMIT 10'
);
$reorder->execute([$tid]);
$reorder = $reorder->fetchAll();

$recentMoves = $pdo->prepare(
    'SELECT sm.*, m.name, b.batch_number, br.name AS branch_name FROM stock_movements sm
       JOIN medicines m ON m.id = sm.medicine_id
       LEFT JOIN batches b ON b.id = sm.batch_id LEFT JOIN branches br ON br.id = sm.branch_id
      WHERE sm.tenant_id = ? ORDER BY sm.id DESC LIMIT 8'
);
$recentMoves->execute([$tid]);
$recentMoves = $recentMoves->fetchAll();

admin_header('Inventory Dashboard', 'inventory/dashboard.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0">Welcome, <?= e($user['name']) ?></h4>
    <p class="text-muted small mb-0">Stock control centre — receiving, transfers, alerts and valuation</p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= url('inventory/receiving.php') ?>"><i class="bi bi-box-arrow-in-down"></i> Receive stock</a>
    <a class="btn btn-emerald" href="<?= url('inventory/purchase-orders.php') ?>"><i class="bi bi-clipboard2-check"></i> Purchase orders</a>
  </div>
</div>

<?php if ($expired > 0 || $expiring30 > 0): ?>
<div class="alert <?= $expired > 0 ? 'alert-danger' : 'alert-warning' ?> d-flex justify-content-between align-items-center">
  <span><i class="bi bi-bell"></i> <?= $expired > 0 ? '<strong>' . $expired . ' expired batch(es)</strong> with stock on hand. ' : '' ?><?= $expiring30 > 0 ? $expiring30 . ' batch(es) expire within 30 days.' : '' ?></span>
  <a class="btn btn-sm btn-dark" href="<?= url('inventory/alerts.php') ?>">Open alerts</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-1">
  <?php stat_card('bi-boxes', 'Units in stock', number_format($units), 'emerald', 'all branches'); ?>
  <?php stat_card('bi-cash-stack', 'Inventory value (cost)', money($value), 'sky'); ?>
  <?php stat_card('bi-clipboard2-check', 'Open purchase orders', $openPos, 'amber', 'awaiting delivery'); ?>
  <?php stat_card('bi-arrow-left-right', 'Pending transfers', $pendingTransfers, 'rose', 'not yet completed'); ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-activity text-emerald"></i> Stock movement trends (14 days)</h6>
      <div class="chart-box"><canvas data-chart-url="<?= e(url('api/charts.php?series=movements&days=14')) ?>" data-chart-type="bar"></canvas></div>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-clock-history text-emerald"></i> Latest movements</h6>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Medicine</th><th>Branch</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($recentMoves as $mv): ?>
          <tr><td class="small"><?= e($mv['name']) ?> <span class="text-muted"><?= e($mv['batch_number'] ?? '') ?></span></td>
          <td class="small"><?= e($mv['branch_name'] ?? '') ?></td><td><?= status_badge($mv['type']) ?></td>
          <td class="text-end <?= (int)$mv['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int)$mv['quantity'] > 0 ? '+' : '' ?><?= (int)$mv['quantity'] ?></td>
          <td class="text-end"><?= (int)$mv['balance_after'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-calendar-x text-emerald"></i> Expiry statistics</h6>
      <div class="chart-box-sm"><canvas data-chart-url="<?= e(url('api/charts.php?series=expiry')) ?>" data-chart-type="bar"></canvas></div>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0"><i class="bi bi-cart-plus text-emerald"></i> Reorder suggestions</h6>
        <a class="btn btn-sm btn-outline-secondary" href="<?= url('inventory/alerts.php') ?>">All alerts</a>
      </div>
      <?php if (!$reorder): ?><p class="text-muted small mb-0">Nothing at or below its reorder level.</p><?php endif; ?>
      <?php foreach ($reorder as $r): $suggest = max((int)$r['reorder_level'] * 2 - (int)$r['available'], (int)$r['reorder_level']); ?>
        <div class="kv-row">
          <span><strong><?= e($r['name']) ?></strong> <?= e($r['strength'] ?? '') ?><br><small class="text-muted"><?= (int)$r['available'] ?> left · reorder at <?= (int)$r['reorder_level'] ?><?= $r['supplier_name'] ? ' · ' . e($r['supplier_name']) : '' ?></small></span>
          <span class="badge text-bg-warning">Order ~<?= $suggest ?></span>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
