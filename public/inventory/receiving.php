<?php
/**
 * MediStock Cloud — Stock receiving & purchase returns (inventory hub).
 *  - Quick receive: goods in without a PO → StockService::receivePurchase
 *    (batches + valuation + movements in one transaction).
 *  - Purchase returns: send stock from a received purchase back to its
 *    supplier → StockService::createPurchaseReturn (batch decremented,
 *    permanent movement record, supplier balance reduced automatically).
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['inventory_manager', 'admin', 'owner']);
require_permission('purchases.manage');
$tid = tenant_id();
$pdo = db();
$uid = (int)$user['id'];

if (is_post()) {
    csrf_check();
    $op = (string)input('_op', '');
    try {
        if ($op === 'receive') {
            $items = [];
            foreach ((array)($_POST['medicine_id'] ?? []) as $i => $mid) {
                $qty = (int)($_POST['quantity'][$i] ?? 0);
                if ((int)$mid <= 0 || $qty <= 0) {
                    continue;
                }
                $items[] = [
                    'medicine_id' => (int)$mid,
                    'batch_number' => trim((string)($_POST['batch_number'][$i] ?? '')),
                    'manufacturing_date' => (string)($_POST['mfg'][$i] ?? ''),
                    'expiry_date' => (string)($_POST['expiry'][$i] ?? ''),
                    'quantity' => $qty,
                    'purchase_price' => (float)($_POST['purchase_price'][$i] ?? 0),
                    'selling_price' => (float)($_POST['selling_price'][$i] ?? 0),
                ];
            }
            if (!$items) {
                throw new RuntimeException('Add at least one line with a quantity.');
            }
            foreach ($items as $it) {
                if ($it['batch_number'] === '' || $it['expiry_date'] === '') {
                    throw new RuntimeException('Every received line needs a batch number and an expiry date.');
                }
            }
            $pid = StockService::receivePurchase($tid, (int)input('branch_id', 0), (int)input('supplier_id', 0), $items, $uid, trim((string)input('invoice_reference', '')) ?: null);
            flash('success', 'Purchase #' . $pid . ' received — batches, valuation and movements updated.');
            redirect('inventory/receiving.php');
        }
        if ($op === 'return') {
            $items = [];
            foreach ((array)($_POST['purchase_item_id'] ?? []) as $i => $pi) {
                $qty = (int)($_POST['r_quantity'][$i] ?? 0);
                if ($qty > 0) {
                    $items[] = ['purchase_item_id' => (int)$pi, 'quantity' => $qty];
                }
            }
            $rid = StockService::createPurchaseReturn($tid, (int)input('purchase_id', 0), $items, trim((string)input('reason', '')) ?: 'Returned to supplier', $uid);
            flash('success', 'Purchase return #' . $rid . ' recorded — stock decremented and supplier balance reduced.');
            redirect('inventory/receiving.php');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('inventory/receiving.php');
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

$purchases = $pdo->prepare(
    'SELECT p.id, p.invoice_reference, p.total_amount, p.created_at, s.name AS supplier_name FROM purchases p
       JOIN suppliers s ON s.id = p.supplier_id
      WHERE p.tenant_id = ? AND p.status = "received" ORDER BY p.id DESC LIMIT 15'
);
$purchases->execute([$tid]);
$purchases = $purchases->fetchAll();

$returnFor = null;
$returnItems = [];
if ((int)input('return_purchase', 0) > 0) {
    $stmt = $pdo->prepare('SELECT p.*, s.name AS supplier_name FROM purchases p JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = ? AND p.tenant_id = ?');
    $stmt->execute([(int)input('return_purchase', 0), $tid]);
    $returnFor = $stmt->fetch() ?: null;
    if ($returnFor) {
        $it = $pdo->prepare(
            'SELECT pi.*, m.name, b.batch_number, b.quantity AS batch_qty,
                    (SELECT COALESCE(SUM(pri.quantity), 0) FROM purchase_return_items pri JOIN purchase_returns pr ON pr.id = pri.purchase_return_id WHERE pri.purchase_item_id = pi.id AND pr.status = "completed") AS already_returned
               FROM purchase_items pi JOIN medicines m ON m.id = pi.medicine_id LEFT JOIN batches b ON b.id = pi.batch_id
              WHERE pi.purchase_id = ?'
        );
        $it->execute([(int)$returnFor['id']]);
        $returnItems = $it->fetchAll();
    }
}

$recentReturns = $pdo->prepare(
    'SELECT pr.*, s.name AS supplier_name FROM purchase_returns pr JOIN suppliers s ON s.id = pr.supplier_id
      WHERE pr.tenant_id = ? ORDER BY pr.id DESC LIMIT 8'
);
$recentReturns->execute([$tid]);
$recentReturns = $recentReturns->fetchAll();

admin_header('Stock Receiving', 'inventory/receiving.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-box-arrow-in-down text-emerald"></i> Stock Receiving & Purchase Returns</h4>
  <a class="btn btn-outline-secondary btn-sm" href="<?= url('inventory/purchase-orders.php') ?>"><i class="bi bi-clipboard2-check"></i> Receive against a PO instead</a>
</div>

<div class="card shadow-sm mb-3"><div class="card-body">
  <h6 class="mb-3">Quick receive (no purchase order)</h6>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="_op" value="receive">
    <div class="row g-2 mb-2">
      <div class="col-md-3"><label class="form-label small">Supplier</label>
        <select class="form-select form-select-sm" name="supplier_id" required>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label small">Branch</label>
        <select class="form-select form-select-sm" name="branch_id" required>
          <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?> (<?= e($b['code']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label small">Supplier invoice ref</label><input class="form-control form-control-sm" name="invoice_reference" placeholder="SUP-INV-…"></div>
    </div>
    <div id="rcvItems"></div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="rcvAdd"><i class="bi bi-plus-lg"></i> Add line</button>
    <button class="btn btn-emerald btn-sm ms-2" type="submit"><i class="bi bi-check-lg"></i> Receive stock</button>
  </form>
</div></div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3">Return goods to a supplier</h6>
      <form class="d-flex gap-2 mb-3" method="get">
        <select class="form-select form-select-sm" name="return_purchase">
          <option value="">Choose a received purchase…</option>
          <?php foreach ($purchases as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $returnFor && (int)$returnFor['id'] === (int)$p['id'] ? ' selected' : '' ?>>#<?= (int)$p['id'] ?> · <?= e($p['supplier_name']) ?> · <?= e($p['invoice_reference'] ?? '') ?> · <?= money((float)$p['total_amount']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm" type="submit">Load</button>
      </form>
      <?php if ($returnFor): ?>
      <form method="post" data-confirm="Return the entered quantities to the supplier? Batch stock will be decremented.">
        <?= csrf_field() ?><input type="hidden" name="_op" value="return"><input type="hidden" name="purchase_id" value="<?= (int)$returnFor['id'] ?>">
        <?php foreach ($returnItems as $it): $returnable = min((int)$it['quantity'] - (int)$it['already_returned'], (int)($it['batch_qty'] ?? 0)); ?>
          <div class="row g-2 mb-2 align-items-center">
            <input type="hidden" name="purchase_item_id[]" value="<?= (int)$it['id'] ?>">
            <div class="col-md-6"><strong class="small"><?= e($it['name']) ?></strong> <span class="text-muted small">batch <?= e($it['batch_number'] ?? '') ?> · returnable <?= $returnable ?></span></div>
            <div class="col-md-3"><input type="number" min="0" max="<?= $returnable ?>" class="form-control form-control-sm" name="r_quantity[]" value="0"></div>
            <div class="col-md-3 text-muted small">@ <?= money((float)$it['purchase_price']) ?></div>
          </div>
        <?php endforeach; ?>
        <div class="row g-2"><div class="col-md-9"><input class="form-control form-control-sm" name="reason" placeholder="Reason (e.g. damaged in transit, wrong item)"></div>
        <div class="col-md-3"><button class="btn btn-warning btn-sm w-100" type="submit"><i class="bi bi-arrow-return-left"></i> Process return</button></div></div>
      </form>
      <?php else: ?><p class="text-muted small mb-0">Pick a purchase to see its returnable lines.</p><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="mb-3">Recent purchase returns</h6>
      <?php if (!$recentReturns): ?><p class="text-muted small mb-0">No purchase returns yet.</p><?php endif; ?>
      <?php foreach ($recentReturns as $r): ?>
        <div class="kv-row"><span><strong>#<?= (int)$r['id'] ?></strong> · <?= e($r['supplier_name']) ?><br><small class="text-muted"><?= e($r['reason'] ?? '') ?> · <?= e(fmt_date($r['created_at'])) ?></small></span><strong><?= money((float)$r['total_amount']) ?></strong></div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>

<script>
(function () {
  var meds = <?= json_encode(array_map(static fn($m) => ['id' => (int)$m['id'], 'label' => $m['name'] . ($m['strength'] ? ' ' . $m['strength'] : ''), 'pp' => (float)$m['default_purchase_price'], 'sp' => (float)$m['default_selling_price']], $meds)) ?>;
  var wrap = document.getElementById('rcvItems');
  function addRow() {
    var row = document.createElement('div');
    row.className = 'row g-2 mb-2';
    row.innerHTML = '<div class="col-md-3"><select class="form-select form-select-sm" name="medicine_id[]" required>'
      + meds.map(function (m) { return '<option value="' + m.id + '">' + m.label.replace(/</g, '&lt;') + '</option>'; }).join('') + '</select></div>'
      + '<div class="col-md-2"><input class="form-control form-control-sm" name="batch_number[]" placeholder="Batch #" required></div>'
      + '<div class="col-md-2"><input type="date" class="form-control form-control-sm" name="expiry[]" required></div>'
      + '<div class="col-md-1"><input type="number" min="1" class="form-control form-control-sm" name="quantity[]" value="50" required></div>'
      + '<div class="col-md-1"><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="purchase_price[]" data-pp value="0"></div>'
      + '<div class="col-md-1"><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="selling_price[]" data-sp value="0"></div>'
      + '<div class="col-md-1"><input type="date" class="form-control form-control-sm" name="mfg[]"></div>'
      + '<div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-del><i class="bi bi-x"></i></button></div>';
    var sel = row.querySelector('select');
    sel.addEventListener('change', function () {
      var m = meds.find(function (x) { return x.id === Number(sel.value); });
      if (m) { row.querySelector('[data-pp]').value = m.pp; row.querySelector('[data-sp]').value = m.sp; }
    });
    sel.dispatchEvent(new Event('change'));
    row.querySelector('[data-del]').addEventListener('click', function () { row.remove(); });
    wrap.appendChild(row);
  }
  document.getElementById('rcvAdd').addEventListener('click', addRow);
  addRow();
})();
</script>
<?php admin_footer(); ?>
