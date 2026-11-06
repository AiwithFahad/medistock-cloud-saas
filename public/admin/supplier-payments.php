<?php
/**
 * MediStock Cloud — Supplier payments & outstanding balances.
 * Balances are DERIVED per supplier (received purchases − payments −
 * purchase returns) via StockService::supplierBalance(); recording a
 * payment inserts a supplier_payments row inside the audit trail.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('purchases.manage');
$tid = tenant_id();
$pdo = db();

if (is_post()) {
    csrf_check();
    try {
        $purchaseId = (int)input('purchase_id', 0) ?: null;
        StockService::recordSupplierPayment(
            $tid,
            (int)input('supplier_id', 0),
            $purchaseId,
            (float)input('amount', 0),
            (string)input('method', 'bank'),
            trim((string)input('reference', '')) ?: null,
            (string)input('paid_at', date('Y-m-d')),
            trim((string)input('notes', '')) ?: null,
            (int)$user['id']
        );
        flash('success', 'Supplier payment recorded — the outstanding balance has been updated.');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/supplier-payments.php');
}

$suppliers = $pdo->prepare('SELECT id, name FROM suppliers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$suppliers->execute([$tid]);
$suppliers = $suppliers->fetchAll();
$purchases = $pdo->prepare('SELECT id, invoice_reference, supplier_id, total_amount FROM purchases WHERE tenant_id = ? AND status = "received" ORDER BY id DESC LIMIT 50');
$purchases->execute([$tid]);
$purchases = $purchases->fetchAll();

$balances = [];
$totalOutstanding = 0.0;
foreach ($suppliers as $s) {
    $bal = StockService::supplierBalance($tid, (int)$s['id']);
    $balances[] = ['supplier' => $s, 'balance' => $bal];
    $totalOutstanding += max(0.0, $bal);
}
$totalPaid = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE tenant_id = ' . $tid)->fetchColumn();
$totalBought = (float)$pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE tenant_id = ' . $tid . ' AND status = "received"')->fetchColumn();

$history = $pdo->prepare(
    'SELECT sp.*, s.name AS supplier_name, p.invoice_reference FROM supplier_payments sp
       JOIN suppliers s ON s.id = sp.supplier_id LEFT JOIN purchases p ON p.id = sp.purchase_id
      WHERE sp.tenant_id = ? ORDER BY sp.id DESC LIMIT 30'
);
$history->execute([$tid]);
$history = $history->fetchAll();

admin_header('Supplier Payments', 'admin/supplier-payments.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-cash-coin text-emerald"></i> Supplier Payments & Balances</h4>
  <a class="btn btn-outline-secondary btn-sm" href="<?= url('inventory/receiving.php') ?>"><i class="bi bi-arrow-return-left"></i> Purchase returns</a>
</div>

<div class="row g-3 mb-1">
  <?php stat_card('bi-bag-check', 'Total purchased', money($totalBought), 'emerald', 'received purchases'); ?>
  <?php stat_card('bi-cash-stack', 'Total paid', money($totalPaid), 'sky', 'supplier payments'); ?>
  <?php stat_card('bi-exclamation-circle', 'Total outstanding', money($totalOutstanding), 'rose', 'owed to suppliers now'); ?>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="mb-3">Record a payment</h6>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label small">Supplier</label>
          <select class="form-select form-select-sm" name="supplier_id" required>
            <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label small">Against purchase (optional)</label>
          <select class="form-select form-select-sm" name="purchase_id">
            <option value="">General payment</option>
            <?php foreach ($purchases as $p): ?><option value="<?= (int)$p['id'] ?>">#<?= (int)$p['id'] ?> · <?= e($p['invoice_reference'] ?? '') ?> · <?= money((float)$p['total_amount']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="row g-2">
          <div class="col-6 mb-2"><label class="form-label small">Amount</label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" name="amount" required></div>
          <div class="col-6 mb-2"><label class="form-label small">Method</label>
            <select class="form-select form-select-sm" name="method"><option value="bank">Bank</option><option value="cash">Cash</option><option value="card">Card</option><option value="mobile">Mobile money</option></select></div>
          <div class="col-6 mb-2"><label class="form-label small">Date</label><input type="date" class="form-control form-control-sm" name="paid_at" value="<?= e(date('Y-m-d')) ?>"></div>
          <div class="col-6 mb-2"><label class="form-label small">Reference</label><input class="form-control form-control-sm" name="reference" placeholder="Cheque / transfer ref"></div>
        </div>
        <div class="mb-3"><label class="form-label small">Notes</label><input class="form-control form-control-sm" name="notes"></div>
        <button class="btn btn-emerald w-100" type="submit"><i class="bi bi-check-lg"></i> Record payment</button>
      </form>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3">Outstanding by supplier</h6>
      <?php foreach ($balances as $b): ?>
        <div class="kv-row"><span><?= e($b['supplier']['name']) ?></span><strong class="<?= $b['balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($b['balance']) ?></strong></div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3">Payment history</h6>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Date</th><th>Supplier</th><th>Purchase</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php if (!$history): ?><tr><td colspan="6" class="text-center text-muted py-3">No supplier payments recorded yet.</td></tr><?php endif; ?>
        <?php foreach ($history as $h): ?>
          <tr><td class="small"><?= e(fmt_date($h['paid_at'])) ?></td><td><?= e($h['supplier_name']) ?></td>
          <td class="small"><?= e($h['invoice_reference'] ?? '—') ?></td><td><?= e(ucfirst($h['method'])) ?></td>
          <td class="small text-muted"><?= e($h['reference'] ?? '') ?></td><td class="text-end"><?= money((float)$h['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="text-muted small mt-2 mb-0">Balances are computed from purchases, payments and purchase returns on every view — they are never stored, so they cannot drift from the records.</p>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
