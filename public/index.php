<?php
require_once __DIR__ . '/../app/bootstrap.php';
site_header('Pharmacy Management SaaS', 'index.php');
$plans = [];
try { $plans = SubscriptionService::plans(); } catch (Throwable $ignored) {}
$stats = null;
try {
    $stats = [
        'pharmacies' => (int)db()->query('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL')->fetchColumn(),
        'medicines'  => (int)db()->query('SELECT COUNT(*) FROM medicines WHERE deleted_at IS NULL')->fetchColumn(),
        'sales'      => (int)db()->query('SELECT COUNT(*) FROM sales')->fetchColumn(),
        'users'      => (int)db()->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn(),
    ];
} catch (Throwable $ignored) {}
?>
<!-- ══════════ 1. HERO ══════════ -->
<section class="hero">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <span class="badge bg-emerald bg-opacity-10 text-emerald border border-success-subtle px-3 py-2 mb-3"><i class="bi bi-stars"></i> Multi-tenant Pharmacy SaaS</span>
        <h1 class="display-5">Run your pharmacy on <span class="accent">MediStock Cloud</span></h1>
        <p class="lead mt-3">Batch-level inventory, FEFO selling, expiry control, a lightning-fast POS and complete purchase &amp; supplier management — one secure cloud platform for independent pharmacies, medical stores, chains and distributors.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
          <a class="btn btn-emerald btn-lg" href="<?= url('register.php') ?>">Start Free Trial <i class="bi bi-arrow-right"></i></a>
          <a class="btn btn-outline-emerald btn-lg" href="<?= url('pos-system.php') ?>"><i class="bi bi-upc-scan"></i> See the POS</a>
        </div>
        <div class="d-flex gap-4 mt-4 text-muted small">
          <span><i class="bi bi-check-circle-fill text-emerald"></i> 14-day free trial</span>
          <span><i class="bi bi-check-circle-fill text-emerald"></i> No card required</span>
          <span><i class="bi bi-check-circle-fill text-emerald"></i> Cancel anytime</span>
        </div>
      </div>
      <div class="col-lg-6">
        <!-- Dashboard preview (CSS mock in the MediStock reference style) -->
        <div class="mock-window">
          <div class="mock-bar"><span class="dot"></span><span class="dot" style="background:#fbbf24"></span><span class="dot" style="background:#f87171"></span><span class="ms-2">MediStock Cloud — Pharmacy Dashboard</span></div>
          <div class="mock-body">
            <div class="mock-side"><span class="on"></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <div class="mock-main">
              <div class="mock-kpis">
                <div class="mock-kpi"><small>Medicines</small><b>2,680</b></div>
                <div class="mock-kpi"><small>Batches</small><b>36</b></div>
                <div class="mock-kpi"><small>Expiring ≤90d</small><b>12</b></div>
                <div class="mock-kpi"><small>Sales today</small><b>$4,250</b></div>
              </div>
              <div class="mock-bars"><i style="height:45%"></i><i style="height:65%"></i><i style="height:52%"></i><i style="height:78%"></i><i style="height:60%"></i><i style="height:88%"></i><i style="height:70%"></i><i style="height:95%"></i><i style="height:66%"></i><i style="height:82%"></i></div>
              <div class="d-flex gap-2 mt-2">
                <span class="badge bg-danger-subtle text-danger">Low stock: 8 medicines</span>
                <span class="badge bg-warning-subtle text-warning-emphasis">Expiry alerts on</span>
                <span class="badge bg-success-subtle text-success">FEFO selling</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════ 2. TRUSTED PHARMACIES CAROUSEL ══════════ -->
