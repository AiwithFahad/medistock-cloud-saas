<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Pricing', 'pricing.php');
$plans = [];
try { $plans = SubscriptionService::plans(); } catch (Throwable $ignored) {}
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>Pricing</h1>
  <p class="lead text-muted">Transparent plans for every size of pharmacy. Start free, upgrade when you grow.</p>
  <div class="form-check form-switch d-inline-flex align-items-center gap-2 mt-2">
    <span>Monthly</span><input class="form-check-input" type="checkbox" id="billingToggle"><span>Yearly <span class="badge bg-emerald">2 months free</span></span>
  </div>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <div class="row g-4 justify-content-center">
    <?php foreach ($plans as $plan): ?>
      <div class="col-md-6 col-lg-3">
        <div class="price-card p-4 h-100 <?= $plan['key'] === 'professional' ? 'popular' : '' ?>">
          <?php if ($plan['key'] === 'professional'): ?><span class="badge bg-emerald mb-2">Most popular</span><?php endif; ?>
          <h5 class="text-navy"><?= e($plan['name']) ?></h5>
          <p class="text-muted small"><?= e($plan['description'] ?? '') ?></p>
          <div class="price-amount" data-monthly="<?= e(money($plan['monthly_price'])) ?>" data-yearly="<?= e(money($plan['yearly_price'])) ?>"><?= e(money($plan['monthly_price'])) ?></div>
          <div class="text-muted small billing-period">/ month</div>
          <ul class="list-unstyled small mt-3">
            <?php foreach (array_filter(array_map('trim', explode("\n", (string)($plan['features'] ?? '')))) as $f): ?>
              <li class="mb-1"><i class="bi bi-check text-emerald"></i> <?= e($f) ?></li>
            <?php endforeach; ?>
            <li class="mb-1"><i class="bi bi-check text-emerald"></i> <?= (int)$plan['max_branches'] === 0 ? 'Unlimited' : (int)$plan['max_branches'] ?> branches · <?= (int)$plan['max_users'] === 0 ? 'Unlimited' : (int)$plan['max_users'] ?> staff</li>
            <li class="mb-1"><i class="bi bi-check text-emerald"></i> <?= (int)$plan['max_medicines'] === 0 ? 'Unlimited' : number_format((int)$plan['max_medicines']) ?> medicines · <?= (int)$plan['max_monthly_transactions'] === 0 ? 'Unlimited' : number_format((int)$plan['max_monthly_transactions']) ?> sales/mo</li>
            <li class="mb-1"><i class="bi bi-check text-emerald"></i> <?= number_format((int)$plan['max_storage_mb']) ?> MB storage</li>
          </ul>
          <a class="btn <?= $plan['key'] === 'professional' ? 'btn-emerald' : 'btn-outline-emerald' ?> w-100 mt-2" href="<?= url('register.php') ?>">Start 14-day free trial</a>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$plans): ?><p class="text-center text-muted">Plans load from the platform database after installation.</p><?php endif; ?>
  </div>

  <div class="col-lg-9 mx-auto mt-5">
    <h3 class="fw-bold text-navy text-center mb-3">Billing, honestly explained</h3>
    <div class="row g-3">
      <div class="col-md-4"><div class="feature-card"><h6 class="text-navy"><i class="bi bi-shield-check text-emerald"></i> Verified payments only</h6><p class="text-muted small mb-0">A payment is never marked successful on our platform without verified confirmation from a payment provider or a platform administrator. Pending payments are always labelled pending.</p></div></div>
      <div class="col-md-4"><div class="feature-card"><h6 class="text-navy"><i class="bi bi-arrow-left-right text-emerald"></i> Upgrade or downgrade anytime</h6><p class="text-muted small mb-0">Switch plans whenever you like. Limits adjust immediately; your data never moves.</p></div></div>
      <div class="col-md-4"><div class="feature-card"><h6 class="text-navy"><i class="bi bi-hourglass-split text-emerald"></i> Grace period included</h6><p class="text-muted small mb-0">If a subscription expires, you get a grace period to renew before access is restricted. Your stock data is never deleted for a late payment.</p></div></div>
    </div>
  </div>
</div></section>
<?php site_footer(); ?>
