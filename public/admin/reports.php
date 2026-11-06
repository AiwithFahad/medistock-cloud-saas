<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
require_permission('reports.view');
$tid = tenant_id();
$pdo = db();

// CSV exports (sales / purchases / expenses) — real rows, tenant-scoped.
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
if (input('export') === 'purchases') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="purchases-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Date', 'Supplier', 'Branch', 'Invoice ref', 'Total', 'Status']);
    $rows = $pdo->prepare('SELECT p.id, p.created_at, s.name AS supplier, b.name AS branch, p.invoice_reference, p.total_amount, p.status FROM purchases p JOIN suppliers s ON s.id = p.supplier_id JOIN branches b ON b.id = p.branch_id WHERE p.tenant_id = ? ORDER BY p.id DESC LIMIT 1000');
    $rows->execute([$tid]);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [$r['id'], $r['created_at'], $r['supplier'], $r['branch'], $r['invoice_reference'], $r['total_amount'], $r['status']]);
    }
    fclose($out);
    exit;
}
if (input('export') === 'expenses') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="expenses-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Title', 'Category', 'Amount', 'Method']);
    $rows = $pdo->prepare('SELECT expense_date, title, category, amount, payment_method FROM expenses WHERE tenant_id = ? ORDER BY expense_date DESC LIMIT 1000');
    $rows->execute([$tid]);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [$r['expense_date'], $r['title'], $r['category'], $r['amount'], $r['payment_method']]);
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
// Daily cash report (today): takings by payment method, refunds, expenses.
$cashToday = $q(
    'SELECT sp.method, COUNT(*) AS n, COALESCE(SUM(sp.amount),0) AS total FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
      WHERE s.tenant_id = ? AND DATE(s.created_at) = CURDATE() AND s.status = "completed" GROUP BY sp.method'
)->fetchAll();
$cashInToday = array_sum(array_map(static fn($r) => (float)$r['total'], $cashToday));
$refundsToday = (float)$q('SELECT COALESCE(SUM(refund_amount),0) FROM returns WHERE tenant_id = ? AND DATE(created_at) = CURDATE()')->fetchColumn();
$expensesToday = (float)$q('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id = ? AND expense_date = CURDATE()')->fetchColumn();

// Monthly financial summary — last 6 months: revenue, COGS, gross profit,
// expenses, refunds, net. All derived from live records.
$financials = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} month"));
    $rev = (float)$q('SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE tenant_id = ? AND DATE_FORMAT(created_at, "%Y-%m") = ? AND status = "completed"', [$ym])->fetchColumn();
    $cogs = (float)$q('SELECT COALESCE(SUM(si.quantity * COALESCE(b.purchase_price,0)),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN batches b ON b.id = si.batch_id WHERE s.tenant_id = ? AND DATE_FORMAT(s.created_at, "%Y-%m") = ? AND s.status = "completed"', [$ym])->fetchColumn();
    $exp = (float)$q('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id = ? AND DATE_FORMAT(expense_date, "%Y-%m") = ?', [$ym])->fetchColumn();
    $ref = (float)$q('SELECT COALESCE(SUM(refund_amount),0) FROM returns WHERE tenant_id = ? AND DATE_FORMAT(created_at, "%Y-%m") = ?', [$ym])->fetchColumn();
    $financials[] = ['label' => date('M Y', strtotime($ym . '-01')), 'revenue' => $rev, 'cogs' => $cogs, 'gross' => $rev - $cogs, 'expenses' => $exp, 'refunds' => $ref, 'net' => $rev - $cogs - $exp - $ref];
}
// Outstanding supplier balances (derived across all suppliers).
$supplierOutstanding = 0.0;
foreach ($pdo->query('SELECT id FROM suppliers WHERE tenant_id = ' . $tid . ' AND deleted_at IS NULL')->fetchAll() as $sup) {
    $supplierOutstanding += max(0.0, StockService::supplierBalance($tid, (int)$sup['id']));
}

