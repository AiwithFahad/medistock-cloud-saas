<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('purchases.manage');
$tid = tenant_id();
$pdo = db();

$action = (string)input('action', 'list');
$viewId = (int)input('view', 0);

if (is_post()) {
    csrf_check();
    try {
        $supplierId = (int)input('supplier_id', 0);
        $branchId = (int)input('branch_id', 0);
        if ($supplierId <= 0 || $branchId <= 0) {
            throw new RuntimeException('Choose a supplier and a branch.');
        }
        $items = [];
        $meds = (array)input('medicine_id', []);
        foreach ($meds as $idx => $mid) {
            $qty = (int)(input('quantity', [])[$idx] ?? 0);
            if ((int)$mid <= 0 || $qty <= 0) continue;
            $items[] = [
                'medicine_id' => (int)$mid,
                'batch_number' => trim((string)(input('batch_number', [])[$idx] ?? '')),
                'manufacturing_date' => (string)(input('manufacturing_date', [])[$idx] ?? ''),
                'expiry_date' => (string)(input('expiry_date', [])[$idx] ?? ''),
                'quantity' => $qty,
                'purchase_price' => (float)(input('purchase_price', [])[$idx] ?? 0),
                'selling_price' => (float)(input('selling_price', [])[$idx] ?? 0),
            ];
        }
        if (!$items) {
            throw new RuntimeException('Add at least one purchase line with a quantity.');
        }
        foreach ($items as $it) {
            if ($it['batch_number'] === '' || $it['expiry_date'] === '') {
                throw new RuntimeException('Every line needs a batch number and an expiry date.');
            }
        }
        $pid = StockService::receivePurchase($tid, $branchId, $supplierId, $items, (int)$user['id'], trim((string)input('invoice_reference', '')) ?: null);
        flash('success', 'Purchase #' . $pid . ' received. Batches and stock movements were created in one transaction.');
        redirect('admin/purchases.php?view=' . $pid);
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('admin/purchases.php?action=new');
    }
}

$purchase = null;
$pItems = [];
if ($viewId > 0) {
    $s = $pdo->prepare('SELECT p.*, s.name AS supplier, br.name AS branch, u.name AS buyer FROM purchases p JOIN suppliers s ON s.id = p.supplier_id JOIN branches br ON br.id = p.branch_id LEFT JOIN users u ON u.id = p.purchased_by WHERE p.id = ? AND p.tenant_id = ?');
    $s->execute([$viewId, $tid]);
    $purchase = $s->fetch() ?: null;
    if ($purchase) {
        $i = $pdo->prepare('SELECT pi.*, m.name AS medicine, b.batch_number, b.expiry_date FROM purchase_items pi JOIN medicines m ON m.id = pi.medicine_id LEFT JOIN batches b ON b.id = pi.batch_id WHERE pi.purchase_id = ?');
        $i->execute([$viewId]);
        $pItems = $i->fetchAll();
    }
}

$list = $pdo->prepare(
    'SELECT p.*, s.name AS supplier, br.name AS branch FROM purchases p JOIN suppliers s ON s.id = p.supplier_id JOIN branches br ON br.id = p.branch_id
      WHERE p.tenant_id = ? ORDER BY p.id DESC LIMIT 100'
);
$list->execute([$tid]);
$purchases = $list->fetchAll();

