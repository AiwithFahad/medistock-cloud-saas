<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('pos.access');
$tid = tenant_id();
$pdo = db();

$branches = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? AND status = "active" AND deleted_at IS NULL ORDER BY is_main DESC, name');
$branches->execute([$tid]);
$branchList = $branches->fetchAll();
$branchId = (int)($_SESSION['pos_branch'] ?? ($branchList[0]['id'] ?? 0));
if ((int)input('branch_id', 0) > 0) {
    $branchId = (int)input('branch_id');
    $_SESSION['pos_branch'] = $branchId;
}

if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = []; // medicine_id => ['qty'=>int, 'discount'=>float]
}
$cart = &$_SESSION['pos_cart'];

/* ------------------------------------------------------------- Actions */
if (is_post()) {
    csrf_check();
    $op = (string)input('_op', '');
    try {
        if ($op === 'add') {
            $mid = (int)input('medicine_id', 0);
            if (StockService::availableQty($tid, $mid, $branchId) <= 0) {
                throw new RuntimeException('No in-date stock available for this medicine at this branch.');
            }
            $cart[$mid] = $cart[$mid] ?? ['qty' => 0, 'discount' => 0];
            $cart[$mid]['qty']++;
            redirect('admin/pos.php');
        }
        if ($op === 'update') {
            foreach ((array)input('qty', []) as $mid => $qty) {
                $mid = (int)$mid;
                if (!isset($cart[$mid])) continue;
                $qty = (int)$qty;
                if ($qty <= 0) { unset($cart[$mid]); continue; }
                $avail = StockService::availableQty($tid, $mid, $branchId);
                if ($qty > $avail && StockService::tenantSetting($tid, 'allow_negative_stock', '0') !== '1') {
                    throw new RuntimeException('Quantity exceeds available in-date stock (' . $avail . ').');
                }
                $cart[$mid]['qty'] = $qty;
                $cart[$mid]['discount'] = max(0, (float)(input('line_discount', [])[$mid] ?? 0));
            }
            redirect('admin/pos.php');
        }
        if ($op === 'clear') {
            $cart = [];
            redirect('admin/pos.php');
        }
        if ($op === 'checkout') {
            if (!$cart) {
                throw new RuntimeException('The cart is empty.');
            }
            $limitErr = SubscriptionService::limitError($tid, 'transactions');
            if ($limitErr) {
                throw new RuntimeException($limitErr);
            }
            $items = [];
            foreach ($cart as $mid => $line) {
                $items[] = ['medicine_id' => (int)$mid, 'quantity' => (int)$line['qty'], 'discount' => (float)$line['discount']];
            }
            $method = (string)input('payment_method', 'cash');
            $taxRate = (float)(StockService::tenantSetting($tid, 'tax_rate', '0') ?? 0);
            // Sale is created with a placeholder payment; the real payment row
            // is written by StockService with the grand total after tax.
            $saleId = StockService::createSale(
                $tid, $branchId,
                (int)input('customer_id', 0) ?: null,
                (int)input('prescription_id', 0) ?: null,
                $items,
                [['method' => $method, 'amount' => 0, 'reference' => (string)input('payment_reference', '')]],
                $taxRate,
                (int)$user['id']
            );
            // Fix the payment amount to the actual grand total.
            $gt = $pdo->prepare('SELECT grand_total FROM sales WHERE id = ?');
            $gt->execute([$saleId]);
            $pdo->prepare('UPDATE sale_payments SET amount = ? WHERE sale_id = ?')->execute([(float)$gt->fetchColumn(), $saleId]);
            $cart = [];
            redirect('admin/pos.php?receipt=' . $saleId);
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('admin/pos.php');
    }
}

/* -------------------------------------------------------- Product grid */
$q = trim((string)input('q', ''));
$sql = 'SELECT m.*,
               (SELECT COALESCE(SUM(b.quantity),0) FROM batches b WHERE b.medicine_id = m.id AND b.tenant_id = m.tenant_id AND b.branch_id = ? AND b.expiry_date >= CURDATE()) AS available,
               (SELECT b.selling_price FROM batches b WHERE b.medicine_id = m.id AND b.tenant_id = m.tenant_id AND b.branch_id = ? AND b.quantity > 0 AND b.expiry_date >= CURDATE() ORDER BY b.expiry_date ASC LIMIT 1) AS fefo_price,
               (SELECT b.batch_number FROM batches b WHERE b.medicine_id = m.id AND b.tenant_id = m.tenant_id AND b.branch_id = ? AND b.quantity > 0 AND b.expiry_date >= CURDATE() ORDER BY b.expiry_date ASC LIMIT 1) AS fefo_batch,
               (SELECT b.expiry_date FROM batches b WHERE b.medicine_id = m.id AND b.tenant_id = m.tenant_id AND b.branch_id = ? AND b.quantity > 0 AND b.expiry_date >= CURDATE() ORDER BY b.expiry_date ASC LIMIT 1) AS fefo_expiry
          FROM medicines m WHERE m.tenant_id = ? AND m.deleted_at IS NULL';
$params = [$branchId, $branchId, $branchId, $branchId, $tid];
if ($q !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.barcode = ? OR m.sku = ? OR m.generic_name LIKE ?)';
    array_push($params, "%$q%", $q, $q, "%$q%");
}
$sql .= ' ORDER BY m.name LIMIT 60';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

