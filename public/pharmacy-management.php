<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Pharmacy Management', 'pharmacy-management.php');
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>Pharmacy Management, End to End</h1>
  <p class="lead text-muted">Medicines, staff, customers, prescriptions and branches — one secure workspace per pharmacy.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <div class="row g-4">
    <?php foreach ([
      ['bi-capsule', 'Medicine catalogue', 'Generics, brands, categories, strengths, dosage forms, SKUs, barcodes and rack locations.'],
      ['bi-people', 'Staff & roles', 'Invite employees by email and control exactly what each role can see and do.'],
      ['bi-person-heart', 'Customers', 'Profiles with contact details, purchase history and outstanding balances.'],
      ['bi-file-earmark-medical', 'Prescriptions', 'Record prescriber, patient and items; link prescriptions to the sales they fulfil.'],
      ['bi-diagram-3', 'Branches', 'Open new locations as you grow, each with isolated stock and shared reporting.'],
      ['bi-gear', 'Pharmacy settings', 'Branding, currency, tax rate, receipt footer, thresholds and your stock policies.'],
    ] as [$i, $t, $d]): ?>
      <div class="col-md-6 col-lg-4"><div class="feature-card"><div class="feature-icon"><i class="bi <?= e($i) ?>"></i></div><h5 class="text-navy"><?= e($t) ?></h5><p class="text-muted mb-0"><?= e($d) ?></p></div></div>
    <?php endforeach; ?>
  </div>
  <div class="text-center mt-5"><a class="btn btn-emerald btn-lg" href="<?= url('register.php') ?>">Manage your pharmacy free for 14 days</a></div>
</div></section>
<?php site_footer(); ?>
