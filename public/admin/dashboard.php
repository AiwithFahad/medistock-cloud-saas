<?php
/**
 * MediStock Cloud — Pharmacy overview dashboard (admin/dashboard.php).
 * The classic overview (public/admin/index.php) stays available; this is
 * the full dashboard for the admin tree: date-filtered KPIs, AJAX charts
 * fed by api/charts.php (daily sales, top medicines, expiry, inventory
 * value by category), subscription usage and quick actions.
 * Every figure is a live query — nothing is hardcoded.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('dashboard.view');
$tid = tenant_id();
$pdo = db();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('from', '')) ? input('from') : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('to', '')) ? input('to') : date('Y-m-d');

$one = static function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$todaySales = (float)$one('SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE tenant_id = ? AND DATE(created_at) = CURDATE() AND status = "completed"', [$tid]);
$periodSales = (float)$one('SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = "completed"', [$tid, $from, $to]);
$periodPurchases = (float)$one('SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = "received"', [$tid, $from, $to]);
$periodExpenses = (float)$one('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE tenant_id = ? AND expense_date BETWEEN ? AND ?', [$tid, $from, $to]);
// Real gross profit for the range: sale line totals minus batch cost.
$periodProfit = (float)$one(
    'SELECT COALESCE(SUM(si.total - (si.quantity * COALESCE(b.purchase_price, 0))), 0)
       FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN batches b ON b.id = si.batch_id
      WHERE s.tenant_id = ? AND DATE(s.created_at) BETWEEN ? AND ? AND s.status = "completed"',
    [$tid, $from, $to]
);
$medicineCount = (int)$one('SELECT COUNT(*) FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]);
$stockUnits = (int)$one('SELECT COALESCE(SUM(quantity), 0) FROM batches WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]);
$stockValue = (float)$one('SELECT COALESCE(SUM(quantity * purchase_price), 0) FROM batches WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]);
$expired = (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date < CURDATE() AND quantity > 0 AND deleted_at IS NULL', [$tid]);
$pendingPos = (int)$one('SELECT COUNT(*) FROM purchase_orders WHERE tenant_id = ? AND status IN ("draft","ordered","partially_received") AND deleted_at IS NULL', [$tid]);
$sub = SubscriptionService::current($tid);
$usage = SubscriptionService::usage($tid);

$recent = $pdo->prepare(
    'SELECT s.invoice_number, s.grand_total, s.created_at, c.name AS customer FROM sales s
       LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 6'
);
$recent->execute([$tid]);
$recent = $recent->fetchAll();

admin_header('Dashboard', 'admin/dashboard.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0">Pharmacy Dashboard</h4>
    <p class="text-muted small mb-0"><?= e(date('l, d F Y')) ?> · <?= e($sub['plan_name'] ?? 'No active plan') ?> plan<?= $sub ? ' · ' . e($sub['status']) . ' until ' . e(fmt_date($sub['ends_at'])) : '' ?></p>
  </div>
  <form class="d-flex gap-2 align-items-center" method="get">
    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>">
    <span class="text-muted small">to</span>
    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>">
    <button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-funnel"></i> Apply</button>
  </form>
</div>

<?php if ($expired > 0): ?>
<div class="alert alert-danger d-flex justify-content-between align-items-center">
  <span><i class="bi bi-exclamation-octagon"></i> <strong><?= $expired ?> expired batch(es)</strong> hold stock that can never be sold.</span>
  <a class="btn btn-sm btn-danger" href="<?= url('admin/expiry.php') ?>">Review expiry</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-1">
  <?php stat_card('bi-cash-stack', "Today's sales", money($todaySales), 'emerald'); ?>
  <?php stat_card('bi-graph-up', 'Sales in range', money($periodSales), 'sky', e(fmt_date($from)) . ' → ' . e(fmt_date($to))); ?>
  <?php stat_card('bi-bag-plus', 'Purchases in range', money($periodPurchases), 'amber'); ?>
  <?php stat_card('bi-piggy-bank', 'Gross profit in range', money($periodProfit), 'rose', 'after expenses: ' . money($periodProfit - $periodExpenses)); ?>
</div>
<div class="row g-3 mb-1">
  <?php stat_card('bi-capsule', 'Medicines', $medicineCount, 'emerald', number_format($stockUnits) . ' units in stock'); ?>
  <?php stat_card('bi-boxes', 'Inventory value (cost)', money($stockValue), 'sky'); ?>
  <?php stat_card('bi-clipboard2-check', 'Pending purchase orders', $pendingPos, 'amber'); ?>
  <?php stat_card('bi-people', 'Plan usage', (int)($usage['users'] ?? 0) . ' staff', 'rose', (int)($usage['medicines'] ?? 0) . ' medicines · ' . (int)($usage['transactions'] ?? 0) . ' sales this month'); ?>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-bar-chart text-emerald"></i> Daily sales — last 14 days</h6>
      <div class="chart-box"><canvas data-chart-url="<?= e(url('api/charts.php?series=daily_sales&days=14')) ?>" data-chart-type="bar"></canvas></div>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-trophy text-emerald"></i> Top-selling medicines</h6>
      <div class="chart-box-sm"><canvas data-chart-url="<?= e(url('api/charts.php?series=top_medicines')) ?>" data-chart-type="bar"></canvas></div>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-pie-chart text-emerald"></i> Inventory value by category</h6>
      <div class="chart-box-sm"><canvas data-chart-url="<?= e(url('api/charts.php?series=inventory_by_category')) ?>" data-chart-type="doughnut"></canvas></div>
    </div></div>
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-calendar-x text-emerald"></i> Expiry statistics</h6>
      <div class="chart-box-sm"><canvas data-chart-url="<?= e(url('api/charts.php?series=expiry')) ?>" data-chart-type="bar"></canvas></div>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-2"><i class="bi bi-lightning text-emerald"></i> Quick actions</h6>
      <div class="d-grid gap-2">
        <a class="btn btn-emerald btn-sm" href="<?= url('cashier/pos.php') ?>"><i class="bi bi-upc-scan"></i> New sale (POS)</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= url('admin/purchase-orders.php') ?>"><i class="bi bi-clipboard2-check"></i> New purchase order</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= url('admin/transfers.php') ?>"><i class="bi bi-arrow-left-right"></i> Branch transfer</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= url('admin/reports.php') ?>"><i class="bi bi-graph-up"></i> Reports & exports</a>
      </div>
      <hr>
      <h6 class="mb-2">Recent sales</h6>
      <?php foreach ($recent as $s): ?>
        <div class="kv-row"><span><strong><?= e($s['invoice_number']) ?></strong><br><small class="text-muted"><?= e($s['customer'] ?? 'Walk-in') ?> · <?= e(fmt_date($s['created_at'], 'd M H:i')) ?></small></span><strong><?= money((float)$s['grand_total']) ?></strong></div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
