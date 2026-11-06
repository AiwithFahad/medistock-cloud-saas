<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Terms of Service', 'terms.php');
?>
<section class="page-hero"><div class="container col-lg-8"><h1>Terms of Service</h1><p class="text-muted">Last updated: <?= date('d F Y') ?></p></div></section>
<section class="section-pad pt-4"><div class="container col-lg-8">
  <h4 class="text-navy">1. The service</h4>
  <p class="text-muted">MediStock Cloud is a multi-tenant pharmacy management platform provided on a subscription basis. Features and limits depend on the plan you select and are shown on the Pricing page and in your subscription dashboard.</p>
  <h4 class="text-navy">2. Accounts</h4>
  <p class="text-muted">You are responsible for your account credentials and for all activity under your tenant. Employee access must be granted through the invitation system so that actions remain attributable in audit logs.</p>
  <h4 class="text-navy">3. Billing</h4>
  <p class="text-muted">Subscriptions renew according to the chosen cycle. Manually recorded payments remain "pending" until verified; access follows the subscription status including any grace period. Fees for elapsed periods are non-refundable except where required by law.</p>
  <h4 class="text-navy">4. Acceptable use</h4>
  <p class="text-muted">You must not use the service to store unlawful content, attempt to access another tenant's data, or interfere with platform operation. We may suspend accounts that violate these terms, with notice where practicable.</p>
  <h4 class="text-navy">5. Data ownership</h4>
  <p class="text-muted">Your business data remains yours. On cancellation you may export your data; retained backups are purged according to the retention schedule in the Privacy Policy.</p>
  <h4 class="text-navy">6. Liability</h4>
  <p class="text-muted">The service is provided "as is" with reasonable care. Stock decisions, pricing and dispensing remain the professional responsibility of the pharmacy.</p>
  <h4 class="text-navy">7. Changes</h4>
  <p class="text-muted">We may update these terms; material changes will be announced in the dashboard at least 14 days before they take effect.</p>
</div></section>
<?php site_footer(); ?>
