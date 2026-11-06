<?php
/**
 * MediStock Cloud — Supplier records (inventory workspace).
 * Per-supplier purchasing totals and the LIVE outstanding balance
 * (purchases − payments − purchase returns, via StockService). Supplier
 * profile editing lives in the admin Suppliers module; payments are
 * recorded on the Supplier Payments page.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['inventory_manager', 'admin', 'owner']);
$tid = tenant_id();
$pdo = db();

$q = trim((string)input('q', ''));
$where = 's.tenant_id = ? AND s.deleted_at IS NULL';
$params = [$tid];
if ($q !== '') {
    $where .= ' AND (s.name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$stmt = $pdo->prepare(
    "SELECT s.*,
            (SELECT COALESCE(SUM(total_amount), 0) FROM purchases p WHERE p.supplier_id = s.id AND p.tenant_id = s.tenant_id AND p.status = 'received') AS purchased,
            (SELECT COALESCE(SUM(amount), 0) FROM supplier_payments sp WHERE sp.supplier_id = s.id AND sp.tenant_id = s.tenant_id) AS paid,
            (SELECT COALESCE(SUM(total_amount), 0) FROM purchase_returns pr WHERE pr.supplier_id = s.id AND pr.tenant_id = s.tenant_id AND pr.status = 'completed') AS returned,
            (SELECT MAX(created_at) FROM purchases p2 WHERE p2.supplier_id = s.id AND p2.tenant_id = s.tenant_id) AS last_purchase
       FROM suppliers s WHERE {$where} ORDER BY s.name"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_header('Suppliers', 'inventory/suppliers.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-truck text-emerald"></i> Supplier Records</h4>
  <div class="d-flex gap-2">
    <form class="d-flex" method="get"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search suppliers…"><button class="btn btn-outline-secondary btn-sm ms-1" type="submit"><i class="bi bi-search"></i></button></form>
    <?php if (can('suppliers.manage')): ?><a class="btn btn-emerald btn-sm" href="<?= url('admin/suppliers.php') ?>"><i class="bi bi-pencil-square"></i> Manage profiles</a><?php endif; ?>
    <?php if (can('purchases.manage')): ?><a class="btn btn-outline-primary btn-sm" href="<?= url('admin/supplier-payments.php') ?>"><i class="bi bi-cash-coin"></i> Payments & balances</a><?php endif; ?>
  </div>
</div>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Supplier</th><th>Contact</th><th>Terms</th><th class="text-end">Purchased</th><th class="text-end">Paid</th><th class="text-end">Returned</th><th class="text-end">Outstanding</th><th>Last purchase</th><th>Status</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No suppliers found.</td></tr><?php endif; ?>
  <?php foreach ($rows as $s): $balance = round((float)$s['purchased'] - (float)$s['paid'] - (float)$s['returned'], 2); ?>
    <tr>
      <td><strong><?= e($s['name']) ?></strong><br><small class="text-muted"><?= e($s['email'] ?? '') ?></small></td>
      <td class="small"><?= e($s['contact_person'] ?? '') ?><br><span class="text-muted"><?= e($s['phone'] ?? '') ?></span></td>
      <td class="small"><?= e($s['payment_terms'] ?? '') ?></td>
      <td class="text-end"><?= money((float)$s['purchased']) ?></td>
      <td class="text-end"><?= money((float)$s['paid']) ?></td>
      <td class="text-end"><?= money((float)$s['returned']) ?></td>
      <td class="text-end"><strong class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>"><?= money($balance) ?></strong></td>
      <td class="small"><?= $s['last_purchase'] ? e(fmt_date($s['last_purchase'])) : '—' ?></td>
      <td><?= status_badge($s['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<p class="text-muted small mt-2">Outstanding = received purchases − supplier payments − purchase returns. It is computed from the records on every view, so it can never drift.</p>
<?php admin_footer(); ?>