/* -------------------------------------------------------------- Cart */
$cartLines = [];
$subtotal = 0.0;
foreach ($cart as $mid => $line) {
    $s = $pdo->prepare('SELECT name, unit FROM medicines WHERE id = ? AND tenant_id = ?');
    $s->execute([$mid, $tid]);
    $med = $s->fetch();
    if (!$med) continue;
    $fefo = StockService::fefoBatches($tid, (int)$mid, $branchId);
    $price = $fefo ? (float)$fefo[0]['selling_price'] : 0.0;
    $lineTotal = $price * (int)$line['qty'] - (float)$line['discount'];
    $subtotal += $price * (int)$line['qty'];
    $cartLines[] = ['id' => (int)$mid, 'name' => $med['name'], 'unit' => $med['unit'], 'price' => $price,
                    'qty' => (int)$line['qty'], 'discount' => (float)$line['discount'], 'total' => $lineTotal,
                    'fefo' => $fefo[0] ?? null, 'available' => StockService::availableQty($tid, (int)$mid, $branchId)];
}
$discountTotal = array_sum(array_map(static fn($l) => $l['discount'], $cartLines));
$taxRate = (float)(StockService::tenantSetting($tid, 'tax_rate', '0') ?? 0);
$tax = round(max(0, $subtotal - $discountTotal) * $taxRate / 100, 2);
$grand = round($subtotal - $discountTotal + $tax, 2);

