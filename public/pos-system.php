<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('POS System', 'pos-system.php');
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>The Pharmacy POS</h1>
  <p class="lead text-muted">Barcode-fast checkout with batch intelligence built in.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <div class="row align-items-center g-5">
    <div class="col-lg-6">
      <div class="card shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-between"><h6 class="fw-bold text-navy"><i class="bi bi-upc-scan text-emerald"></i> Point of Sale</h6><span class="badge bg-emerald">Branch: Main</span></div>
        <input class="form-control my-3" placeholder="Scan barcode or search medicine…" disabled>
        <table class="table table-sm"><tbody>
          <tr><td>Amoxicillin 250mg <small class="text-muted d-block">Batch AMX-118 · exp Aug 2026 · FEFO</small></td><td class="text-end">×3</td><td class="text-end">$10.50</td></tr>
          <tr><td>Ibuprofen 400mg <small class="text-muted d-block">Batch IBU-042 · exp Jan 2027</small></td><td class="text-end">×1</td><td class="text-end">$4.20</td></tr>
        </tbody></table>
        <div class="d-flex justify-content-between border-top pt-2"><span>Discount</span><span>−$1.00</span></div>
        <div class="d-flex justify-content-between"><span class="fw-bold">Total</span><strong class="text-emerald fs-5">$13.70</strong></div>
        <div class="d-flex gap-2 mt-3"><button class="btn btn-outline-secondary flex-fill" type="button">Cash</button><button class="btn btn-outline-secondary flex-fill" type="button">Card</button><button class="btn btn-emerald flex-fill" type="button">Charge</button></div>
      </div></div>
    </div>
    <div class="col-lg-6">
      <h3 class="fw-bold text-navy">Designed for the evening rush</h3>
      <ul class="list-unstyled mt-3">
        <li class="mb-3"><i class="bi bi-upc-scan text-emerald"></i> <strong>Barcode &amp; search:</strong> scan a code or type a name — only in-date, in-stock batches appear.</li>
        <li class="mb-3"><i class="bi bi-arrow-repeat text-emerald"></i> <strong>FEFO built in:</strong> the earliest-expiring batch is chosen automatically; cashiers can override with a reason.</li>
        <li class="mb-3"><i class="bi bi-percent text-emerald"></i> <strong>Discounts &amp; taxes:</strong> per-line discounts, configurable tax rate, split tenders.</li>
        <li class="mb-3"><i class="bi bi-receipt text-emerald"></i> <strong>Invoices &amp; receipts:</strong> every sale prints a professional invoice or a thermal-style receipt.</li>
        <li><i class="bi bi-arrow-counterclockwise text-emerald"></i> <strong>Returns:</strong> find the original sale, pick items, refund by the original method — stock restores itself.</li>
      </ul>
      <a class="btn btn-emerald" href="<?= url('register.php') ?>">Open your POS free</a>
    </div>
  </div>
</div></section>
<?php site_footer(); ?>
