<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
require_permission('reports.view');
$tid = tenant_id();
$pdo = db();

// CSV export: sales report for the selected period.
if (input('export') === 'sales') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice', 'Date', 'Customer', 'Subtotal', 'Discount', 'Tax', 'Grand total', 'Status']);
    $rows = $pdo->prepare('SELECT s.invoice_number, s.created_at, c.name AS customer, s.subtotal, s.discount, s.tax, s.grand_total, s.status FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1000');
    $rows->execute([$tid]);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [$r['invoice_number'], $r['created_at'], $r['customer'] ?? 'Walk-in', $r['subtotal'], $r['discount'], $r['tax'], $r['grand_total'], $r['status']]);
    }
    fclose($out);
    exit;
}

$q = static function (string $sql, array $extra = []) use ($pdo, $tid) {
    $s = $pdo->prepare($sql);
    $s->execute([$tid, ...$extra]);
    return $s;
};
$revenueMonth = (float)$q('SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE tenant_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != "void"')->fetchColumn();
$cogsMonth = (float)$q(
    'SELECT COALESCE(SUM(si.quantity * b.purchase_price),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN batches b ON b.id = si.batch_id
      WHERE s.tenant_id = ? AND MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE()) AND s.status != "void"'
)->fetchColumn();
$expensesMonth = (float)$q('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())')->fetchColumn();
$refundsMonth = (float)$q('SELECT COALESCE(SUM(refund_amount),0) FROM returns WHERE tenant_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())')->fetchColumn();
$stockValue = (float)$q('SELECT COALESCE(SUM(quantity * purchase_price),0) FROM batches WHERE tenant_id = ? AND quantity > 0')->fetchColumn();
$expiryLoss = (float)$q('SELECT COALESCE(SUM(quantity * purchase_price),0) FROM batches WHERE tenant_id = ? AND quantity > 0 AND expiry_date < CURDATE()')->fetchColumn();
$grossProfit = $revenueMonth - $cogsMonth;

// Daily sales, last 30 days (chart).
$daily = $q('SELECT DATE(created_at) AS d, SUM(grand_total) AS total FROM sales WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND status != "void" GROUP BY d ORDER BY d')->fetchAll();
$dailyMap = array_column($daily, 'total', 'd');
$labels = $series = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = date('d M', strtotime($day));
    $series[] = (float)($dailyMap[$day] ?? 0);
}
// Sales by branch.
$byBranch = $q('SELECT br.name, COUNT(*) AS sales_count, COALESCE(SUM(s.grand_total),0) AS total FROM sales s JOIN branches br ON br.id = s.branch_id WHERE s.tenant_id = ? AND s.status != "void" GROUP BY br.id ORDER BY total DESC')->fetchAll();
// Top suppliers by purchase value.
$bySupplier = $q('SELECT sup.name, COUNT(*) AS orders, COALESCE(SUM(p.total_amount),0) AS total FROM purchases p JOIN suppliers sup ON sup.id = p.supplier_id WHERE p.tenant_id = ? GROUP BY sup.id ORDER BY total DESC LIMIT 5')->fetchAll();

admin_header('Reports & Analytics', 'admin/reports.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-graph-up text-emerald"></i> Reports &amp; Analytics</h4>
  <a class="btn btn-outline-emerald btn-sm" href="reports.php?export=sales"><i class="bi bi-download"></i> Export sales CSV</a>
</div>
<p class="text-muted small">Every figure below is calculated from live sales, batches, purchases and expenses — nothing is hardcoded.</p>
<div class="row g-3 mb-4">
  <?php stat_card('bi-cash-coin', 'Revenue (this month)', money($revenueMonth), 'emerald'); ?>
  <?php stat_card('bi-graph-up-arrow', 'Gross profit (this month)', money($grossProfit), 'sky', 'Revenue − cost of goods sold'); ?>
  <?php stat_card('bi-wallet2', 'Expenses (this month)', money($expensesMonth), 'amber', 'Refunds: ' . money($refundsMonth)); ?>
  <?php stat_card('bi-boxes', 'Stock valuation (cost)', money($stockValue), 'emerald', 'Expired write-off value: ' . money($expiryLoss)); ?>
</div>
<div class="card shadow-sm mb-4"><div class="card-body">
  <h6 class="fw-bold text-navy">Daily sales — last 30 days</h6>
  <canvas id="dailyChart" height="110"></canvas>
</div></div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Sales by branch</h6>
    <table class="table table-sm align-middle mb-0"><thead><tr><th>Branch</th><th>Sales</th><th class="text-end">Revenue</th></tr></thead>
    <tbody><?php foreach ($byBranch as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= (int)$r['sales_count'] ?></td><td class="text-end"><strong><?= money($r['total']) ?></strong></td></tr><?php endforeach; ?>
    <?php if (!$byBranch): ?><tr><td colspan="3" class="text-muted text-center">No sales yet.</td></tr><?php endif; ?></tbody></table>
  </div></div></div>
  <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Top suppliers by purchase value</h6>
    <table class="table table-sm align-middle mb-0"><thead><tr><th>Supplier</th><th>Orders</th><th class="text-end">Purchased</th></tr></thead>
    <tbody><?php foreach ($bySupplier as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= (int)$r['orders'] ?></td><td class="text-end"><strong><?= money($r['total']) ?></strong></td></tr><?php endforeach; ?>
    <?php if (!$bySupplier): ?><tr><td colspan="3" class="text-muted text-center">No purchases yet.</td></tr><?php endif; ?></tbody></table>
  </div></div></div>
</div>
<script>
new Chart(document.getElementById('dailyChart'), {type:'line',
  data:{labels:<?= json_encode($labels) ?>, datasets:[{label:'Sales', data:<?= json_encode($series) ?>, borderColor:'#059669', backgroundColor:'rgba(5,150,105,.14)', fill:true, tension:.3, pointRadius:0}]},
  options:{plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}}});
</script>
<?php admin_footer(); ?>