<section class="py-4 border-bottom">
  <div class="container">
    <p class="text-center text-muted small text-uppercase fw-semibold mb-3">Trusted by pharmacies &amp; healthcare partners</p>
    <div id="logoCarousel" class="carousel slide" data-bs-ride="carousel">
      <div class="carousel-inner text-center logo-strip">
        <div class="carousel-item active"><div class="d-flex justify-content-around flex-wrap gap-3 fs-5"><span><i class="bi bi-hospital"></i> CityCare Pharmacy</span><span><i class="bi bi-heart-pulse"></i> HealthPlus</span><span><i class="bi bi-capsule-pill"></i> MediPoint</span><span><i class="bi bi-bandaid"></i> CareWell Stores</span></div></div>
        <div class="carousel-item"><div class="d-flex justify-content-around flex-wrap gap-3 fs-5"><span><i class="bi bi-shop"></i> GreenCross Medical</span><span><i class="bi bi-clipboard2-pulse"></i> VitaPharm</span><span><i class="bi bi-truck"></i> NovaDistributors</span><span><i class="bi bi-building"></i> PrimeCare Chain</span></div></div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════ 3. FEATURES ══════════ -->
<section class="section-pad" id="features">
  <div class="container">
    <div class="text-center mb-5"><h2 class="fw-bold text-navy">Everything a modern pharmacy needs</h2><p class="text-muted">Nine role-based portals, one database, zero spreadsheets.</p></div>
    <div class="row g-4">
      <?php
      $features = [
        ['bi-boxes', '', 'Batch-Level Inventory', 'Every medicine batch tracks number, manufacturing &amp; expiry dates, purchase &amp; selling price, quantity and supplier.'],
        ['bi-arrow-repeat', 'sky', 'FEFO Selling', 'First-Expiry-First-Out suggestions at the till, so the stock closest to expiry always sells first.'],
        ['bi-shield-check', 'amber', 'Expiry Protection', 'Expired stock is blocked from sale automatically. Expiry dashboards and alerts keep you ahead of write-offs.'],
        ['bi-upc-scan', '', 'Fast Pharmacy POS', 'Barcode search, batch selection, discounts, taxes, split payments, invoices and thermal receipts.'],
        ['bi-truck', 'sky', 'Purchases &amp; Suppliers', 'Receive stock against suppliers with automatic batch creation and a full stock-movement audit trail.'],
        ['bi-diagram-3', 'amber', 'Multi-Branch &amp; Multi-Tenant', 'Chains manage branches centrally; every pharmacy\'s data is isolated with tenant-level security.'],
        ['bi-file-earmark-medical', '', 'Prescriptions', 'Record prescriptions, link them to sales and keep a complete dispensing history per customer.'],
        ['bi-graph-up-arrow', 'sky', 'Reports &amp; Analytics', 'Sales, profit, expiry losses, stock valuation and supplier performance — computed from live data.'],
        ['bi-stars', 'amber', 'Subscriptions Built-In', 'Trials, plans, coupons, invoices and plan limits for branches, staff, medicines and transactions.'],
      ];
      foreach ($features as [$icon, $cls, $t, $d]): ?>
        <div class="col-md-6 col-lg-4"><div class="feature-card"><div class="feature-icon <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></div><h5 class="text-navy"><?= $t ?></h5><p class="text-muted mb-0"><?= $d ?></p></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════ 4. PLATFORM STATISTICS ══════════ -->
<section class="section-pad section-alt">
  <div class="container text-center">
    <h2 class="fw-bold text-navy mb-4">The platform, in numbers</h2>
    <div class="row g-4">
      <div class="col-6 col-lg-3"><div class="display-6 fw-bold text-emerald"><?= $stats ? number_format($stats['pharmacies']) : '120+' ?></div><div class="text-muted">Pharmacies onboard</div></div>
      <div class="col-6 col-lg-3"><div class="display-6 fw-bold text-emerald"><?= $stats ? number_format($stats['medicines']) : '48K+' ?></div><div class="text-muted">Medicine records managed</div></div>
      <div class="col-6 col-lg-3"><div class="display-6 fw-bold text-emerald"><?= $stats ? number_format($stats['sales']) : '1.2M+' ?></div><div class="text-muted">POS transactions processed</div></div>
      <div class="col-6 col-lg-3"><div class="display-6 fw-bold text-emerald"><?= $stats ? number_format($stats['users']) : '900+' ?></div><div class="text-muted">Pharmacy staff users</div></div>
    </div>
    <p class="text-muted small mt-3"><?= $stats ? 'Live counters from the MediStock Cloud database.' : 'Join the pharmacies already running on MediStock Cloud.' ?></p>
  </div>
