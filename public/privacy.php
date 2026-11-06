<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Privacy Policy', 'privacy.php');
?>
<section class="page-hero"><div class="container col-lg-8"><h1>Privacy Policy</h1><p class="text-muted">Last updated: <?= date('d F Y') ?></p></div></section>
<section class="section-pad pt-4"><div class="container col-lg-8">
  <h4 class="text-navy">1. Data we process</h4>
  <p class="text-muted">MediStock Cloud processes account data (names, emails, roles), pharmacy business data (medicines, batches, suppliers, customers, sales and prescriptions) and platform data (subscriptions, payments, support tickets and logs) solely to provide the service.</p>
  <h4 class="text-navy">2. Tenant isolation</h4>
  <p class="text-muted">Every business record carries a tenant identifier and every database query is scoped to it. One pharmacy can never access another pharmacy's data through the application. Platform administrators access tenant data only for support, billing and abuse prevention, and such access is audit-logged.</p>
  <h4 class="text-navy">3. Prescription &amp; health data</h4>
  <p class="text-muted">Prescription records are sensitive. Access is restricted by role-based permissions, all views are audit-logged, and tenants are responsible for their own regulatory retention policies, supported by configurable backup and export tools.</p>
  <h4 class="text-navy">4. Payments</h4>
  <p class="text-muted">We never store full card numbers. Payments are recorded with a status; a payment is only marked verified after confirmed provider or administrator verification.</p>
  <h4 class="text-navy">5. Security</h4>
  <p class="text-muted">Passwords are hashed with modern algorithms, sessions are hardened, all input is validated server-side and all queries use prepared statements. See our Security documentation in the repository for the full control list.</p>
  <h4 class="text-navy">6. Your rights</h4>
  <p class="text-muted">Tenants may export or delete their data at any time from the dashboard or by contacting support. Sub-processors and retention periods are documented in the deployment guide.</p>
  <h4 class="text-navy">7. Contact</h4>
  <p class="text-muted">Privacy questions: privacy@medistockcloud.example</p>
</div></section>
<?php site_footer(); ?>
