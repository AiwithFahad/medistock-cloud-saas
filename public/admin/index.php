<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
$tid = tenant_id();
$pdo = db();
$q = static function (string $sql) use ($pdo, $tid) {
    $s = $pdo->prepare($sql);
    $s->execute([$tid]);
    return $s->fetchColumn();
};

$todaySales = (float)$q('SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE tenant_id = ? AND DATE(created_at) = CURDATE() AND status != "void"');
$monthSales = (float)$q('SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE tenant_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != "void"');
$medicineCount = (int)$q('SELECT COUNT(*) FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL');
$stockUnits = (int)$q('SELECT COALESCE(SUM(quantity),0) FROM batches WHERE tenant_id = ? AND quantity > 0');
$stockValue = (float)$q('SELECT COALESCE(SUM(quantity * purchase_price),0) FROM batches WHERE tenant_id = ? AND quantity > 0');
$lowStock = (int)$q('SELECT COUNT(*) FROM medicines m WHERE m.tenant_id = ? AND m.deleted_at IS NULL AND m.reorder_level > 0 AND (SELECT COALESCE(SUM(b.quantity),0) FROM batches b WHERE b.medicine_id = m.id AND b.expiry_date >= CURDATE()) <= m.reorder_level');
$expiring = (int)$q('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND quantity > 0 AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)');
$expired = (int)$q('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND quantity > 0 AND expiry_date < CURDATE()');
$customerCount = (int)$q('SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND deleted_at IS NULL');
$sub = SubscriptionService::current($tid);
$usage = SubscriptionService::usage($tid);

// Sales last 14 days for chart.
$salesRows = $pdo->prepare(
    'SELECT DATE(created_at) AS d, SUM(grand_total) AS total FROM sales
      WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND status != "void"
      GROUP BY d ORDER BY d'
);
$salesRows->execute([$tid]);
$salesMap = array_column($salesRows->fetchAll(), 'total', 'd');
$labels = $data = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = date('d M', strtotime($day));
    $data[] = (float)($salesMap[$day] ?? 0);
}
// Top medicines by units sold.
$top = $pdo->prepare(
    'SELECT m.name, SUM(si.quantity) AS units, SUM(si.total) AS revenue FROM sale_items si
       JOIN sales s ON s.id = si.sale_id JOIN medicines m ON m.id = si.medicine_id
      WHERE s.tenant_id = ? AND s.status != "void" GROUP BY m.id ORDER BY units DESC LIMIT 5'
);
$top->execute([$tid]);
$topMeds = $top->fetchAll();
// Recent sales.
$recent = $pdo->prepare(
    'SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 6'
);
$recent->execute([$tid]);
$recentSales = $recent->fetchAll();

$tenant = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
$tenant->execute([$tid]);
$tenantRow = $tenant->fetch();

admin_header('Pharmacy Dashboard', 'admin/index.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h3 class="mb-0 text-navy fw-bold"><?= e($tenantRow['name'] ?? 'Pharmacy') ?></h3>
    <small class="text-muted">Welcome back, <?= e($user['name']) ?> · <?= e($user['role_name']) ?></small>
  </div>
  <div class="d-flex gap-2">
    <?php if ($sub): ?><span class="badge bg-emerald align-self-center"><?= e($sub['plan_name']) ?> · <?= e(ucfirst($sub['status'])) ?></span><?php endif; ?>
    <?php if (can('pos.access')): ?><a class="btn btn-emerald" href="<?= url('admin/pos.php') ?>"><i class="bi bi-upc-scan"></i> Open POS</a><?php endif; ?>
  </div>
</div>

<?php if ($expired > 0 || $lowStock > 0): ?>
<div class="row g-3 mb-3">
  <?php if ($expired > 0): ?><div class="col-md-6"><div class="alert-strip danger"><i class="bi bi-calendar-x fs-4 text-danger"></i><div><strong><?= $expired ?> expired batch<?= $expired > 1 ? 'es' : '' ?></strong> still hold stock. They are blocked from sale — review and write them off. <a href="<?= url('admin/expiry.php') ?>">Open expiry management</a></div></div></div><?php endif; ?>
  <?php if ($lowStock > 0): ?><div class="col-md-6"><div class="alert-strip warning"><i class="bi bi-exclamation-triangle fs-4 text-warning"></i><div><strong><?= $lowStock ?> medicine<?= $lowStock > 1 ? 's' : '' ?> at or below reorder level.</strong> <a href="<?= url('admin/lowstock.php') ?>">View low-stock alerts</a></div></div></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php stat_card('bi-cash-coin', "Today's Sales", money($todaySales), 'emerald'); ?>
  <?php stat_card('bi-calendar-month', 'Sales This Month', money($monthSales), 'sky'); ?>
  <?php stat_card('bi-capsule', 'Medicines', number_format($medicineCount), 'emerald', number_format($stockUnits) . ' units in stock'); ?>
  <?php stat_card('bi-boxes', 'Stock Value (cost)', money($stockValue), 'amber'); ?>
</div>
<div class="row g-3 mb-4">
  <?php stat_card('bi-calendar-x', 'Expiring ≤ 90 days', number_format($expiring), 'amber', $expired . ' already expired'); ?>
  <?php stat_card('bi-exclamation-triangle', 'Low Stock', number_format($lowStock), 'rose'); ?>
  <?php stat_card('bi-person-heart', 'Customers', number_format($customerCount), 'sky'); ?>
  <?php stat_card('bi-diagram-3', 'Branches', number_format($usage['branches']), 'emerald', 'Plan limit: ' . ((int)($sub['max_branches'] ?? 0) === 0 ? 'Unlimited' : (int)$sub['max_branches'])); ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy">Sales — last 14 days</h6>
    <canvas id="salesChart" height="130"></canvas>
  </div></div></div>
  <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Top medicines (units sold)</h6>
    <?php foreach ($topMeds as $m): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><span><?= e($m['name']) ?></span><span><strong><?= number_format((int)$m['units']) ?></strong> · <?= money($m['revenue']) ?></span></div>
    <?php endforeach; ?>
    <?php if (!$topMeds): ?><p class="text-muted small mb-0">No sales yet — open the POS to make your first sale.</p><?php endif; ?>
  </div></div></div>
</div>

<div class="card shadow-sm"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold text-navy mb-0">Recent sales</h6><a class="small" href="<?= url('admin/sales.php') ?>">View all</a></div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Invoice</th><th>Customer</th><th>Status</th><th class="text-end">Total</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($recentSales as $s): ?>
      <tr><td><a href="<?= url('admin/sales.php') ?>?view=<?= (int)$s['id'] ?>"><?= e($s['invoice_number']) ?></a></td><td><?= e($s['customer_name'] ?? 'Walk-in') ?></td><td><?= status_badge($s['status']) ?></td><td class="text-end"><strong><?= money($s['grand_total']) ?></strong></td><td><?= fmt_date($s['created_at'], 'd M H:i') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recentSales): ?><tr><td colspan="5" class="text-center text-muted py-3">No sales recorded yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</div></div>

<script>
new Chart(document.getElementById('salesChart'), {type:'bar',
  data:{labels:<?= json_encode($labels) ?>, datasets:[{label:'Sales', data:<?= json_encode($data) ?>, backgroundColor:'#059669', borderRadius:5}]},
  options:{plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}}});
</script>
<?php admin_footer(); ?>