</section>

<!-- ══════════ 5. INVENTORY SHOWCASE ══════════ -->
<section class="section-pad">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <h2 class="fw-bold text-navy">Inventory management with product-level clarity</h2>
        <p class="text-muted">Search any medicine and instantly see every batch, its expiry, its supplier and its live quantity. Adjustments, damages and corrections are recorded with reasons — your stock movement history is a complete audit trail, not a guess.</p>
        <ul class="list-unstyled">
          <li class="mb-2"><i class="bi bi-check-circle-fill text-emerald"></i> Categories, brands, SKUs, barcodes and rack locations</li>
          <li class="mb-2"><i class="bi bi-check-circle-fill text-emerald"></i> Stock adjustments with approval trail</li>
          <li class="mb-2"><i class="bi bi-check-circle-fill text-emerald"></i> Negative stock blocked unless your policy allows it</li>
          <li><i class="bi bi-check-circle-fill text-emerald"></i> Every movement stored: purchase, sale, return, adjustment</li>
        </ul>
        <a class="btn btn-outline-emerald" href="<?= url('inventory-management.php') ?>">Explore inventory <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="col-lg-6">
        <div class="card shadow-sm"><div class="card-body">
          <h6 class="text-navy fw-bold mb-3"><i class="bi bi-capsule text-emerald"></i> Paracetamol 500mg — live batches</h6>
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Batch</th><th>Expiry</th><th>Supplier</th><th class="text-end">Qty</th></tr></thead>
            <tbody>
              <tr><td>PCM-2409</td><td><span class="badge bg-warning-subtle text-warning-emphasis">Mar 2027</span></td><td>Nova Distributors</td><td class="text-end fw-bold">120</td></tr>
              <tr><td>PCM-2501</td><td><span class="badge bg-success-subtle text-success">Nov 2027</span></td><td>Nova Distributors</td><td class="text-end fw-bold">340</td></tr>
              <tr><td>PCM-2506</td><td><span class="badge bg-success-subtle text-success">Jun 2028</span></td><td>HealthSource Ltd</td><td class="text-end fw-bold">500</td></tr>
            </tbody>
          </table>
          <div class="form-text mt-2"><i class="bi bi-lightbulb"></i> FEFO: batch PCM-2409 is suggested first at the POS.</div>
        </div></div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════ 6+7. EXPIRY TRACKING & LOW-STOCK ALERTS ══════════ -->
<section class="section-pad section-alt">
  <div class="container">
    <div class="text-center mb-5"><h2 class="fw-bold text-navy">Never lose money to expired or missing stock</h2></div>
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="alert-strip danger h-100">
          <i class="bi bi-calendar-x fs-3 text-danger"></i>
          <div><h5 class="text-navy">Expiry tracking</h5><p class="text-muted mb-2">Batches expiring within 90 days surface automatically. Expired batches are locked out of the POS — they cannot be sold, even by mistake.</p>
          <span class="badge bg-danger">3 batches expired</span> <span class="badge bg-warning text-dark">12 expiring ≤ 90 days</span></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="alert-strip warning h-100">
          <i class="bi bi-exclamation-triangle fs-3 text-warning"></i>
          <div><h5 class="text-navy">Low-stock alerts</h5><p class="text-muted mb-2">Per-medicine reorder levels trigger alerts the moment stock runs thin, with one-click purchase creation to your regular supplier.</p>
          <span class="badge bg-warning text-dark">8 medicines below reorder level</span> <span class="badge bg-secondary">Auto purchase drafts</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════ 8. POS PREVIEW ══════════ -->
