<?php
/**
 * MediStock Cloud — Pharmacist sales history (read-only, tenant-wide
 * under sales.view): date filter, prescription flag, receipt reprints.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('sales.view');
$tid = tenant_id();
$pdo = db();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('from', '')) ? input('from') : date('Y-m-d', strtotime('-30 day'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('to', '')) ? input('to') : date('Y-m-d');
$page = max(1, (int)input('page', 1));
$perPage = 15;
$where = 's.tenant_id = ? AND DATE(s.created_at) BETWEEN ? AND ?';
$params = [$tid, $from, $to];
$count = $pdo->prepare("SELECT COUNT(*) FROM sales s WHERE {$where}");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT s.*, c.name AS customer_name, u.name AS sold_by_name, p.prescription_number
       FROM sales s
       LEFT JOIN customers c ON c.id = s.customer_id
       LEFT JOIN users u ON u.id = s.sold_by
       LEFT JOIN prescriptions p ON p.id = s.prescription_id
      WHERE {$where} ORDER BY s.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_header('Sales History', 'pharmacist/sales.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-receipt text-emerald"></i> Sales History <span class="badge bg-light text-dark"><?= $total ?></span></h4>
  <form class="d-flex gap-2" method="get">
    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>">
    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>">
    <button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-funnel"></i> Filter</button>
  </form>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Invoice</th><th>Customer</th><th>Rx</th><th>Sold by</th><th>Date</th><th>Status</th><th class="text-end">Total</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No sales in this range.</td></tr><?php endif; ?>
  <?php foreach ($rows as $s): ?>
    <tr>
      <td><strong><?= e($s['invoice_number']) ?></strong></td>
      <td><?= e($s['customer_name'] ?? 'Walk-in') ?></td>
      <td><?= $s['prescription_number'] ? '<span class="badge text-bg-warning">' . e($s['prescription_number']) . '</span>' : '<span class="text-muted small">—</span>' ?></td>
      <td class="small"><?= e($s['sold_by_name'] ?? '') ?></td>
      <td class="small"><?= e(fmt_date($s['created_at'], 'd M Y H:i')) ?></td>
      <td><?= status_badge($s['status']) ?></td>
      <td class="text-end"><?= money((float)$s['grand_total']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/receipt.php?id=' . (int)$s['id']) ?>"><i class="bi bi-printer"></i></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php if ($pages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="?page=<?= $p ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php admin_footer(); ?>
