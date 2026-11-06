<?php
/**
 * MediStock Cloud — Super Admin: subscription plans (super-admin tree).
 * Same plan catalogue as the legacy page (kept in sync — one table):
 * prices and usage limits (branches / staff / medicines / monthly sales).
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
admin_header('Subscription Plans', 'super-admin/plans.php');
Crud::handle([
    'title' => 'Plan', 'icon' => 'bi-layers', 'table' => 'subscription_plans',
    'permission' => 'dashboard.view', 'tenant' => false, 'base' => 'plans.php',
    'order' => 'sort_order ASC',
    'columns' => [
        'name' => 'Plan', 'key' => 'Key', 'monthly_price' => 'Monthly', 'yearly_price' => 'Yearly',
        'max_branches' => 'Branches', 'max_users' => 'Users', 'max_medicines' => 'Medicines',
        'max_monthly_transactions' => 'Sales/mo', 'is_active' => 'Active',
    ],
    'display' => [
        'monthly_price' => static fn($r) => money($r['monthly_price']),
        'yearly_price' => static fn($r) => money($r['yearly_price']),
        'max_branches' => static fn($r) => (int)$r['max_branches'] === 0 ? '∞' : $r['max_branches'],
        'max_users' => static fn($r) => (int)$r['max_users'] === 0 ? '∞' : $r['max_users'],
        'max_medicines' => static fn($r) => (int)$r['max_medicines'] === 0 ? '∞' : number_format((int)$r['max_medicines']),
        'max_monthly_transactions' => static fn($r) => (int)$r['max_monthly_transactions'] === 0 ? '∞' : number_format((int)$r['max_monthly_transactions']),
        'is_active' => static fn($r) => $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>',
    ],
    'search' => ['name', 'key'],
    'fields' => [
        'name' => ['label' => 'Plan name', 'required' => true],
        'key' => ['label' => 'Plan key', 'required' => true, 'help' => 'Unique machine key, e.g. professional'],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'col' => 'col-12'],
        'monthly_price' => ['label' => 'Monthly price', 'type' => 'number', 'step' => '0.01', 'required' => true],
        'yearly_price' => ['label' => 'Yearly price', 'type' => 'number', 'step' => '0.01', 'required' => true],
        'max_branches' => ['label' => 'Max branches (0 = unlimited)', 'type' => 'number'],
        'max_users' => ['label' => 'Max staff users (0 = unlimited)', 'type' => 'number'],
        'max_medicines' => ['label' => 'Max medicines (0 = unlimited)', 'type' => 'number'],
        'max_storage_mb' => ['label' => 'Max storage (MB)', 'type' => 'number'],
        'max_monthly_transactions' => ['label' => 'Max sales / month (0 = unlimited)', 'type' => 'number'],
        'features' => ['label' => 'Features (one per line)', 'type' => 'textarea', 'col' => 'col-12'],
        'sort_order' => ['label' => 'Sort order', 'type' => 'number'],
        'is_active' => ['label' => 'Visible on pricing page', 'type' => 'checkbox'],
    ],
]);
admin_footer();