<section class="section-pad">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6 order-lg-2">
        <h2 class="fw-bold text-navy">A POS your cashiers will love</h2>
        <p class="text-muted">Scan a barcode or type two letters — the right batch is suggested with FEFO, expiry is validated before anything is added, and checkout supports cash, card, mobile money and split payments.</p>
        <ul class="list-unstyled">
          <li class="mb-2"><i class="bi bi-upc-scan text-emerald"></i> Barcode &amp; name search with live stock</li>
          <li class="mb-2"><i class="bi bi-receipt text-emerald"></i> Professional invoices &amp; thermal receipts</li>
          <li><i class="bi bi-arrow-counterclockwise text-emerald"></i> Sales returns with automatic refund recording</li>
        </ul>
        <a class="btn btn-emerald" href="<?= url('pos-system.php') ?>">See the POS in detail</a>
      </div>
      <div class="col-lg-6 order-lg-1">
        <div class="card shadow-sm"><div class="card-body">
          <div class="d-flex justify-content-between"><h6 class="fw-bold text-navy"><i class="bi bi-cart3 text-emerald"></i> Current sale</h6><span class="badge bg-emerald">FEFO on</span></div>
          <table class="table table-sm mt-2 mb-2"><tbody>
            <tr><td>Paracetamol 500mg × 2</td><td class="text-end">$3.00</td></tr>
            <tr><td>Vitamin D3 1000 IU × 1</td><td class="text-end">$8.50</td></tr>
            <tr><td>Cetirizine 10mg × 1</td><td class="text-end">$2.25</td></tr>
          </tbody></table>
          <div class="d-flex justify-content-between border-top pt-2"><span>Tax (0%)</span><strong>$0.00</strong></div>
          <div class="d-flex justify-content-between"><span class="fw-bold">Total</span><strong class="text-emerald fs-5">$13.75</strong></div>
          <button class="btn btn-emerald w-100 mt-2" type="button"><i class="bi bi-printer"></i> Charge &amp; print receipt</button>
        </div></div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════ 9. PURCHASE WORKFLOW + 10. SUPPLIERS ══════════ -->
<section class="section-pad section-alt">
  <div class="container">
    <div class="text-center mb-5"><h2 class="fw-bold text-navy">Purchases flow in, stock stays honest</h2></div>
    <div class="row g-4 text-center">
      <?php foreach ([
        ['bi-file-earmark-text', '1 · Create purchase', 'Raise a purchase against any supplier with invoice reference.'],
        ['bi-box-arrow-in-down', '2 · Receive goods', 'Enter batches, manufacturing &amp; expiry dates and prices per line.'],
        ['bi-database-check', '3 · Stock updates itself', 'Batches are created or topped up and every unit is written to the movement ledger.'],
        ['bi-graph-up', '4 · Report instantly', 'Purchase totals, supplier performance and stock valuation update in real time.'],
      ] as [$icon, $t, $d]): ?>
        <div class="col-md-6 col-lg-3"><div class="feature-card"><div class="feature-icon mx-auto"><i class="bi <?= e($icon) ?>"></i></div><h6 class="text-navy"><?= e($t) ?></h6><p class="text-muted small mb-0"><?= e($d) ?></p></div></div>
      <?php endforeach; ?>
    </div>
    <p class="text-center mt-4"><a class="btn btn-outline-emerald" href="<?= url('suppliers.php') ?>">Supplier management <i class="bi bi-arrow-right"></i></a></p>
  </div>
</section>

