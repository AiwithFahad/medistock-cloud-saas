<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
$tid = tenant_id();
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT m.id, m.name, m.sku, m.reorder_level, m.unit,
            COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS available,
            (SELECT s.name FROM batches bb LEFT JOIN suppliers s ON s.id = bb.supplier_id
              WHERE bb.medicine_id = m.id AND bb.tenant_id = m.tenant_id ORDER BY bb.id DESC LIMIT 1) AS last_supplier
       FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id AND b.tenant_id = m.tenant_id
      WHERE m.tenant_id = ? AND m.deleted_at IS NULL
      GROUP BY m.id
     HAVING available <= m.reorder_level
      ORDER BY available ASC'
);
$stmt->execute([$tid]);
$rows = $stmt->fetchAll();

admin_header('Low Stock Alerts', 'admin/lowstock.php');
?>
<h4 class="mb-3"><i class="bi bi-exclamation-triangle text-emerald"></i> Low Stock Alerts <span class="badge bg-warning text-dark"><?= count($rows) ?></span></h4>
<p class="text-muted">Medicines whose in-date available quantity is at or below their reorder level. Create a purchase to restock — receiving stock clears the alert automatically.</p>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Medicine</th><th>SKU</th><th class="text-end">Available</th><th class="text-end">Reorder level</th><th>Last supplier</th><th class="text-end">Action</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong></td>
      <td><?= e($r['sku'] ?? '—') ?></td>
      <td class="text-end"><span class="badge bg-<?= (int)$r['available'] === 0 ? 'danger' : 'warning text-dark' ?>"><?= number_format((int)$r['available']) ?> <?= e($r['unit']) ?></span></td>
      <td class="text-end"><?= number_format((int)$r['reorder_level']) ?></td>
      <td><?= e($r['last_supplier'] ?? '—') ?></td>
      <td class="text-end"><a class="btn btn-sm btn-emerald" href="<?= url('admin/purchases.php') ?>?action=new"><i class="bi bi-bag-plus"></i> Restock</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">All medicines are above their reorder levels.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php admin_footer(); ?>
