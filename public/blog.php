<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Blog', 'blog.php');
$posts = [
  ['Cutting expiry losses with FEFO', 'Inventory', 'Most pharmacies write off 1–3% of stock value to expiry every year. First-expiry-first-out selling attacks that number directly: the batch closest to expiry is always the batch that sells. Here is how to set reorder levels, read the 90-day expiry radar and turn near-expiry stock into promotions instead of write-offs.'],
  ['5 POS habits of high-volume pharmacies', 'Operations', 'Fast pharmacies are not lucky — they are disciplined. Barcode-first scanning, FEFO trust, preset discounts, split-payment fluency and a returns routine under sixty seconds. Steal these five habits.'],
  ['Batch tracking: a compliance primer', 'Compliance', 'When a manufacturer recalls a batch, regulators expect you to find every unit: purchased, sold, returned, still on shelf. Batch-level records with a movement ledger make that a five-minute query instead of a five-day panic.'],
  ['Reorder levels that actually work', 'Inventory', 'A reorder level is a promise to your future self. We show the simple formula (lead-time demand + safety stock), how MediStock alerts you, and how to review levels quarterly.'],
  ['From one store to a chain', 'Growth', 'Branches change everything: stock transfers, per-branch staff, consolidated reports. A field guide to scaling a pharmacy group without losing control of inventory.'],
  ['Why your stock count is wrong (and how to fix it)', 'Operations', 'Shrinkage, unrecorded damages and partial data entry quietly corrupt counts. Transactional stock movements and reason-coded adjustments keep the ledger — and the shelf — in agreement.'],
];
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>The MediStock Blog</h1><p class="lead text-muted">Practical pharmacy operations, inventory science and growth.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <div class="row g-4">
    <?php foreach ($posts as [$t, $cat, $body]): ?>
      <div class="col-md-6 col-lg-4"><article class="feature-card">
        <span class="badge bg-emerald bg-opacity-10 text-emerald mb-2"><?= e($cat) ?></span>
        <h5 class="text-navy"><?= e($t) ?></h5>
        <p class="text-muted small"><?= e($body) ?></p>
        <span class="text-muted small"><i class="bi bi-calendar3"></i> <?= date('d M Y') ?> · MediStock Team</span>
      </article></div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php site_footer(); ?>
