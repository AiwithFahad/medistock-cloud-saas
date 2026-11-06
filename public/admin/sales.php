<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
require_permission('sales.view');
$tid = tenant_id();
$pdo = db();

$viewId = (int)input('view', 0);
$sale = null;
$items = $payments = [];
if ($viewId > 0) {
    $s = $pdo->prepare('SELECT s.*, c.name AS customer_name, br.name AS branch_name, u.name AS cashier FROM sales s LEFT JOIN customers c ON c.id = s.customer_id LEFT JOIN branches br ON br.id = s.branch_id LEFT JOIN users u ON u.id = s.sold_by WHERE s.id = ? AND s.tenant_id = ?');
    $s->execute([$viewId, $tid]);
    $sale = $s->fetch() ?: null;
    if ($sale) {
        $i = $pdo->prepare('SELECT si.*, m.name AS medicine, b.batch_number, b.expiry_date FROM sale_items si JOIN medicines m ON m.id = si.medicine_id LEFT JOIN batches b ON b.id = si.batch_id WHERE si.sale_id = ?');
        $i->execute([$viewId]);
        $items = $i->fetchAll();
        $p = $pdo->prepare('SELECT * FROM sale_payments WHERE sale_id = ?');
        $p->execute([$viewId]);
        $payments = $p->fetchAll();
    }
}

$q = trim((string)input('q', ''));
$sql = 'SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.tenant_id = ?';
$params = [$tid];
if ($q !== '') {
    $sql .= ' AND (s.invoice_number LIKE ? OR c.name LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$sql .= ' ORDER BY s.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

admin_header('Sales', 'admin/sales.php');
?>
<?php if ($sale): ?>
  <div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h4 class="mb-0"><i class="bi bi-receipt text-emerald"></i> Invoice <?= e($sale['invoice_number']) ?></h4>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print invoice</button>
      <a class="btn btn-outline-secondary btn-sm" href="sales.php">All sales</a>
      <?php if (can('sales.manage')): ?><a class="btn btn-outline-danger btn-sm" href="<?= url('admin/returns.php') ?>?sale_id=<?= (int)$sale['id'] ?>"><i class="bi bi-arrow-counterclockwise"></i> Process return</a><?php endif; ?>
    </div>
  </div>
  <div class="card shadow-sm"><div class="card-body">
    <div class="row mb-3">
      <div class="col-md-4"><strong>Customer:</strong> <?= e($sale['customer_name'] ?? 'Walk-in') ?><br><strong>Branch:</strong> <?= e($sale['branch_name'] ?? '') ?></div>
      <div class="col-md-4"><strong>Date:</strong> <?= fmt_date($sale['created_at'], 'd M Y H:i') ?><br><strong>Sold by:</strong> <?= e($sale['cashier'] ?? '') ?></div>
      <div class="col-md-4"><strong>Status:</strong> <?= status_badge($sale['status']) ?></div>
    </div>
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Discount</th><th class="text-end">Total</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['medicine']) ?></td><td><?= e($it['batch_number'] ?? '—') ?></td><td><?= fmt_date($it['expiry_date']) ?></td>
        <td class="text-end"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= money($it['unit_price']) ?></td>
        <td class="text-end">−<?= money($it['discount']) ?></td><td class="text-end"><strong><?= money($it['total']) ?></strong></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="6" class="text-end">Subtotal</td><td class="text-end"><?= money($sale['subtotal']) ?></td></tr>
        <tr><td colspan="6" class="text-end">Discount</td><td class="text-end">−<?= money($sale['discount']) ?></td></tr>
        <tr><td colspan="6" class="text-end">Tax</td><td class="text-end"><?= money($sale['tax']) ?></td></tr>
        <tr><td colspan="6" class="text-end fw-bold">Grand total</td><td class="text-end fw-bold text-emerald"><?= money($sale['grand_total']) ?></td></tr>
      </tfoot>
    </table></div>
    <h6 class="fw-bold text-navy mt-3">Payments</h6>
    <?php foreach ($payments as $p): ?>
      <span class="badge bg-light text-dark border"><?= e(ucfirst($p['method'])) ?>: <?= money($p['amount']) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></span>
    <?php endforeach; ?>
  </div></div>
<?php else: ?>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-cart-check text-emerald"></i> Sales</h4>
    <form class="d-flex" method="get"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Invoice # or customer…"><button class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-search"></i></button></form>
  </div>
  <div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Invoice</th><th>Customer</th><th>Items</th><th>Status</th><th class="text-end">Total</th><th>When</th><th class="text-end">View</th></tr></thead>
    <tbody>
    <?php foreach ($sales as $s): ?>
      <tr>
        <td><code><?= e($s['invoice_number']) ?></code></td>
        <td><?= e($s['customer_name'] ?? 'Walk-in') ?></td>
        <td><?= (int)$pdo->query('SELECT COUNT(*) FROM sale_items WHERE sale_id = ' . (int)$s['id'])->fetchColumn() ?></td>
        <td><?= status_badge($s['status']) ?></td>
        <td class="text-end"><strong><?= money($s['grand_total']) ?></strong></td>
        <td><?= fmt_date($s['created_at'], 'd M Y H:i') ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="sales.php?view=<?= (int)$s['id'] ?>"><i class="bi bi-eye"></i></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sales): ?><tr><td colspan="7" class="text-center text-muted py-4">No sales yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>
<?php admin_footer(); ?>
