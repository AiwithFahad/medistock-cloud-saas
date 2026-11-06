<?php
/**
 * MediStock Cloud — Cashier dashboard.
 * My shift figures (real, from sales I rang up), today's pharmacy totals,
 * my payment-method breakdown and my recent sales with receipt links.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['cashier', 'pharmacist', 'admin', 'owner']);
$tid = tenant_id();
$pdo = db();
$uid = (int)$user['id'];

$one = static function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$myTodayCount = (int)$one('SELECT COUNT(*) FROM sales WHERE tenant_id = ? AND sold_by = ? AND DATE(created_at) = CURDATE() AND status = "completed"', [$tid, $uid]);
$myTodayTotal = (float)$one('SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE tenant_id = ? AND sold_by = ? AND DATE(created_at) = CURDATE() AND status = "completed"', [$tid, $uid]);
$shopTodayTotal = (float)$one('SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE tenant_id = ? AND DATE(created_at) = CURDATE() AND status = "completed"', [$tid]);
$myMonthTotal = (float)$one('SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE tenant_id = ? AND sold_by = ? AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01") AND status = "completed"', [$tid, $uid]);

$methods = $pdo->prepare(
    'SELECT sp.method, COUNT(*) AS n, SUM(sp.amount) AS total FROM sale_payments sp
       JOIN sales s ON s.id = sp.sale_id
      WHERE s.tenant_id = ? AND s.sold_by = ? AND DATE(s.created_at) = CURDATE() AND s.status = "completed"
      GROUP BY sp.method'
);
$methods->execute([$tid, $uid]);
$methods = $methods->fetchAll();

$recent = $pdo->prepare(
    'SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.tenant_id = ? AND s.sold_by = ? ORDER BY s.id DESC LIMIT 10'
);
$recent->execute([$tid, $uid]);
$recent = $recent->fetchAll();

admin_header('Cashier Dashboard', 'cashier/dashboard.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0">Welcome, <?= e($user['name']) ?></h4>
    <p class="text-muted small mb-0"><?= e(date('l, d F Y')) ?> · Your till at a glance</p>
  </div>
  <a class="btn btn-emerald" href="<?= url('cashier/pos.php') ?>"><i class="bi bi-upc-scan"></i> Open POS</a>
</div>

<div class="row g-3 mb-1">
  <?php stat_card('bi-receipt', 'My sales today', $myTodayCount, 'emerald', 'bills I completed'); ?>
  <?php stat_card('bi-cash-stack', 'My takings today', money($myTodayTotal), 'sky'); ?>
  <?php stat_card('bi-shop', 'Pharmacy sales today', money($shopTodayTotal), 'amber', 'all cashiers'); ?>
  <?php stat_card('bi-calendar3', 'My takings this month', money($myMonthTotal), 'rose'); ?>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-wallet2 text-emerald"></i> Today's payments (mine)</h6>
      <?php if (!$methods): ?><p class="text-muted small mb-0">No payments yet today.</p><?php endif; ?>
      <?php foreach ($methods as $m): ?>
        <div class="kv-row"><span><?= e(ucfirst($m['method'])) ?> <span class="text-muted">(<?= (int)$m['n'] ?>)</span></span><strong><?= money((float)$m['total']) ?></strong></div>
      <?php endforeach; ?>
      <a class="btn btn-outline-secondary btn-sm mt-3" href="<?= url('cashier/sales.php') ?>"><i class="bi bi-receipt"></i> My sales history</a>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3"><i class="bi bi-clock-history text-emerald"></i> My recent sales</h6>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Invoice</th><th>Customer</th><th>When</th><th class="text-end">Total</th><th></th></tr></thead>
        <tbody>
        <?php if (!$recent): ?><tr><td colspan="5" class="text-center text-muted py-3">No sales yet — open the POS to ring up your first sale.</td></tr><?php endif; ?>
        <?php foreach ($recent as $s): ?>
          <tr>
            <td><strong><?= e($s['invoice_number']) ?></strong></td>
            <td><?= e($s['customer_name'] ?? 'Walk-in') ?></td>
            <td class="small text-muted"><?= e(fmt_date($s['created_at'], 'd M H:i')) ?></td>
            <td class="text-end"><?= money((float)$s['grand_total']) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/receipt.php?id=' . (int)$s['id']) ?>"><i class="bi bi-printer"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
