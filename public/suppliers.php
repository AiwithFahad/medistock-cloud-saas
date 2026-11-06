<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Suppliers', 'suppliers.php');
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>Suppliers &amp; Purchase Management</h1>
  <p class="lead text-muted">Know who supplies what, at what price, and how reliably.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <div class="row g-4">
    <?php foreach ([
      ['bi-truck', 'Supplier directory', 'Contact people, phones, emails, addresses and payment terms for every distributor you buy from.'],
      ['bi-bag-plus', 'Guided receiving', 'Receive purchases line by line: batch numbers, manufacturing and expiry dates, purchase and selling prices.'],
      ['bi-database-check', 'Automatic batches', 'Receiving creates or tops up batches and posts every unit to the stock movement ledger.'],
      ['bi-graph-up', 'Supplier performance', 'Purchase totals per supplier, outstanding amounts and price history at a glance.'],
    ] as [$i, $t, $d]): ?>
      <div class="col-md-6"><div class="feature-card"><div class="feature-icon"><i class="bi <?= e($i) ?>"></i></div><h5 class="text-navy"><?= e($t) ?></h5><p class="text-muted mb-0"><?= e($d) ?></p></div></div>
    <?php endforeach; ?>
  </div>
  <div class="text-center mt-5"><a class="btn btn-emerald btn-lg" href="<?= url('register.php') ?>">Streamline your purchasing</a></div>
</div></section>
<?php site_footer(); ?>
