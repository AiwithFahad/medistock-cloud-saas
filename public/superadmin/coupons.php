<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
admin_header('Coupons', 'superadmin/coupons.php');
Crud::handle([
    'title' => 'Coupon', 'icon' => 'bi-ticket-perforated', 'table' => 'coupons',
    'permission' => 'dashboard.view', 'tenant' => false, 'base' => 'coupons.php',
    'order' => 'id DESC',
    'columns' => ['code' => 'Code', 'type' => 'Type', 'value' => 'Value', 'times_used' => 'Used', 'max_uses' => 'Max uses', 'expires_at' => 'Expires', 'is_active' => 'Active'],
    'display' => [
        'value' => static fn($r) => $r['type'] === 'percent' ? $r['value'] . '%' : money($r['value']),
        'is_active' => static fn($r) => $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Off</span>',
    ],
    'search' => ['code'],
    'fields' => [
        'code' => ['label' => 'Coupon code', 'required' => true, 'help' => 'Customers enter this at checkout, e.g. WELCOME10'],
        'type' => ['label' => 'Discount type', 'type' => 'select', 'options' => ['percent' => 'Percentage', 'fixed' => 'Fixed amount'], 'required' => true],
        'value' => ['label' => 'Value', 'type' => 'number', 'step' => '0.01', 'required' => true],
        'max_uses' => ['label' => 'Max uses (blank = unlimited)', 'type' => 'number'],
        'starts_at' => ['label' => 'Starts at', 'type' => 'datetime-local'],
        'expires_at' => ['label' => 'Expires at', 'type' => 'datetime-local'],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1],
    ],
    'before_save' => static function (array $data, ?int $id): array {
        $data['code'] = strtoupper(trim((string)$data['code']));
        foreach (['starts_at', 'expires_at'] as $k) {
            $data[$k] = !empty($data[$k]) ? str_replace('T', ' ', $data[$k]) . ':00' : null;
        }
        if ($data['max_uses'] === '' || $data['max_uses'] === null) {
            $data['max_uses'] = null;
        }
        return $data;
    },
]);
admin_footer();
