<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Solutions', 'solutions.php');
$solutions = [
  ['bi-shop', 'Independent Pharmacies', 'One store, full control: batch stock, a fast till and reports that used to take a bookkeeper all weekend — done in one click.'],
  ['bi-building', 'Medical Stores', 'Smaller catalogues still need expiry discipline. MediStock keeps over-the-counter stock compliant and accounted for.'],
  ['bi-diagram-3', 'Pharmacy Chains', 'Central catalogue, branch-level stock, consolidated revenue and per-branch staff roles across every location.'],
  ['bi-truck', 'Distributors', 'Track batches from purchase to branch transfer with a movement ledger your auditors will love.'],
  ['bi-hospital', 'Hospital Pharmacies', 'Prescription-linked dispensing, department issues and strict batch traceability for clinical environments.'],
  ['bi-globe', 'Online Pharmacies', 'API-ready architecture and accurate live stock counts keep your storefront honest about availability.'],
];
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>Solutions for every kind of pharmacy</h1>
  <p class="lead text-muted">One platform, configured for how you actually operate.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <div class="row g-4">
    <?php foreach ($solutions as [$i, $t, $d]): ?>
      <div class="col-md-6 col-lg-4"><div class="feature-card"><div class="feature-icon"><i class="bi <?= e($i) ?>"></i></div><h5 class="text-navy"><?= e($t) ?></h5><p class="text-muted mb-0"><?= e($d) ?></p></div></div>
    <?php endforeach; ?>
  </div>
  <div class="text-center mt-5"><a class="btn btn-emerald btn-lg" href="<?= url('register.php') ?>">Start free — set up in minutes</a></div>
</div></section>
<?php site_footer(); ?>
