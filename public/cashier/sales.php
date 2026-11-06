<?php
/**
 * MediStock Cloud — Cashier sales history (only my own sales), with date
 * filter, payment breakdown per sale and receipt reprints.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('sales.view');
$tid = tenant_id();
$pdo = db();
$uid = (int)$user['id'];

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('from', '')) ? input('from') : date('Y-m-d', strtotime('-30 day'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('to', '')) ? input('to') : date('Y-m-d');
$page = max(1, (int)input('page', 1));
$perPage = 15;

$where = 's.tenant_id = ? AND s.sold_by = ? AND DATE(s.created_at) BETWEEN ? AND ?';
$params = [$tid, $uid, $from, $to];
$count = $pdo->prepare("SELECT COUNT(*) FROM sales s WHERE {$where}");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$sum = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales s WHERE {$where} AND status = 'completed'");
$sum->execute($params);
$sumTotal = (float)$sum->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT s.*, c.name AS customer_name,
            (SELECT GROUP_CONCAT(CONCAT(sp.method, ' ', sp.amount) SEPARATOR ' + ') FROM sale_payments sp WHERE sp.sale_id = s.id) AS payments
       FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
      WHERE {$where} ORDER BY s.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_header('My Sales', 'cashier/sales.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-receipt text-emerald"></i> My Sales <span class="badge bg-light text-dark"><?= $total ?></span></h4>
  <span class="badge text-bg-success fs-6">Total in range: <?= money($sumTotal) ?></span>
</div>
<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
  <div class="col-auto"><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
  <div class="col-auto"><button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
  <div class="col-auto"><a class="btn btn-outline-secondary btn-sm" href="<?= url('cashier/pos.php') ?>"><i class="bi bi-upc-scan"></i> New sale</a></div>
</form>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Invoice</th><th>Customer</th><th>Payments</th><th>Date</th><th>Status</th><th class="text-end">Total</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No sales in this range.</td></tr><?php endif; ?>
  <?php foreach ($rows as $s): ?>
    <tr>
      <td><strong><?= e($s['invoice_number']) ?></strong></td>
      <td><?= e($s['customer_name'] ?? 'Walk-in') ?></td>
      <td class="small text-muted"><?= e($s['payments'] ?? '') ?></td>
      <td class="small"><?= e(fmt_date($s['created_at'], 'd M Y H:i')) ?></td>
      <td><?= status_badge($s['status']) ?></td>
      <td class="text-end"><?= money((float)$s['grand_total']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/receipt.php?id=' . (int)$s['id']) ?>"><i class="bi bi-printer"></i> Receipt</a></td>
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