$customers = $pdo->prepare('SELECT id, name FROM customers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$customers->execute([$tid]);
$customerList = $customers->fetchAll();
$prescriptions = $pdo->prepare('SELECT id, prescription_number, patient_name FROM prescriptions WHERE tenant_id = ? AND status IN ("pending","partially_dispensed") AND deleted_at IS NULL ORDER BY id DESC LIMIT 50');
$prescriptions->execute([$tid]);
$rxList = $prescriptions->fetchAll();

/* ------------------------------------------------------------ Receipt */
$receipt = null;
$receiptItems = [];
$receiptId = (int)input('receipt', 0);
if ($receiptId > 0) {
    $rs = $pdo->prepare('SELECT s.*, c.name AS customer_name, br.name AS branch_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id LEFT JOIN branches br ON br.id = s.branch_id WHERE s.id = ? AND s.tenant_id = ?');
    $rs->execute([$receiptId, $tid]);
    $receipt = $rs->fetch() ?: null;
    if ($receipt) {
        $ri = $pdo->prepare('SELECT si.*, m.name AS medicine, b.batch_number FROM sale_items si JOIN medicines m ON m.id = si.medicine_id LEFT JOIN batches b ON b.id = si.batch_id WHERE si.sale_id = ?');
        $ri->execute([$receiptId]);
        $receiptItems = $ri->fetchAll();
    }
}

admin_header('Pharmacy POS', 'admin/pos.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-upc-scan text-emerald"></i> Pharmacy POS</h4>
  <form method="get" class="d-flex align-items-center gap-2">
    <label class="form-label mb-0 small text-muted">Branch</label>
    <select class="form-select form-select-sm" name="branch_id" onchange="this.form.submit()">
      <?php foreach ($branchList as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($receipt): ?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5">
  <div class="card shadow-sm"><div class="card-body receipt" id="receiptCard">
    <div class="text-center">
      <strong class="fs-6">MediStock Cloud Pharmacy</strong><br>
      <?= e($receipt['branch_name'] ?? '') ?><br>
      Invoice: <?= e($receipt['invoice_number']) ?><br>
      <?= fmt_date($receipt['created_at'], 'd M Y H:i') ?> · Customer: <?= e($receipt['customer_name'] ?? 'Walk-in') ?>
    </div>
    <hr>
    <?php foreach ($receiptItems as $it): ?>
      <div class="d-flex justify-content-between"><span><?= e($it['medicine']) ?><br><small><?= (int)$it['quantity'] ?> × <?= money($it['unit_price']) ?> · Batch <?= e($it['batch_number'] ?? '') ?></small></span><span><?= money($it['total']) ?></span></div>
    <?php endforeach; ?>
    <hr>
    <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= money($receipt['subtotal']) ?></span></div>
    <div class="d-flex justify-content-between"><span>Discount</span><span>−<?= money($receipt['discount']) ?></span></div>
    <div class="d-flex justify-content-between"><span>Tax</span><span><?= money($receipt['tax']) ?></span></div>
    <div class="d-flex justify-content-between fw-bold"><span>TOTAL</span><span><?= money($receipt['grand_total']) ?></span></div>
    <hr>
    <p class="text-center mb-0"><?= e(StockService::tenantSetting($tid, 'receipt_footer', 'Thank you for your purchase!') ?? '') ?></p>
  </div></div>
  <div class="d-flex gap-2 mt-3 no-print">
    <button class="btn btn-outline-secondary flex-fill" onclick="window.print()"><i class="bi bi-printer"></i> Print receipt</button>
    <a class="btn btn-emerald flex-fill" href="<?= url('admin/pos.php') ?>">New sale</a>
  </div>
</div></div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-7">
    <form class="mb-3" method="get">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
        <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Scan barcode or search medicine name / SKU…" autofocus>
        <button class="btn btn-emerald" type="submit">Search</button>
      </div>
      <div class="form-text">Only in-date, in-stock batches appear. The batch shown is the FEFO suggestion (earliest expiry first). Expired stock is never listed.</div>
    </form>
    <div class="row g-2">
      <?php foreach ($products as $p): ?>
        <div class="col-md-6 col-xl-4">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="_op" value="add"><input type="hidden" name="medicine_id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="pos-product w-100 text-start" <?= (int)$p['available'] <= 0 ? 'disabled' : '' ?>>
              <strong class="text-navy d-block"><?= e($p['name']) ?></strong>
              <small class="text-muted d-block"><?= e($p['strength'] ?? '') ?> · <?= e($p['dosage_form'] ?? '') ?> <?= $p['requires_prescription'] ? '· <span class="badge bg-warning text-dark">Rx</span>' : '' ?></small>
              <?php if ((int)$p['available'] > 0): ?>
                <small class="d-block mt-1">Batch <?= e($p['fefo_batch']) ?> · exp <?= fmt_date($p['fefo_expiry']) ?></small>
                <span class="d-flex justify-content-between mt-1"><span class="badge bg-success-subtle text-success"><?= (int)$p['available'] ?> available</span><strong class="text-emerald"><?= money($p['fefo_price']) ?></strong></span>
              <?php else: ?>
                <span class="badge bg-danger-subtle text-danger mt-1">Out of in-date stock</span>
              <?php endif; ?>
            </button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$products): ?><p class="text-muted">No medicines match. Add medicines and receive stock via Purchases first.</p><?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm pos-cart"><div class="card-body">
      <h6 class="fw-bold text-navy"><i class="bi bi-cart3"></i> Current sale</h6>
      <?php if (!$cartLines): ?>
        <p class="text-muted small mb-0">Cart is empty. Click a medicine to add it — FEFO picks the earliest-expiring batch and validates expiry automatically.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="_op" value="update">
          <table class="table table-sm align-middle">
            <thead><tr><th>Item</th><th style="width:80px">Qty</th><th style="width:90px">Disc.</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php foreach ($cartLines as $l): ?>
              <tr>
                <td><strong class="small"><?= e($l['name']) ?></strong><br><small class="text-muted"><?= money($l['price']) ?> · FEFO batch <?= e($l['fefo']['batch_number'] ?? '—') ?> · exp <?= $l['fefo'] ? fmt_date($l['fefo']['expiry_date']) : '—' ?> · <?= $l['available'] ?> avail.</small></td>
                <td><input class="form-control form-control-sm" type="number" min="0" name="qty[<?= $l['id'] ?>]" value="<?= $l['qty'] ?>"></td>
                <td><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="line_discount[<?= $l['id'] ?>]" value="<?= e($l['discount']) ?>"></td>
                <td class="text-end"><?= money($l['total']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-arrow-repeat"></i> Update cart</button>
        </form>
        <hr>
        <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= money($subtotal) ?></span></div>
        <div class="d-flex justify-content-between"><span>Discount</span><span>−<?= money($discountTotal) ?></span></div>
        <div class="d-flex justify-content-between"><span>Tax (<?= e($taxRate) ?>%)</span><span><?= money($tax) ?></span></div>
        <div class="d-flex justify-content-between fs-5"><span class="fw-bold">Total</span><strong class="text-emerald"><?= money($grand) ?></strong></div>
        <form method="post" class="mt-3">
          <?= csrf_field() ?><input type="hidden" name="_op" value="checkout">
          <div class="row g-2">
            <div class="col-6"><label class="form-label small">Customer</label>
              <select class="form-select form-select-sm" name="customer_id"><option value="">Walk-in customer</option>
              <?php foreach ($customerList as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small">Prescription</label>
              <select class="form-select form-select-sm" name="prescription_id"><option value="">None</option>
              <?php foreach ($rxList as $rx): ?><option value="<?= (int)$rx['id'] ?>"><?= e($rx['prescription_number']) ?> — <?= e($rx['patient_name'] ?? '') ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small">Payment method</label>
              <select class="form-select form-select-sm" name="payment_method">
                <option value="cash">Cash</option><option value="card">Card</option>
                <option value="mobile">Mobile money</option><option value="bank">Bank transfer</option><option value="split">Split payment</option>
              </select></div>
            <div class="col-6"><label class="form-label small">Payment reference</label><input class="form-control form-control-sm" name="payment_reference" placeholder="Optional"></div>
          </div>
          <button class="btn btn-emerald w-100 mt-3" type="submit"><i class="bi bi-check-circle"></i> Complete sale &amp; print receipt</button>
        </form>
        <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="_op" value="clear"><button class="btn btn-outline-danger btn-sm w-100" type="submit">Clear cart</button></form>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
