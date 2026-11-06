<?php
/**
 * MediStock Cloud — JavaScript Point of Sale (cashier workspace).
 *
 * The cart, batch picker and split payments run in assets/js/pos.js and
 * talk to api/pos-search.php / api/customers.php / api/pos-checkout.php.
 * All prices, discounts, tax, stock and expiry are re-validated
 * server-side at checkout — the JS totals are display-only.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('pos.access');
$tid = tenant_id();
$pdo = db();

$branches = $pdo->prepare('SELECT * FROM branches WHERE tenant_id = ? AND deleted_at IS NULL AND status = "active" ORDER BY is_main DESC, name');
$branches->execute([$tid]);
$branches = $branches->fetchAll();
$taxRate = (float)StockService::tenantSetting($tid, 'tax_rate', '0');
$rxStmt = $pdo->prepare('SELECT id, prescription_number, patient_name FROM prescriptions WHERE tenant_id = ? AND status IN ("pending","partially_dispensed") AND deleted_at IS NULL ORDER BY id DESC LIMIT 50');
$rxStmt->execute([$tid]);
$prescriptions = $rxStmt->fetchAll();

$GLOBALS['extra_js'] = ['js/pos.js'];
admin_header('Point of Sale', 'cashier/pos.php');
?>
<script>
window.POS_CONFIG = {
  searchUrl: <?= json_encode(url('api/pos-search.php')) ?>,
  checkoutUrl: <?= json_encode(url('api/pos-checkout.php')) ?>,
  customersUrl: <?= json_encode(url('api/customers.php')) ?>,
  taxRate: <?= json_encode($taxRate) ?>
};
</script>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-upc-scan text-emerald"></i> Point of Sale</h4>
  <div class="d-flex align-items-center gap-2">
    <label class="form-label mb-0 small text-muted" for="posBranch">Branch</label>
    <select class="form-select form-select-sm" id="posBranch" style="min-width:190px">
      <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?> (<?= e($b['code']) ?>)</option><?php endforeach; ?>
    </select>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input class="form-control" id="posSearch" placeholder="Search by name, generic name, SKU — or scan a barcode and press Enter" autocomplete="off">
      </div>
      <div class="pos-results-grid mt-3" id="posResults"><p class="text-muted small mb-0">Loading medicines…</p></div>
      <p class="text-muted small mt-2 mb-0"><i class="bi bi-shield-check"></i> Expired batches are never sellable. FEFO (first-expiry-first-out) is applied automatically unless you pin a batch in the cart.</p>
    </div></div>

    <div class="card shadow-sm d-none" id="posReceiptCard"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0"><i class="bi bi-receipt text-emerald"></i> Last sale receipt</h6>
        <button class="btn btn-emerald btn-sm" id="posPrintBtn"><i class="bi bi-printer"></i> Print receipt</button>
      </div>
      <div id="posReceiptHtml"></div>
    </div></div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm pos-cart"><div class="card-body">
      <h6 class="mb-2"><i class="bi bi-cart3"></i> Current cart</h6>
      <div id="posCartBody"></div>
      <hr>
      <div class="position-relative mb-2">
        <input class="form-control form-control-sm" id="posCustomerSearch" placeholder="Customer (optional — type to search)" autocomplete="off">
        <input type="hidden" id="posCustomerId">
        <div class="list-group position-absolute w-100 shadow-sm" id="posCustomerResults" style="z-index:20"></div>
      </div>
      <select class="form-select form-select-sm mb-2" id="posRx">
        <option value="">Prescription (required for Rx medicines)</option>
        <?php foreach ($prescriptions as $rx): ?><option value="<?= (int)$rx['id'] ?>"><?= e($rx['prescription_number']) ?> — <?= e($rx['patient_name'] ?? '') ?></option><?php endforeach; ?>
      </select>
      <div class="kv-row"><span>Subtotal</span><strong id="posSubtotal">0.00</strong></div>
      <div class="kv-row"><span>Discount</span><strong id="posDiscountTotal">0.00</strong></div>
      <div class="kv-row"><span>Tax (<?= e(rtrim(rtrim(number_format($taxRate, 2), '0'), '.')) ?>%)</span><strong id="posTax">0.00</strong></div>
      <div class="kv-row"><span>Grand total</span><strong class="fs-5" id="posGrand">0.00</strong></div>
      <h6 class="mt-3 mb-1 small text-muted">PAYMENTS (SPLIT ALLOWED)</h6>
      <div id="posPayments"></div>
      <div class="d-flex justify-content-between align-items-center mt-1">
        <span id="posChange" class="small"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="posAddPayment"><i class="bi bi-plus-lg"></i> Add payment</button>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="button" class="btn btn-outline-danger" id="posClear"><i class="bi bi-trash"></i> Clear</button>
        <button type="button" class="btn btn-emerald flex-grow-1" id="posCheckout"><i class="bi bi-check2-circle"></i> Checkout</button>
      </div>
      <p class="text-muted small mt-2 mb-0">Totals are recomputed and validated on the server when you check out.</p>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
