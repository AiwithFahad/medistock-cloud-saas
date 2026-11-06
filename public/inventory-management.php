<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Inventory Management', 'inventory-management.php');
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>Inventory Management Built on Batches</h1>
  <p class="lead text-muted">The only inventory model that matches how pharmacies actually work.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <div class="row g-4">
    <div class="col-lg-6"><div class="feature-card">
      <h5 class="text-navy"><i class="bi bi-box-seam text-emerald"></i> Batch truth</h5>
      <p class="text-muted">A medicine is not a number. It is a set of batches, each with its own expiry date, supplier and cost. MediStock tracks all of it: batch number, manufacturing date, expiry date, purchase price, selling price and live quantity — per branch.</p>
    </div></div>
    <div class="col-lg-6"><div class="feature-card">
      <h5 class="text-navy"><i class="bi bi-arrow-left-right text-emerald"></i> A ledger, not a guess</h5>
      <p class="text-muted">Every change writes a stock movement: purchases add, sales subtract, returns restore, adjustments explain themselves with reasons. Filter the ledger by medicine, batch, type or date and export it for any audit.</p>
    </div></div>
    <div class="col-lg-6"><div class="feature-card">
      <h5 class="text-navy"><i class="bi bi-calendar-x text-emerald"></i> Expiry &amp; low-stock radar</h5>
      <p class="text-muted">Dedicated screens list expired batches and everything expiring within 30, 60 or 90 days, plus every medicine sitting below its reorder level — with supplier shortcuts to fix both.</p>
    </div></div>
    <div class="col-lg-6"><div class="feature-card">
      <h5 class="text-navy"><i class="bi bi-shield-lock text-emerald"></i> Guard rails</h5>
      <p class="text-muted">Expired stock cannot be sold. Stock cannot go negative unless an authorised policy in your settings explicitly permits it. Sales, purchases, returns and adjustments all run inside database transactions, so partial writes never corrupt your counts.</p>
    </div></div>
  </div>
  <div class="text-center mt-5"><a class="btn btn-emerald btn-lg" href="<?= url('register.php') ?>">Take control of your inventory</a></div>
</div></section>
<?php site_footer(); ?>
