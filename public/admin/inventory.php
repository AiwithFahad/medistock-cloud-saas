<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
$tid = tenant_id();
$pdo = db();

$branchId = (int)input('branch_id', 0);
$q = trim((string)input('q', ''));

// CSV export of the full inventory position.
if (input('export') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Medicine', 'SKU', 'Barcode', 'Batches', 'Available (in-date)', 'Expired units', 'Stock value (cost)']);
    $rows = $pdo->prepare(
        'SELECT m.name, m.sku, m.barcode,
                COUNT(b.id) AS batches,
                COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS available,
                COALESCE(SUM(CASE WHEN b.expiry_date < CURDATE() THEN b.quantity ELSE 0 END), 0) AS expired,
                COALESCE(SUM(b.quantity * b.purchase_price), 0) AS value_cost
           FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id AND b.tenant_id = m.tenant_id
          WHERE m.tenant_id = ? AND m.deleted_at IS NULL GROUP BY m.id ORDER BY m.name'
    );
    $rows->execute([$tid]);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [$r['name'], $r['sku'], $r['barcode'], $r['batches'], $r['available'], $r['expired'], number_format((float)$r['value_cost'], 2, '.', '')]);
    }
    fclose($out);
    exit;
}

$branches = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$branches->execute([$tid]);
$branchList = $branches->fetchAll();

$sql = 'SELECT m.id, m.name, m.sku, m.barcode, m.unit, m.reorder_level,
               COUNT(b.id) AS batch_count,
               COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS available,
               COALESCE(SUM(CASE WHEN b.expiry_date < CURDATE() THEN b.quantity ELSE 0 END), 0) AS expired_units,
               COALESCE(SUM(b.quantity * b.purchase_price), 0) AS value_cost,
               MIN(CASE WHEN b.quantity > 0 AND b.expiry_date >= CURDATE() THEN b.expiry_date END) AS next_expiry
          FROM medicines m
          LEFT JOIN batches b ON b.medicine_id = m.id AND b.tenant_id = m.tenant_id' . ($branchId ? ' AND b.branch_id = ' . $branchId : '') . '
         WHERE m.tenant_id = ? AND m.deleted_at IS NULL';
$params = [$tid];
if ($q !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.sku LIKE ? OR m.barcode LIKE ?)';
    $params = [$tid, "%$q%", "%$q%", "%$q%"];
}
$sql .= ' GROUP BY m.id ORDER BY m.name LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalValue = array_sum(array_map(static fn($r) => (float)$r['value_cost'], $rows));

admin_header('Inventory Overview', 'admin/inventory.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-boxes text-emerald"></i> Inventory Overview</h4>
  <div class="d-flex gap-2">
    <form class="d-flex gap-1" method="get">
      <select class="form-select form-select-sm" name="branch_id" onchange="this.form.submit()">
        <option value="0">All branches</option>
        <?php foreach ($branchList as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select>
      <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search medicine, SKU, barcode…">
      <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i></button>
    </form>
    <a class="btn btn-outline-emerald btn-sm" href="inventory.php?export=csv"><i class="bi bi-download"></i> Export CSV</a>
  </div>
</div>
<div class="alert alert-light border small">Total stock value at cost (shown rows): <strong><?= money($totalValue) ?></strong> · Available units count only in-date batches; expired units are shown separately and cannot be sold.</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Medicine</th><th>SKU / Barcode</th><th>Batches</th><th>Next expiry</th><th class="text-end">Available</th><th class="text-end">Expired</th><th class="text-end">Value (cost)</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong></td>
      <td class="small"><?= e($r['sku'] ?? '—') ?><br><span class="text-muted"><?= e($r['barcode'] ?? '') ?></span></td>
      <td><?= (int)$r['batch_count'] ?></td>
      <td><?= $r['next_expiry'] ? fmt_date($r['next_expiry']) : '—' ?></td>
      <td class="text-end"><strong><?= number_format((int)$r['available']) ?></strong> <?= e($r['unit']) ?></td>
      <td class="text-end text-danger"><?= (int)$r['expired_units'] > 0 ? number_format((int)$r['expired_units']) : '—' ?></td>
      <td class="text-end"><?= money($r['value_cost']) ?></td>
      <td><?php if ((int)$r['available'] <= (int)$r['reorder_level']): ?><span class="badge bg-warning text-dark">Low stock</span><?php else: ?><span class="badge bg-success">In stock</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No medicines found.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php admin_footer(); ?>