// Sales by branch.
$byBranch = $q('SELECT br.name, COUNT(*) AS sales_count, COALESCE(SUM(s.grand_total),0) AS total FROM sales s JOIN branches br ON br.id = s.branch_id WHERE s.tenant_id = ? AND s.status != "void" GROUP BY br.id ORDER BY total DESC')->fetchAll();
// Top suppliers by purchase value.
$bySupplier = $q('SELECT sup.name, COUNT(*) AS orders, COALESCE(SUM(p.total_amount),0) AS total FROM purchases p JOIN suppliers sup ON sup.id = p.supplier_id WHERE p.tenant_id = ? GROUP BY sup.id ORDER BY total DESC LIMIT 5')->fetchAll();

admin_header('Reports & Analytics', 'admin/reports.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-graph-up text-emerald"></i> Reports &amp; Analytics</h4>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-emerald btn-sm" href="reports.php?export=sales"><i class="bi bi-download"></i> Sales CSV</a>
    <a class="btn btn-outline-emerald btn-sm" href="reports.php?export=purchases"><i class="bi bi-download"></i> Purchases CSV</a>
    <a class="btn btn-outline-emerald btn-sm" href="reports.php?export=expenses"><i class="bi bi-download"></i> Expenses CSV</a>
  </div>
</div>
<p class="text-muted small">Every figure below is calculated from live sales, batches, purchases and expenses — nothing is hardcoded.</p>
<div class="row g-3 mb-4">
  <?php stat_card('bi-cash-coin', 'Revenue (this month)', money($revenueMonth), 'emerald'); ?>
  <?php stat_card('bi-graph-up-arrow', 'Gross profit (this month)', money($grossProfit), 'sky', 'Revenue − cost of goods sold'); ?>
  <?php stat_card('bi-wallet2', 'Expenses (this month)', money($expensesMonth), 'amber', 'Refunds: ' . money($refundsMonth)); ?>
  <?php stat_card('bi-boxes', 'Stock valuation (cost)', money($stockValue), 'emerald', 'Expired write-off value: ' . money($expiryLoss)); ?>
</div>
<div class="row g-3 mb-4">
  <div class="col-lg-5"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3"><i class="bi bi-cash-register"></i> Daily cash report — today</h6>
    <?php if (!$cashToday): ?><p class="text-muted small">No takings recorded yet today.</p><?php endif; ?>
    <?php foreach ($cashToday as $r): ?>
      <div class="kv-row"><span><?= e(ucfirst($r['method'])) ?> <span class="text-muted">(<?= (int)$r['n'] ?> payments)</span></span><strong><?= money((float)$r['total']) ?></strong></div>
    <?php endforeach; ?>
    <div class="kv-row"><span>Total takings</span><strong><?= money($cashInToday) ?></strong></div>
    <div class="kv-row"><span>Refunds paid today</span><strong class="text-danger">−<?= money($refundsToday) ?></strong></div>
    <div class="kv-row"><span>Expenses today</span><strong class="text-danger">−<?= money($expensesToday) ?></strong></div>
    <div class="kv-row"><span><strong>Net cash movement</strong></span><strong><?= money($cashInToday - $refundsToday - $expensesToday) ?></strong></div>
  </div></div></div>
  <div class="col-lg-7"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3"><i class="bi bi-table"></i> Monthly financial summary</h6>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Month</th><th class="text-end">Revenue</th><th class="text-end">COGS</th><th class="text-end">Gross profit</th><th class="text-end">Expenses</th><th class="text-end">Refunds</th><th class="text-end">Net</th></tr></thead>
      <tbody>
      <?php foreach ($financials as $f): ?>
        <tr><td><?= e($f['label']) ?></td><td class="text-end"><?= money($f['revenue']) ?></td><td class="text-end"><?= money($f['cogs']) ?></td>
        <td class="text-end"><?= money($f['gross']) ?></td><td class="text-end"><?= money($f['expenses']) ?></td><td class="text-end"><?= money($f['refunds']) ?></td>
        <td class="text-end"><strong class="<?= $f['net'] < 0 ? 'text-danger' : 'text-success' ?>"><?= money($f['net']) ?></strong></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="text-muted small mt-2 mb-0">Outstanding owed to suppliers right now: <strong><?= money($supplierOutstanding) ?></strong> (see Supplier Payments for the per-supplier breakdown).</p>
  </div></div></div>
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