$suppliers = $pdo->prepare('SELECT id, name FROM suppliers WHERE tenant_id = ? AND deleted_at IS NULL AND status = "active" ORDER BY name');
$suppliers->execute([$tid]);
$supplierList = $suppliers->fetchAll();
$branches = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY is_main DESC, name');
$branches->execute([$tid]);
$branchList = $branches->fetchAll();
$meds = $pdo->prepare('SELECT id, name FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$meds->execute([$tid]);
$medList = $meds->fetchAll();

admin_header('Purchases', 'admin/purchases.php');
?>
<?php if ($purchase): ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-bag-plus text-emerald"></i> Purchase #<?= (int)$purchase['id'] ?></h4>
    <a class="btn btn-outline-secondary btn-sm" href="purchases.php">All purchases</a>
  </div>
  <div class="card shadow-sm"><div class="card-body">
    <div class="row mb-3">
      <div class="col-md-4"><strong>Supplier:</strong> <?= e($purchase['supplier']) ?><br><strong>Branch:</strong> <?= e($purchase['branch']) ?></div>
      <div class="col-md-4"><strong>Invoice ref:</strong> <?= e($purchase['invoice_reference'] ?? '—') ?><br><strong>Received by:</strong> <?= e($purchase['buyer'] ?? '') ?></div>
      <div class="col-md-4"><strong>Status:</strong> <?= status_badge($purchase['status']) ?><br><strong>Total:</strong> <span class="text-emerald fw-bold"><?= money($purchase['total_amount']) ?></span></div>
    </div>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th class="text-end">Qty</th><th class="text-end">Buy price</th><th class="text-end">Line total</th></tr></thead>
      <tbody>
      <?php foreach ($pItems as $it): ?>
        <tr><td><?= e($it['medicine']) ?></td><td><?= e($it['batch_number'] ?? '') ?></td><td><?= fmt_date($it['expiry_date']) ?></td>
        <td class="text-end"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= money($it['purchase_price']) ?></td><td class="text-end"><strong><?= money($it['total']) ?></strong></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div></div>
<?php elseif ($action === 'new'): ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-bag-plus text-emerald"></i> Receive Purchase</h4>
    <a class="btn btn-outline-secondary btn-sm" href="purchases.php">All purchases</a>
  </div>
  <div class="card shadow-sm"><div class="card-body">
    <form method="post" id="purchaseForm">
      <?= csrf_field() ?>
      <div class="row g-3 mb-3">
        <div class="col-md-4"><label class="form-label">Supplier *</label>
          <select class="form-select" name="supplier_id" required><option value="">— Select supplier —</option>
          <?php foreach ($supplierList as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Receiving branch *</label>
          <select class="form-select" name="branch_id" required>
          <?php foreach ($branchList as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Supplier invoice reference</label><input class="form-control" name="invoice_reference" placeholder="e.g. SUP-INV-9918"></div>
      </div>
      <div class="table-responsive"><table class="table table-sm align-middle" id="linesTable">
        <thead><tr><th style="min-width:180px">Medicine</th><th>Batch #</th><th>Mfg date</th><th>Expiry *</th><th style="width:90px">Qty</th><th style="width:110px">Buy price</th><th style="width:110px">Sell price</th><th></th></tr></thead>
        <tbody>
          <tr>
            <td><select class="form-select form-select-sm" name="medicine_id[]"><option value="">— Select —</option><?php foreach ($medList as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select></td>
            <td><input class="form-control form-control-sm" name="batch_number[]" placeholder="B-1001"></td>
            <td><input class="form-control form-control-sm" type="date" name="manufacturing_date[]"></td>
            <td><input class="form-control form-control-sm" type="date" name="expiry_date[]"></td>
            <td><input class="form-control form-control-sm" type="number" name="quantity[]" min="0" value="0"></td>
            <td><input class="form-control form-control-sm" type="number" step="0.01" name="purchase_price[]" value="0"></td>
            <td><input class="form-control form-control-sm" type="number" step="0.01" name="selling_price[]" value="0"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line"><i class="bi bi-x"></i></button></td>
          </tr>
        </tbody>
      </table></div>
      <button type="button" class="btn btn-outline-emerald btn-sm" id="addLine"><i class="bi bi-plus-lg"></i> Add line</button>
      <button class="btn btn-emerald float-end" type="submit"><i class="bi bi-box-arrow-in-down"></i> Receive stock (transactional)</button>
      <div class="form-text mt-2">Receiving creates or tops up batches and writes a stock movement per line — all inside one database transaction.</div>
    </form>
  </div></div>
  <script>
  document.getElementById('addLine').addEventListener('click', function () {
    var body = document.querySelector('#linesTable tbody');
    var row = body.rows[0].cloneNode(true);
    row.querySelectorAll('input').forEach(function (i) { i.value = i.type === 'number' ? 0 : ''; });
    body.appendChild(row);
  });
  document.querySelector('#linesTable').addEventListener('click', function (ev) {
    var btn = ev.target.closest('.remove-line');
    if (btn && document.querySelectorAll('#linesTable tbody tr').length > 1) { btn.closest('tr').remove(); }
  });
  </script>
<?php else: ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-bag-plus text-emerald"></i> Purchases</h4>
    <a class="btn btn-emerald btn-sm" href="purchases.php?action=new"><i class="bi bi-plus-lg"></i> Receive purchase</a>
  </div>
  <div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>#</th><th>Supplier</th><th>Branch</th><th>Invoice ref</th><th>Status</th><th class="text-end">Total</th><th>When</th><th class="text-end">View</th></tr></thead>
    <tbody>
    <?php foreach ($purchases as $p): ?>
      <tr><td><?= (int)$p['id'] ?></td><td><strong><?= e($p['supplier']) ?></strong></td><td><?= e($p['branch']) ?></td>
      <td><?= e($p['invoice_reference'] ?? '—') ?></td><td><?= status_badge($p['status']) ?></td>
      <td class="text-end"><strong><?= money($p['total_amount']) ?></strong></td><td><?= fmt_date($p['created_at'], 'd M Y') ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="purchases.php?view=<?= (int)$p['id'] ?>"><i class="bi bi-eye"></i></a></td></tr>
    <?php endforeach; ?>
    <?php if (!$purchases): ?><tr><td colspan="8" class="text-center text-muted py-4">No purchases yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>
<?php admin_footer(); ?>
