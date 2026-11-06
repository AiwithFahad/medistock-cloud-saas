<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Branches', 'admin/branches.php');
Crud::handle([
    'title' => 'Branch', 'icon' => 'bi-diagram-3', 'table' => 'branches',
    'permission' => 'branches.manage', 'base' => 'branches.php',
    'columns' => ['name' => 'Branch', 'code' => 'Code', 'phone' => 'Phone', 'city' => 'City', 'is_main' => 'Main', 'status' => 'Status'],
    'display' => ['is_main' => static fn($r) => $r['is_main'] ? '<span class="badge bg-emerald">Main branch</span>' : ''],
    'search' => ['name', 'code', 'city'],
    'fields' => [
        'name' => ['label' => 'Branch name', 'required' => true],
        'code' => ['label' => 'Branch code', 'required' => true, 'help' => 'Short unique code, e.g. MAIN, BR-02'],
        'phone' => ['label' => 'Phone'],
        'email' => ['label' => 'Email', 'type' => 'email'],
        'address' => ['label' => 'Address', 'type' => 'textarea', 'col' => 'col-12'],
        'city' => ['label' => 'City'],
        'is_main' => ['label' => 'Main branch', 'type' => 'checkbox'],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'default' => 'active'],
    ],
    'before_save' => static function (array $data, ?int $id): array {
        if ($id === null) {
            $err = SubscriptionService::limitError(tenant_id(), 'branches');
            if ($err) {
                throw new RuntimeException($err);
            }
        }
        return $data;
    },
]);
admin_footer();
