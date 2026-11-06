<?php
/**
 * MediStock Cloud — view layouts.
 * site_header()/site_footer() render the public marketing website.
 * admin_header()/admin_footer() render the Super Admin and Pharmacy
 * dashboards (dark-green sidebar, emerald accents, collapsible on mobile).
 */

/* --------------------------------------------------------- Public website */

function site_header(string $title, string $active = ''): void
{
    $app = $GLOBALS['app_config'];
    $links = [
        'index.php' => 'Home', 'about.php' => 'About', 'features.php' => 'Features',
        'pricing.php' => 'Pricing', 'solutions.php' => 'Solutions', 'blog.php' => 'Blog',
        'contact.php' => 'Contact',
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($app['name']) ?></title>
<meta name="description" content="MediStock Cloud — the multi-tenant pharmacy management SaaS: batch inventory, expiry tracking, POS, purchases and suppliers in one emerald dashboard.">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= asset('css/style.css') ?>" rel="stylesheet">
</head>
<body class="site-body">
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
  <div class="container">
    <a class="navbar-brand brand" href="<?= url('index.php') ?>"><span class="brand-badge"><i class="bi bi-capsule"></i></span> MediStock <span class="text-emerald">Cloud</span></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="siteNav">
      <ul class="navbar-nav mx-auto">
        <?php foreach ($links as $href => $label): ?>
          <li class="nav-item"><a class="nav-link <?= $active === $href ? 'active fw-semibold text-emerald' : '' ?>" href="<?= url($href) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-emerald" href="<?= url('login.php') ?>">Sign in</a>
        <a class="btn btn-emerald" href="<?= url('register.php') ?>">Start Free Trial</a>
      </div>
    </div>
  </div>
</nav>
<?php
}

function site_footer(): void
{
    ?>
<footer class="site-footer mt-0">
  <div class="container py-5">
    <div class="row g-4">
      <div class="col-md-4">
        <div class="brand text-white mb-2"><span class="brand-badge"><i class="bi bi-capsule"></i></span> MediStock <span class="text-emerald-light">Cloud</span></div>
        <p class="text-white-50 small">The pharmacy management SaaS for independent pharmacies, medical stores, chains and distributors. Batch-level inventory, FEFO selling, expiry control and a fast POS — in one secure cloud platform.</p>
      </div>
      <div class="col-md-2">
        <h6 class="text-white">Product</h6>
        <ul class="footer-links">
          <li><a href="<?= url('features.php') ?>">Features</a></li>
          <li><a href="<?= url('pricing.php') ?>">Pricing</a></li>
          <li><a href="<?= url('pharmacy-management.php') ?>">Pharmacy Management</a></li>
          <li><a href="<?= url('inventory-management.php') ?>">Inventory Management</a></li>
          <li><a href="<?= url('pos-system.php') ?>">POS System</a></li>
        </ul>
      </div>
      <div class="col-md-2">
        <h6 class="text-white">Company</h6>
        <ul class="footer-links">
          <li><a href="<?= url('about.php') ?>">About</a></li>
          <li><a href="<?= url('blog.php') ?>">Blog</a></li>
          <li><a href="<?= url('suppliers.php') ?>">Suppliers</a></li>
          <li><a href="<?= url('contact.php') ?>">Contact</a></li>
        </ul>
      </div>
      <div class="col-md-2">
        <h6 class="text-white">Legal</h6>
        <ul class="footer-links">
          <li><a href="<?= url('privacy.php') ?>">Privacy Policy</a></li>
          <li><a href="<?= url('terms.php') ?>">Terms of Service</a></li>
          <li><a href="<?= url('login.php') ?>">Sign in</a></li>
          <li><a href="<?= url('register.php') ?>">Register</a></li>
        </ul>
      </div>
      <div class="col-md-2">
        <h6 class="text-white">Stay updated</h6>
        <form method="post" action="<?= url('contact.php') ?>" class="d-flex">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="newsletter">
          <input class="form-control form-control-sm me-1" type="email" name="email" placeholder="Email address" required>
          <button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-send"></i></button>
        </form>
        <p class="text-white-50 small mt-2">Pharmacy growth tips, once a month. No spam.</p>
      </div>
    </div>
    <hr class="border-secondary">
    <div class="d-flex flex-wrap justify-content-between text-white-50 small">
      <span>© <?= date('Y') ?> MediStock Cloud. All rights reserved.</span>
      <span>Core PHP 8 · MySQL · Bootstrap 5</span>
    </div>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('js/main.js') ?>"></script>
</body>
</html>
<?php
}

/* -------------------------------------------------------------- Dashboards */

function admin_menu(): array
{
    if (is_super_admin()) {
        // Platform console: the super-admin/ tree is the primary console;
        // the remaining platform modules stay linked below it.
        return [
            ['super-admin/dashboard.php', 'bi-speedometer2', 'Dashboard', null],
            ['super-admin/pharmacies.php', 'bi-building', 'Pharmacies', null],
            ['super-admin/users.php', 'bi-people', 'Users', null],
            ['super-admin/plans.php', 'bi-layers', 'Plans', null],
            ['super-admin/subscriptions.php', 'bi-arrow-repeat', 'Subscriptions', null],
            ['super-admin/payments.php', 'bi-credit-card', 'Payments', null],
            ['super-admin/reports.php', 'bi-graph-up-arrow', 'Reports', null],
            ['super-admin/settings.php', 'bi-gear', 'Platform Settings', null],
            ['superadmin/coupons.php', 'bi-ticket-perforated', 'Coupons', null],
            ['superadmin/invoices.php', 'bi-receipt', 'Invoices', null],
            ['superadmin/analytics.php', 'bi-bar-chart', 'Analytics (legacy)', null],
            ['superadmin/tickets.php', 'bi-life-preserver', 'Support Tickets', null],
            ['superadmin/announcements.php', 'bi-megaphone', 'Announcements', null],
            ['superadmin/logs.php', 'bi-clock-history', 'Activity Logs', null],
        ];
    }
    // Tenant (pharmacy) menu — each item gated by its RBAC permission.
    $role = current_user()['role_key'] ?? '';
    $posHref = in_array($role, ['cashier', 'pharmacist'], true) ? 'cashier/pos.php' : 'admin/pos.php';
    $items = [
        ['admin/dashboard.php', 'bi-speedometer2', 'Overview', 'dashboard.view'],
        [$posHref, 'bi-upc-scan', 'Pharmacy POS', 'pos.access'],
        ['admin/sales.php', 'bi-cart-check', 'Sales', 'sales.view'],
        ['admin/returns.php', 'bi-arrow-counterclockwise', 'Sales Returns', 'sales.manage'],
        ['admin/medicines.php', 'bi-capsule', 'Medicines', 'medicines.view'],
        ['admin/categories.php', 'bi-tags', 'Categories', 'medicines.view'],
        ['admin/brands.php', 'bi-award', 'Brands', 'medicines.view'],
        ['admin/batches.php', 'bi-box-seam', 'Batches', 'stock.view'],
        ['admin/inventory.php', 'bi-boxes', 'Inventory', 'stock.view'],
        ['admin/adjustments.php', 'bi-sliders', 'Stock Adjustments', 'stock.adjust'],
        ['admin/movements.php', 'bi-arrow-repeat', 'Stock Movements', 'stock.view'],
        ['admin/transfers.php', 'bi-arrow-left-right', 'Branch Transfers', 'stock.view'],
        ['admin/expiry.php', 'bi-calendar-x', 'Expiry Management', 'stock.view'],
        ['admin/lowstock.php', 'bi-exclamation-triangle', 'Low Stock Alerts', 'stock.view'],
        ['admin/purchases.php', 'bi-bag-plus', 'Purchases', 'purchases.manage'],
        ['admin/purchase-orders.php', 'bi-clipboard2-check', 'Purchase Orders', 'purchases.manage'],
        ['admin/suppliers.php', 'bi-truck', 'Suppliers', 'suppliers.manage'],
        ['admin/supplier-payments.php', 'bi-cash-coin', 'Supplier Payments', 'purchases.manage'],
        ['admin/customers.php', 'bi-person-heart', 'Customers', 'customers.manage'],
        ['admin/prescriptions.php', 'bi-file-earmark-medical', 'Prescriptions', 'prescriptions.manage'],
        ['admin/expenses.php', 'bi-wallet2', 'Expenses', 'expenses.manage'],
        ['admin/staff.php', 'bi-people', 'Staff', 'staff.manage'],
        ['admin/branches.php', 'bi-diagram-3', 'Branches', 'branches.manage'],
        ['admin/reports.php', 'bi-graph-up', 'Reports & Analytics', 'reports.view'],
        ['admin/subscription.php', 'bi-stars', 'Subscription', 'billing.manage'],
        ['admin/settings.php', 'bi-gear', 'Settings', 'settings.manage'],
    ];
    $items = array_values(array_filter($items, static fn($i) => $i[3] === null || can($i[3])));

    // Role workspaces come first in the navigation for the roles that have
    // a dedicated dashboard tree (server-side RBAC still applies per page).
    $roleItems = match ($role) {
        'pharmacist' => [
            ['pharmacist/dashboard.php', 'bi-clipboard2-pulse', 'Pharmacist Home', null],
            ['pharmacist/prescriptions.php', 'bi-file-earmark-medical', 'My Prescriptions', null],
            ['pharmacist/medicines.php', 'bi-search-heart', 'Medicine Search', null],
            ['pharmacist/dispensing.php', 'bi-clipboard-check', 'Dispensing Records', null],
            ['pharmacist/sales.php', 'bi-receipt', 'Sales History', null],
        ],
        'cashier' => [
            ['cashier/dashboard.php', 'bi-cash-register', 'Cashier Home', null],
            ['cashier/sales.php', 'bi-receipt', 'My Sales', null],
        ],
        'inventory_manager' => [
            ['inventory/dashboard.php', 'bi-boxes', 'Inventory Home', null],
            ['inventory/receiving.php', 'bi-box-arrow-in-down', 'Stock Receiving', null],
            ['inventory/transfers.php', 'bi-arrow-left-right', 'Branch Transfers', null],
            ['inventory/purchase-orders.php', 'bi-clipboard2-check', 'Purchase Orders', null],
            ['inventory/alerts.php', 'bi-bell', 'Stock Alerts', null],
            ['inventory/suppliers.php', 'bi-truck', 'Suppliers', null],
        ],
        default => [],
    };
    return array_merge($roleItems, $items);
}

function admin_header(string $title, string $active = ''): void
{
    $app = $GLOBALS['app_config'];
    $u = current_user();
    $notifications = [];
    if ($u) {
        $stmt = db()->prepare(
            'SELECT * FROM notifications WHERE (user_id = ? OR (tenant_id = ? AND user_id IS NULL)) AND read_at IS NULL ORDER BY id DESC LIMIT 6'
        );
        $stmt->execute([$u['id'], $u['tenant_id']]);
        $notifications = $stmt->fetchAll();
    }
    $initials = '';
    foreach (explode(' ', (string)($u['name'] ?? 'U')) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($app['name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= asset('css/style.css') ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="admin-body">
<div class="admin-wrapper">
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand"><span class="brand-badge"><i class="bi bi-capsule"></i></span> MediStock <span>Cloud</span></div>
    <div class="sidebar-role"><?= e(is_super_admin() ? 'Platform Super Admin' : ($u['role_name'] ?? '')) ?></div>
    <nav class="sidebar-nav">
      <?php foreach (admin_menu() as [$href, $icon, $label, $perm]): ?>
        <a href="<?= url($href) ?>" class="<?= $active === $href ? 'active' : '' ?>"><i class="bi <?= e($icon) ?>"></i> <span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <a href="<?= url(is_super_admin() ? 'index.php' : 'admin/index.php') ?>"><i class="bi bi-house"></i> <span>Back to app</span></a>
      <a href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> <span>Sign out</span></a>
    </div>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle" aria-label="Toggle sidebar"><i class="bi bi-list"></i></button>
      <form class="topbar-search d-none d-md-flex" method="get" action="<?= url(is_super_admin() ? 'superadmin/tenants.php' : 'admin/medicines.php') ?>">
        <i class="bi bi-search"></i>
        <input class="form-control form-control-sm border-0" name="q" placeholder="Search medicines, pharmacies, customers…">
      </form>
      <div class="ms-auto d-flex align-items-center gap-3">
        <div class="dropdown">
          <button class="btn btn-sm btn-light position-relative" data-bs-toggle="dropdown" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($notifications): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= count($notifications) ?></span><?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:300px">
            <li class="dropdown-header">Notifications</li>
            <?php if (!$notifications): ?><li><span class="dropdown-item-text text-muted small">You are all caught up.</span></li><?php endif; ?>
            <?php foreach ($notifications as $n): ?>
              <li><span class="dropdown-item-text"><strong class="small"><?= e($n['title']) ?></strong><br><span class="text-muted small"><?= e($n['body']) ?></span></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="dropdown">
          <button class="btn btn-sm btn-light d-flex align-items-center gap-2" data-bs-toggle="dropdown">
            <span class="avatar-circle"><?= e(substr($initials, 0, 2)) ?></span>
            <span class="d-none d-md-inline text-start"><strong class="d-block small lh-1"><?= e($u['name'] ?? '') ?></strong><small class="text-muted"><?= e($u['email'] ?? '') ?></small></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-item-text small text-muted"><?= e($u['role_name'] ?? '') ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <?php if (!is_super_admin()): ?><li><a class="dropdown-item" href="<?= url('admin/settings.php') ?>"><i class="bi bi-gear"></i> Pharmacy settings</a></li><?php endif; ?>
            <li><a class="dropdown-item" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> Sign out</a></li>
          </ul>
        </div>
      </div>
    </header>
    <main class="admin-content">
      <?= flash_html() ?>
<?php
}

function admin_footer(): void
{
    $extraJs = $GLOBALS['extra_js'] ?? [];
    ?>
    </main>
    <footer class="admin-footer">MediStock Cloud © <?= date('Y') ?> · Built with Core PHP 8, MySQL & Bootstrap 5</footer>
  </div>
</div>
<!-- Shared JS layer mounts: toast notifications + confirm dialog (app.js) -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="msToasts" style="z-index:1090"></div>
<div class="modal fade" id="msConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body">
        <div class="d-flex gap-3 align-items-start">
          <span class="stat-icon stat-rose flex-shrink-0" style="width:38px;height:38px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center"><i class="bi bi-exclamation-triangle"></i></span>
          <div><h6 class="mb-1">Please confirm</h6><p class="small text-muted mb-0" id="msConfirmText">Are you sure?</p></div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ms-confirm="no">Cancel</button>
        <button type="button" class="btn btn-sm btn-danger" data-ms-confirm="yes">Yes, continue</button>
      </div>
    </div>
  </div>
</div>
<script>window.MS_CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('js/main.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php foreach ($extraJs as $js): ?>
<script src="<?= asset($js) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Small stat card used across dashboards. */
function stat_card(string $icon, string $label, $value, string $class = 'emerald', string $sub = ''): void
{
    echo '<div class="col-6 col-xl-3"><div class="stat-card stat-' . e($class) . '">'
        . '<div class="stat-icon"><i class="bi ' . e($icon) . '"></i></div>'
        . '<div><div class="stat-value">' . e($value) . '</div><div class="stat-label">' . e($label) . '</div>'
        . ($sub !== '' ? '<div class="stat-sub">' . e($sub) . '</div>' : '')
        . '</div></div></div>';
}
