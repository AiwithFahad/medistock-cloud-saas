<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('sales.manage');
$tid = tenant_id();
$pdo = db();

if (is_post()) {
    csrf_check();
    try {
        $saleId = (int)input('sale_id', 0);
        $items = [];
        foreach ((array)input('return_qty', []) as $saleItemId => $qty) {
            if ((int)$qty > 0) {
                $items[] = ['sale_item_id' => (int)$saleItemId, 'quantity' => (int)$qty];
            }
        }
        if ($saleId <= 0 || !$items) {
            throw new RuntimeException('Select a sale and at least one item to return.');
        }
        $returnId = StockService::createReturn($tid, $saleId, $items, (string)input('refund_method', 'cash'), trim((string)input('reason', 'Customer return')), (int)$user['id']);
        flash('success', 'Return #' . $returnId . ' processed. Refund recorded and stock restored for in-date batches.');
        redirect('admin/returns.php');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('admin/returns.php' . ((int)input('sale_id', 0) ? '?sale_id=' . (int)input('sale_id') : ''));
    }
}

$saleId = (int)input('sale_id', 0);
$saleItems = [];
if ($saleId > 0) {
    $stmt = $pdo->prepare('SELECT si.*, m.name AS medicine, b.batch_number, s.invoice_number FROM sale_items si JOIN medicines m ON m.id = si.medicine_id LEFT JOIN batches b ON b.id = si.batch_id JOIN sales s ON s.id = si.sale_id WHERE si.sale_id = ? AND s.tenant_id = ?');
    $stmt->execute([$saleId, $tid]);
    $saleItems = $stmt->fetchAll();
}
$recentSales = $pdo->prepare('SELECT id, invoice_number, grand_total, created_at FROM sales WHERE tenant_id = ? AND status = "completed" ORDER BY id DESC LIMIT 30');
$recentSales->execute([$tid]);
$saleOptions = $recentSales->fetchAll();

$returns = $pdo->prepare(
    'SELECT r.*, s.invoice_number FROM returns r JOIN sales s ON s.id = r.sale_id WHERE r.tenant_id = ? ORDER BY r.id DESC LIMIT 50'
);
$returns->execute([$tid]);
$returnRows = $returns->fetchAll();

admin_header('Sales Returns', 'admin/returns.php');
?>
<h4 class="mb-3"><i class="bi bi-arrow-counterclockwise text-emerald"></i> Sales Returns &amp; Refunds</h4>
<div class="row g-3">
  <div class="col-lg-5"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Process a return</h6>
    <form method="get" class="mb-3">
      <label class="form-label">Original sale</label>
      <div class="d-flex gap-1">
        <select class="form-select" name="sale_id">
          <option value="">— Select sale —</option>
          <?php foreach ($saleOptions as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $saleId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['invoice_number']) ?> · <?= money($s['grand_total']) ?> · <?= fmt_date($s['created_at'], 'd M') ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary" type="submit">Load</button>
      </div>
    </form>
    <?php if ($saleItems): ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="sale_id" value="<?= $saleId ?>">
        <table class="table table-sm align-middle">
          <thead><tr><th>Item</th><th>Sold</th><th style="width:90px">Return qty</th></tr></thead>
          <tbody>
          <?php foreach ($saleItems as $it): ?>
            <tr><td><strong class="small"><?= e($it['medicine']) ?></strong><br><small class="text-muted">Batch <?= e($it['batch_number'] ?? '—') ?> · <?= money($it['unit_price']) ?></small></td>
            <td><?= (int)$it['quantity'] ?></td>
            <td><input class="form-control form-control-sm" type="number" name="return_qty[<?= (int)$it['id'] ?>]" min="0" max="<?= (int)$it['quantity'] ?>" value="0"></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small">Refund method</label>
            <select class="form-select form-select-sm" name="refund_method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile">Mobile money</option><option value="bank">Bank transfer</option></select></div>
          <div class="col-6"><label class="form-label small">Reason</label><input class="form-control form-control-sm" name="reason" value="Customer return"></div>
        </div>
        <button class="btn btn-emerald w-100 mt-3" type="submit"><i class="bi bi-check-lg"></i> Process return (transactional)</button>
        <div class="form-text">In-date items return to stock; expired items are recorded but not restocked. The refund is recorded against the return.</div>
      </form>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Recent returns</h6>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>#</th><th>Sale</th><th>Reason</th><th>Refund method</th><th class="text-end">Refund</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach ($returnRows as $r): ?>
        <tr><td><?= (int)$r['id'] ?></td><td><code><?= e($r['invoice_number']) ?></code></td><td><?= e($r['reason'] ?? '') ?></td>
        <td><?= e(ucfirst($r['refund_method'])) ?></td><td class="text-end"><strong><?= money($r['refund_amount']) ?></strong></td><td><?= fmt_date($r['created_at'], 'd M H:i') ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$returnRows): ?><tr><td colspan="6" class="text-center text-muted py-3">No returns yet.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div></div></div>
</div>
<?php admin_footer(); ?>
