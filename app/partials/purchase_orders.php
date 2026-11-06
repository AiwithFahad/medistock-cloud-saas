<?php
/**
 * MediStock Cloud — shared Purchase Orders page body.
 * Included by public/admin/purchase-orders.php and
 * public/inventory/purchase-orders.php after their own guards.
 * Expects: $tid, $user, $base, $activePath.
 *
 * Orders track ordered vs received per line; receiving (including partial
 * deliveries) creates a real purchase — batches, valuation and stock
 * movements — via StockService::receivePurchaseOrder().
 */
$pdo = db();
$uid = (int)$user['id'];

if (is_post()) {
    csrf_check();
    $op = (string)input('_op', '');
    try {
        if ($op === 'create') {
            $items = [];
            foreach ((array)($_POST['medicine_id'] ?? []) as $i => $mid) {
                $qty = (int)($_POST['quantity'][$i] ?? 0);
                if ((int)$mid > 0 && $qty > 0) {
                    $items[] = ['medicine_id' => (int)$mid, 'quantity' => $qty, 'purchase_price' => (float)($_POST['purchase_price'][$i] ?? 0)];
                }
            }
            if (!$items) {
                throw new RuntimeException('Add at least one order line.');
            }
            $id = StockService::createPurchaseOrder($tid, (int)input('branch_id', 0), (int)input('supplier_id', 0), $items, input('expected_date') ?: null, trim((string)input('notes', '')) ?: null, $uid);
            flash('success', 'Purchase order created.');
            redirect($base . '?id=' . $id);
        }
        if ($op === 'receive') {
            $lines = [];
            foreach ((array)($_POST['po_item_id'] ?? []) as $i => $poi) {
                $qty = (int)($_POST['r_quantity'][$i] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $lines[] = [
                    'po_item_id' => (int)$poi, 'quantity' => $qty,
                    'batch_number' => trim((string)($_POST['r_batch_number'][$i] ?? '')),
                    'manufacturing_date' => (string)($_POST['r_mfg'][$i] ?? ''),
                    'expiry_date' => (string)($_POST['r_expiry'][$i] ?? ''),
                    'selling_price' => (float)($_POST['r_selling'][$i] ?? 0),
                ];
            }
            $purchaseId = StockService::receivePurchaseOrder($tid, (int)input('id', 0), $lines, $uid);
            flash('success', 'Goods received — purchase #' . $purchaseId . ' created and stock updated.');
            redirect($base . '?id=' . (int)input('id', 0));
        }
        if ($op === 'cancel') {
            $stmt = $pdo->prepare('UPDATE purchase_orders SET status = "cancelled", updated_at = NOW() WHERE id = ? AND tenant_id = ? AND status IN ("draft","ordered","partially_received")');
            $stmt->execute([(int)input('id', 0), $tid]);
            flash('success', 'Purchase order cancelled.');
            redirect($base);
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect($base);
    }
}

$suppliers = $pdo->prepare('SELECT id, name FROM suppliers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$suppliers->execute([$tid]);
$suppliers = $suppliers->fetchAll();
$branches = $pdo->prepare('SELECT id, name, code FROM branches WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY is_main DESC, name');
$branches->execute([$tid]);
$branches = $branches->fetchAll();
$meds = $pdo->prepare('SELECT id, name, strength, default_purchase_price, default_selling_price FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$meds->execute([$tid]);
$meds = $meds->fetchAll();

$detail = null;
$detailItems = [];
if ((int)input('id', 0) > 0) {
    $stmt = $pdo->prepare(
        'SELECT po.*, s.name AS supplier_name, b.name AS branch_name, u.name AS ordered_by_name
           FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN branches b ON b.id = po.branch_id
           LEFT JOIN users u ON u.id = po.ordered_by
          WHERE po.id = ? AND po.tenant_id = ? AND po.deleted_at IS NULL'
    );
    $stmt->execute([(int)input('id', 0), $tid]);
    $detail = $stmt->fetch() ?: null;
    if ($detail) {
        $it = $pdo->prepare('SELECT poi.*, m.name, m.strength FROM purchase_order_items poi JOIN medicines m ON m.id = poi.medicine_id WHERE poi.purchase_order_id = ? ORDER BY poi.id');
        $it->execute([(int)$detail['id']]);
        $detailItems = $it->fetchAll();
    }
}

$status = (string)input('status', '');
$where = 'po.tenant_id = ? AND po.deleted_at IS NULL';
$params = [$tid];
if (in_array($status, ['draft', 'ordered', 'partially_received', 'received', 'cancelled'], true)) {
    $where .= ' AND po.status = ?';
    $params[] = $status;
}
$list = $pdo->prepare(
    "SELECT po.*, s.name AS supplier_name, b.name AS branch_name,
            (SELECT COALESCE(SUM(quantity_ordered), 0) FROM purchase_order_items WHERE purchase_order_id = po.id) AS ordered_units,
            (SELECT COALESCE(SUM(quantity_received), 0) FROM purchase_order_items WHERE purchase_order_id = po.id) AS received_units
       FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN branches b ON b.id = po.branch_id
      WHERE {$where} ORDER BY po.id DESC LIMIT 50"
);
$list->execute($params);
$list = $list->fetchAll();
$receivable = $detail && in_array($detail['status'], ['draft', 'ordered', 'partially_received'], true);

admin_header('Purchase Orders', $activePath);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-clipboard2-check text-emerald"></i> Purchase Orders</h4>
  <button class="btn btn-emerald btn-sm" data-bs-toggle="collapse" data-bs-target="#newPo"><i class="bi bi-plus-lg"></i> New order</button>
</div>

<div class="collapse mb-3" id="newPo"><div class="card shadow-sm"><div class="card-body">
  <h6 class="mb-3">Create a purchase order</h6>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="_op" value="create">
    <div class="row g-2 mb-2">
      <div class="col-md-3"><label class="form-label small">Supplier</label>
        <select class="form-select form-select-sm" name="supplier_id" required>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label small">Deliver to branch</label>
        <select class="form-select form-select-sm" name="branch_id" required>
          <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?> (<?= e($b['code']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label small">Expected date</label><input type="date" class="form-control form-control-sm" name="expected_date"></div>
      <div class="col-md-3"><label class="form-label small">Notes</label><input class="form-control form-control-sm" name="notes"></div>
    </div>
    <div id="poItems"></div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="poAdd"><i class="bi bi-plus-lg"></i> Add line</button>
    <button class="btn btn-emerald btn-sm ms-2" type="submit"><i class="bi bi-check-lg"></i> Create order</button>
  </form>
</div></div></div>

<?php if ($detail): ?>
<div class="card shadow-sm mb-3 border-success"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between gap-2">
    <div>
      <h5 class="mb-1"><?= e($detail['order_number']) ?> <?= status_badge($detail['status']) ?></h5>
      <p class="text-muted small mb-0">Supplier: <strong><?= e($detail['supplier_name']) ?></strong> · Branch: <?= e($detail['branch_name']) ?> · Ordered by <?= e($detail['ordered_by_name'] ?? '') ?> · Total <?= money((float)$detail['total_amount']) ?><?= $detail['expected_date'] ? ' · Expected ' . e(fmt_date($detail['expected_date'])) : '' ?></p>
    </div>
    <?php if (in_array($detail['status'], ['draft', 'ordered', 'partially_received'], true)): ?>
    <form method="post" data-confirm="Cancel this purchase order? Received goods stay received."><?= csrf_field() ?><input type="hidden" name="_op" value="cancel"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Cancel order</button></form>
    <?php endif; ?>
  </div>

  <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Medicine</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Remaining</th><th class="text-end">Price</th><th class="text-end">Line total</th></tr></thead>
    <tbody>
    <?php foreach ($detailItems as $it): ?>
      <tr><td><?= e($it['name']) ?> <?= e($it['strength'] ?? '') ?></td>
      <td class="text-end"><?= (int)$it['quantity_ordered'] ?></td>
      <td class="text-end"><?= (int)$it['quantity_received'] ?></td>
      <td class="text-end"><?= (int)$it['quantity_ordered'] - (int)$it['quantity_received'] ?></td>
      <td class="text-end"><?= money((float)$it['purchase_price']) ?></td>
      <td class="text-end"><?= money((float)$it['total']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if ($receivable): ?>
  <hr>
  <h6 class="mb-2"><i class="bi bi-box-arrow-in-down text-emerald"></i> Receive goods (partial deliveries supported)</h6>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="_op" value="receive"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
    <?php foreach ($detailItems as $it): $remaining = (int)$it['quantity_ordered'] - (int)$it['quantity_received']; if ($remaining <= 0) continue; ?>
      <input type="hidden" name="po_item_id[]" value="<?= (int)$it['id'] ?>">
      <div class="row g-2 mb-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-0"><?= e($it['name']) ?> (<?= $remaining ?> outstanding)</label></div>
        <div class="col-md-1"><label class="form-label small mb-0">Qty</label><input type="number" min="0" max="<?= $remaining ?>" class="form-control form-control-sm" name="r_quantity[]" value="0"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Batch #</label><input class="form-control form-control-sm" name="r_batch_number[]" placeholder="Required if qty > 0"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Mfg date</label><input type="date" class="form-control form-control-sm" name="r_mfg[]"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Expiry date</label><input type="date" class="form-control form-control-sm" name="r_expiry[]"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Selling price</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="r_selling[]" value="0"></div>
      </div>
    <?php endforeach; ?>
    <button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-check-lg"></i> Receive goods</button>
    <span class="text-muted small ms-2">Receiving creates batches + stock movements and updates inventory valuation in one transaction.</span>
  </form>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (['ordered', 'partially_received', 'received', 'cancelled'] as $st): ?><option value="<?= $st ?>"<?= $status === $st ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $st))) ?></option><?php endforeach; ?>
  </select></div>
</form>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Order #</th><th>Supplier</th><th>Branch</th><th>Progress</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4">No purchase orders yet.</td></tr><?php endif; ?>
  <?php foreach ($list as $po): ?>
    <tr>
      <td><strong><?= e($po['order_number']) ?></strong><br><small class="text-muted"><?= e(fmt_date($po['created_at'])) ?></small></td>
      <td><?= e($po['supplier_name']) ?></td><td class="small"><?= e($po['branch_name']) ?></td>
      <td class="small"><?= (int)$po['received_units'] ?> / <?= (int)$po['ordered_units'] ?> units</td>
      <td class="text-end"><?= money((float)$po['total_amount']) ?></td>
      <td><?= status_badge($po['status']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url($base) ?>?id=<?= (int)$po['id'] ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<script>
(function () {
  var meds = <?= json_encode(array_map(static fn($m) => ['id' => (int)$m['id'], 'label' => $m['name'] . ($m['strength'] ? ' ' . $m['strength'] : ''), 'price' => (float)$m['default_purchase_price']], $meds)) ?>;
  var wrap = document.getElementById('poItems');
  function addRow() {
    var row = document.createElement('div');
    row.className = 'row g-2 mb-2';
    row.innerHTML = '<div class="col-md-5"><select class="form-select form-select-sm" name="medicine_id[]" required>'
      + meds.map(function (m) { return '<option value="' + m.id + '">' + m.label.replace(/</g, '&lt;') + '</option>'; }).join('') + '</select></div>'
      + '<div class="col-md-2"><input type="number" min="1" class="form-control form-control-sm" name="quantity[]" value="50" required></div>'
      + '<div class="col-md-3"><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="purchase_price[]" value="0" data-price></div>'
      + '<div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-del><i class="bi bi-x"></i> Remove</button></div>';
    var sel = row.querySelector('select');
    sel.addEventListener('change', function () {
      var m = meds.find(function (x) { return x.id === Number(sel.value); });
      if (m) row.querySelector('[data-price]').value = m.price;
    });
    sel.dispatchEvent(new Event('change'));
    row.querySelector('[data-del]').addEventListener('click', function () { row.remove(); });
    wrap.appendChild(row);
  }
  document.getElementById('poAdd').addEventListener('click', addRow);
  addRow();
})();
</script>
<?php admin_footer(); ?>
