<?php
/**
 * MediStock Cloud — printable sales receipt / invoice.
 * Shared by every role with sales.view (admin, pharmacist, cashier,
 * accountant). Screen view uses the standard layout; Print renders the
 * receipt sheet only (see the print rules in assets/css/style.css).
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('sales.view');
$tid = tenant_id();
$pdo = db();

$saleId = (int)input('id', 0);
$stmt = $pdo->prepare(
    'SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, b.name AS branch_name, u.name AS cashier_name
       FROM sales s
       LEFT JOIN customers c ON c.id = s.customer_id
       LEFT JOIN branches b ON b.id = s.branch_id
       LEFT JOIN users u ON u.id = s.sold_by
      WHERE s.id = ? AND s.tenant_id = ?'
);
$stmt->execute([$saleId, $tid]);
$sale = $stmt->fetch();
if (!$sale) {
    flash('error', 'Receipt not found.');
    redirect(role_home());
}
$items = $pdo->prepare(
    'SELECT si.*, m.name, m.strength, bt.batch_number FROM sale_items si
       JOIN medicines m ON m.id = si.medicine_id
       LEFT JOIN batches bt ON bt.id = si.batch_id
      WHERE si.sale_id = ? ORDER BY si.id'
);
$items->execute([$saleId]);
$items = $items->fetchAll();
$pays = $pdo->prepare('SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id');
$pays->execute([$saleId]);
$pays = $pays->fetchAll();
$paidTotal = array_sum(array_map(static fn($p) => (float)$p['amount'], $pays));
$tenant = $pdo->prepare('SELECT name, address, city, phone, email FROM tenants WHERE id = ?');
$tenant->execute([$tid]);
$tenant = $tenant->fetch();
$footer = StockService::tenantSetting($tid, 'receipt_footer', 'Thank you for your purchase!');

admin_header('Receipt ' . $sale['invoice_number'], 'admin/sales.php');
?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <h4 class="mb-0"><i class="bi bi-receipt text-emerald"></i> Receipt <?= e($sale['invoice_number']) ?></h4>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= url('admin/sales.php') ?>"><i class="bi bi-arrow-left"></i> Sales</a>
    <button class="btn btn-emerald btn-sm" onclick="document.body.classList.add('sheet-printing');window.print();setTimeout(()=>document.body.classList.remove('sheet-printing'),400)"><i class="bi bi-printer"></i> Print receipt</button>
  </div>
</div>

<div class="row justify-content-center"><div class="col-lg-6">
  <div class="card shadow-sm"><div class="card-body">
    <div class="receipt">
      <h5 class="text-center mb-0"><?= e($tenant['name']) ?></h5>
      <p class="text-center small text-muted mb-1"><?= e(trim(($tenant['address'] ?? '') . ' ' . ($tenant['city'] ?? ''))) ?> · <?= e($tenant['phone'] ?? '') ?></p>
      <hr>
      <div class="d-flex justify-content-between"><span>Invoice</span><strong><?= e($sale['invoice_number']) ?></strong></div>
      <div class="d-flex justify-content-between"><span>Date</span><span><?= e(fmt_date($sale['created_at'], 'd M Y H:i')) ?></span></div>
      <div class="d-flex justify-content-between"><span>Branch</span><span><?= e($sale['branch_name'] ?? '') ?></span></div>
      <div class="d-flex justify-content-between"><span>Served by</span><span><?= e($sale['cashier_name'] ?? '') ?></span></div>
      <?php if ($sale['customer_name']): ?><div class="d-flex justify-content-between"><span>Customer</span><span><?= e($sale['customer_name']) ?> (<?= e($sale['customer_phone'] ?? '') ?>)</span></div><?php endif; ?>
      <hr>
      <table class="table table-sm">
        <thead><tr><th>Item</th><th class="text-end">Qty × Price</th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['name']) ?> <?= e($it['strength'] ?? '') ?><br><small class="text-muted">Batch <?= e($it['batch_number'] ?? '—') ?></small></td>
            <td class="text-end"><?= (int)$it['quantity'] ?> × <?= money((float)$it['unit_price']) ?></td>
            <td class="text-end"><?= money((float)$it['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <hr>
      <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= money((float)$sale['subtotal']) ?></span></div>
      <div class="d-flex justify-content-between"><span>Discount</span><span>−<?= money((float)$sale['discount']) ?></span></div>
      <div class="d-flex justify-content-between"><span>Tax</span><span><?= money((float)$sale['tax']) ?></span></div>
      <div class="d-flex justify-content-between fw-bold"><span>Grand total</span><span><?= money((float)$sale['grand_total']) ?></span></div>
      <?php foreach ($pays as $p): ?>
        <div class="d-flex justify-content-between"><span>Paid (<?= e(ucfirst($p['method'])) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?>)</span><span><?= money((float)$p['amount']) ?></span></div>
      <?php endforeach; ?>
      <?php if ($paidTotal > (float)$sale['grand_total']): ?>
        <div class="d-flex justify-content-between"><span>Change</span><span><?= money($paidTotal - (float)$sale['grand_total']) ?></span></div>
      <?php endif; ?>
      <hr>
      <p class="text-center small mb-0"><?= e($footer) ?></p>
    </div>
  </div></div>
</div></div>

<!-- Print-only copy (assets/css/style.css: body.sheet-printing) -->
<div id="printSheet">
  <div class="receipt" style="max-width:340px;margin:0 auto">
    <h5 class="text-center mb-0"><?= e($tenant['name']) ?></h5>
    <p class="text-center small text-muted mb-1"><?= e($sale['branch_name'] ?? '') ?></p>
    <hr>
    <div class="d-flex justify-content-between"><span><?= e($sale['invoice_number']) ?></span><span><?= e(fmt_date($sale['created_at'], 'd M Y H:i')) ?></span></div>
    <table class="table table-sm mt-2"><tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['name']) ?> <?= e($it['strength'] ?? '') ?></td><td class="text-end"><?= (int)$it['quantity'] ?> × <?= money((float)$it['unit_price']) ?></td><td class="text-end"><?= money((float)$it['total']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <hr>
    <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= money((float)$sale['subtotal']) ?></span></div>
    <div class="d-flex justify-content-between"><span>Discount</span><span>−<?= money((float)$sale['discount']) ?></span></div>
    <div class="d-flex justify-content-between"><span>Tax</span><span><?= money((float)$sale['tax']) ?></span></div>
    <div class="d-flex justify-content-between fw-bold"><span>Total</span><span><?= money((float)$sale['grand_total']) ?></span></div>
    <?php foreach ($pays as $p): ?><div class="d-flex justify-content-between"><span><?= e(ucfirst($p['method'])) ?></span><span><?= money((float)$p['amount']) ?></span></div><?php endforeach; ?>
    <hr><p class="text-center small mb-0"><?= e($footer) ?></p>
  </div>
</div>
<?php admin_footer(); ?>