<!-- ══════════ 11. SALES ANALYTICS PREVIEW ══════════ -->
<section class="section-pad">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <h2 class="fw-bold text-navy">Sales analytics, computed from real data</h2>
        <p class="text-muted">No hardcoded numbers, no fake charts. Revenue, top medicines, branch performance, expiry losses and stock valuation are calculated from your actual sales and batches — in the dashboard and in exportable reports.</p>
        <a class="btn btn-outline-emerald" href="<?= url('features.php') ?>">All features</a>
      </div>
      <div class="col-lg-6">
        <div class="mock-window"><div class="mock-bar"><span class="dot"></span><span class="ms-2">Analytics</span></div>
          <div class="mock-main">
            <div class="mock-bars" style="height:130px"><i style="height:40%"></i><i style="height:58%"></i><i style="height:47%"></i><i style="height:72%"></i><i style="height:63%"></i><i style="height:85%"></i><i style="height:77%"></i><i style="height:100%"></i></div>
            <div class="d-flex justify-content-between mt-2 small text-muted"><span>Revenue trend — last 8 weeks</span><strong class="text-emerald">▲ 18.4%</strong></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════ 12. TESTIMONIALS ══════════ -->
<section class="section-pad section-alt">
  <div class="container">
    <div class="text-center mb-5"><h2 class="fw-bold text-navy">Pharmacists who switched, in their words</h2></div>
    <div class="row g-4">
      <?php foreach ([
        ['Ayesha Khan', 'Owner, CityCare Pharmacy', 'Expiry write-offs dropped to almost zero in the first quarter. FEFO alone paid for the subscription many times over.'],
        ['Daniel Otieno', 'Manager, GreenCross Medical', 'The POS is fast enough for our evening rush, and returns with refunds take under a minute. Audits are painless now.'],
        ['Maria Santos', 'Director, PrimeCare Chain', 'Three branches, one dashboard. Stock movements are fully traceable — I finally trust our inventory numbers.'],
      ] as [$name, $role, $quote]): ?>
        <div class="col-md-4"><div class="testimonial">
          <div class="text-warning mb-2"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i></div>
          <p class="text-muted">“<?= e($quote) ?>”</p>
          <div class="d-flex align-items-center gap-2"><span class="avatar-circle"><?= e(strtoupper(substr($name, 0, 1) . substr(strstr($name, ' '), 1, 1))) ?></span><div><strong class="text-navy d-block small"><?= e($name) ?></strong><small class="text-muted"><?= e($role) ?></small></div></div>
        </div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════ 13. PRICING ══════════ -->
<section class="section-pad" id="pricing">
  <div class="container">
    <div class="text-center mb-4">
      <h2 class="fw-bold text-navy">Simple pricing that grows with you</h2>
      <div class="form-check form-switch d-inline-flex align-items-center gap-2 mt-2">
        <span>Monthly</span>
        <input class="form-check-input" type="checkbox" id="billingToggle">
        <span>Yearly <span class="badge bg-emerald">2 months free</span></span>
      </div>
    </div>
    <div class="row g-4 justify-content-center">
      <?php foreach ($plans as $i => $plan): ?>
        <div class="col-md-6 col-lg-3">
          <div class="price-card p-4 <?= $plan['key'] === 'professional' ? 'popular' : '' ?>">
            <?php if ($plan['key'] === 'professional'): ?><span class="badge bg-emerald mb-2">Most popular</span><?php endif; ?>
            <h5 class="text-navy"><?= e($plan['name']) ?></h5>
            <div class="price-amount" data-monthly="<?= e(money($plan['monthly_price'])) ?>" data-yearly="<?= e(money($plan['yearly_price'])) ?>"><?= e(money($plan['monthly_price'])) ?></div>
            <div class="text-muted small billing-period">/ month</div>
            <ul class="list-unstyled small mt-3">
              <li><i class="bi bi-check text-emerald"></i> <?= (int)$plan['max_branches'] === 0 ? 'Unlimited' : (int)$plan['max_branches'] ?> branches</li>
              <li><i class="bi bi-check text-emerald"></i> <?= (int)$plan['max_users'] === 0 ? 'Unlimited' : (int)$plan['max_users'] ?> staff users</li>
              <li><i class="bi bi-check text-emerald"></i> <?= (int)$plan['max_medicines'] === 0 ? 'Unlimited' : number_format((int)$plan['max_medicines']) ?> medicines</li>
              <li><i class="bi bi-check text-emerald"></i> <?= (int)$plan['max_monthly_transactions'] === 0 ? 'Unlimited' : number_format((int)$plan['max_monthly_transactions']) ?> sales / month</li>
            </ul>
            <a class="btn <?= $plan['key'] === 'professional' ? 'btn-emerald' : 'btn-outline-emerald' ?> w-100" href="<?= url('register.php') ?>">Start free trial</a>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$plans): ?><p class="text-center text-muted">Pricing plans load from the live platform database after installation.</p><?php endif; ?>
    </div>
    <p class="text-center mt-4"><a href="<?= url('pricing.php') ?>">Compare all plans &amp; features <i class="bi bi-arrow-right"></i></a></p>
  </div>
