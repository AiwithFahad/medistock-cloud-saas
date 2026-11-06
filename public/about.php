<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('About Us', 'about.php');
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>About MediStock Cloud</h1>
  <p class="lead text-muted">We build the software that lets pharmacies focus on patients, not paperwork.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <div class="row g-5 align-items-center">
    <div class="col-lg-6">
      <h2 class="fw-bold text-navy">Why we built MediStock</h2>
      <p class="text-muted">Independent pharmacies lose money in three quiet ways: expired stock that should have been sold first, shelves that run empty at the worst moment, and records that can't survive an audit. Spreadsheets and generic POS systems don't solve any of them, because none of them understand batches.</p>
      <p class="text-muted">MediStock Cloud was designed pharmacy-first: every unit of stock lives in a batch with an expiry date, every sale follows FEFO, and every movement is written to a permanent ledger. On top of that foundation we built the commercial SaaS layer — subscriptions, multi-branch chains, role-based staff portals and a platform Super Admin — so a single store and a national chain run on the same trustworthy core.</p>
    </div>
    <div class="col-lg-6">
      <div class="row g-3">
        <?php foreach ([
          ['bi-bullseye', 'Our mission', 'Make professional pharmacy management affordable for every store, everywhere.'],
          ['bi-eye', 'Our vision', 'A world where no medicine expires unsold on a shelf it was tracked on.'],
          ['bi-shield-lock', 'Our promise', 'Your data is yours: tenant-isolated, audit-logged, exportable and never sold.'],
          ['bi-people', 'Our team', 'Pharmacists, PHP engineers and support specialists across three time zones.'],
        ] as [$i, $t, $d]): ?>
          <div class="col-md-6"><div class="feature-card"><div class="feature-icon"><i class="bi <?= e($i) ?>"></i></div><h5 class="text-navy"><?= e($t) ?></h5><p class="text-muted mb-0"><?= e($d) ?></p></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="text-center mt-5">
    <h3 class="fw-bold text-navy">Join the pharmacies running on MediStock Cloud</h3>
    <a class="btn btn-emerald btn-lg mt-2" href="<?= url('register.php') ?>">Start your free trial</a>
  </div>
</div></section>
<?php site_footer(); ?>
