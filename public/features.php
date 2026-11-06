<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Features', 'features.php');
$groups = [
  ['Inventory & Stock', 'bi-boxes', [
    ['Batch-level stock', 'Batch number, manufacturing & expiry dates, purchase/selling price, quantity and supplier on every batch.'],
    ['FEFO allocation', 'First-expiry-first-out batch suggestions at sale time and in purchase planning.'],
    ['Expiry management', 'Dedicated dashboards for expired stock and batches expiring within 30/60/90 days.'],
    ['Low-stock alerts', 'Per-medicine reorder levels with instant alerts and purchase drafts.'],
    ['Stock adjustments', 'Damage, loss and correction adjustments with reason codes and full history.'],
    ['Movement ledger', 'Every purchase, sale, return and adjustment is an auditable stock movement.'],
  ]],
  ['Sales & POS', 'bi-upc-scan', [
    ['Barcode POS', 'Scan or search medicines; stock and expiry are validated before items enter the cart.'],
    ['Flexible checkout', 'Discounts, taxes, cash/card/mobile/split payments and change calculation.'],
    ['Invoices & receipts', 'Professional invoices plus printable thermal-style receipts for every sale.'],
    ['Returns & refunds', 'Return items to stock (when in date) with automatic refund recording.'],
    ['Prescriptions', 'Attach prescriptions to customers and sales for a complete dispensing record.'],
    ['Customers', 'Customer profiles with purchase history, balances and prescription records.'],
  ]],
  ['Business & SaaS', 'bi-stars', [
    ['Multi-tenant isolation', 'Every pharmacy is a tenant; every query is scoped — no cross-tenant access, ever.'],
    ['Role-based access', 'Owner, Admin, Pharmacist, Cashier, Inventory Manager, Accountant and Staff roles with granular permissions.'],
    ['Subscriptions', 'Trial, Starter, Professional, Business and Enterprise plans with monthly/yearly billing.'],
    ['Coupons & invoices', 'Percentage and fixed coupons, generated invoices and verified payment records.'],
    ['Plan limits', 'Branches, staff, medicine records, storage and monthly transactions enforced per plan.'],
    ['Super Admin platform', 'Tenant management, revenue analytics, support tickets, announcements and platform settings.'],
  ]],
];
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>Features</h1><p class="lead text-muted">A complete commercial pharmacy platform — not a template. Explore what each module does.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <?php foreach ($groups as [$title, $icon, $items]): ?>
    <h3 class="fw-bold text-navy mt-4 mb-3"><i class="bi <?= e($icon) ?> text-emerald"></i> <?= e($title) ?></h3>
    <div class="row g-4 mb-3">
      <?php foreach ($items as [$t, $d]): ?>
        <div class="col-md-6 col-lg-4"><div class="feature-card"><h5 class="text-navy"><?= e($t) ?></h5><p class="text-muted mb-0"><?= e($d) ?></p></div></div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <div class="text-center mt-5"><a class="btn btn-emerald btn-lg" href="<?= url('register.php') ?>">Try every feature free for 14 days</a></div>
</div></section>
<?php site_footer(); ?>