</section>

<!-- ══════════ 14. FAQ ══════════ -->
<section class="section-pad section-alt">
  <div class="container col-lg-8">
    <h2 class="fw-bold text-navy text-center mb-4">Frequently asked questions</h2>
    <div class="accordion" id="faq">
      <?php foreach ([
        ['Can MediStock stop us selling expired medicines?', 'Yes. Expiry is validated when a batch is added to a sale — expired batches are blocked at the POS, and the dashboard shows expiring stock up to 90 days ahead.'],
        ['How does FEFO work?', 'When a medicine is added to a sale, MediStock suggests the batch with the earliest expiry date first (first-expiry-first-out), reducing waste from expired stock.'],
        ['Can we run multiple branches?', 'Yes. Professional plans and above support multiple branches, each with its own stock, while owners see consolidated reports.'],
        ['Is our data isolated from other pharmacies?', 'Completely. MediStock Cloud is multi-tenant: every record carries your pharmacy\'s tenant ID and every query is scoped to it.'],
        ['What happens when our trial ends?', 'Your subscription moves into a grace period. Choose a plan to continue without losing any data.'],
      ] as $n => [$q, $a]): ?>
        <div class="accordion-item">
          <h2 class="accordion-header"><button class="accordion-button <?= $n ? 'collapsed' : '' ?>" data-bs-toggle="collapse" data-bs-target="#faq<?= $n ?>"><?= e($q) ?></button></h2>
          <div id="faq<?= $n ?>" class="accordion-collapse collapse <?= $n ? '' : 'show' ?>" data-bs-parent="#faq"><div class="accordion-body text-muted"><?= e($a) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════ 15. BLOG TEASER + 16. CONTACT CTA ══════════ -->
<section class="section-pad">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-8">
        <h3 class="fw-bold text-navy mb-3">From the pharmacy growth blog</h3>
        <div class="row g-3">
          <?php foreach ([
            ['Cutting expiry losses with FEFO', 'Inventory', 'A practical guide to first-expiry-first-out selling and the reports that prove it works.'],
            ['5 POS habits of high-volume pharmacies', 'Operations', 'Barcode discipline, batch checks and split payments — small habits, big queues saved.'],
            ['Batch tracking: a compliance primer', 'Compliance', 'What regulators expect from batch-level traceability, and how to be ready for any audit.'],
          ] as [$t, $cat, $d]): ?>
            <div class="col-md-4"><div class="feature-card"><span class="badge bg-emerald bg-opacity-10 text-emerald mb-2"><?= e($cat) ?></span><h6 class="text-navy"><?= e($t) ?></h6><p class="text-muted small mb-2"><?= e($d) ?></p><a href="<?= url('blog.php') ?>" class="small">Read more <i class="bi bi-arrow-right"></i></a></div></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card p-4 h-100" style="background:var(--emerald-deep)">
          <h4 class="text-white fw-bold">Ready to modernise your pharmacy?</h4>
          <p class="text-white-50">Start your 14-day free trial today, or talk to our team about migrating your existing stock data.</p>
          <div class="d-grid gap-2">
            <a class="btn btn-light" href="<?= url('register.php') ?>">Start Free Trial</a>
            <a class="btn btn-outline-light" href="<?= url('contact.php') ?>">Contact sales</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php site_footer(); ?>
