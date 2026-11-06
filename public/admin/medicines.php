<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Medicines', 'admin/medicines.php');
Crud::handle([
    'title' => 'Medicine', 'icon' => 'bi-capsule', 'table' => 'medicines',
    'permission' => 'medicines.view', 'base' => 'medicines.php',
    'columns' => [
        'name' => 'Medicine', 'generic_name' => 'Generic', 'sku' => 'SKU', 'barcode' => 'Barcode',
        'strength' => 'Strength', 'unit' => 'Unit', 'reorder_level' => 'Reorder level', 'requires_prescription' => 'Rx',
    ],
    'display' => [
        'requires_prescription' => static fn($r) => $r['requires_prescription'] ? '<span class="badge bg-warning text-dark">Rx</span>' : '<span class="badge bg-light text-dark">OTC</span>',
    ],
    'search' => ['name', 'generic_name', 'sku', 'barcode'],
    'fields' => [
        'name' => ['label' => 'Medicine name', 'required' => true],
        'generic_name' => ['label' => 'Generic name'],
        'category_id' => ['label' => 'Category', 'type' => 'select', 'options_sql' => 'SELECT id, name AS label FROM categories WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'brand_id' => ['label' => 'Brand', 'type' => 'select', 'options_sql' => 'SELECT id, name AS label FROM brands WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'sku' => ['label' => 'SKU'],
        'barcode' => ['label' => 'Barcode'],
        'strength' => ['label' => 'Strength', 'help' => 'e.g. 500mg, 250mg/5ml'],
        'dosage_form' => ['label' => 'Dosage form', 'type' => 'select', 'options' => ['Tablet' => 'Tablet', 'Capsule' => 'Capsule', 'Syrup' => 'Syrup', 'Injection' => 'Injection', 'Cream' => 'Cream', 'Drops' => 'Drops', 'Inhaler' => 'Inhaler', 'Other' => 'Other']],
        'unit' => ['label' => 'Unit', 'help' => 'e.g. tablet, strip, bottle', 'default' => 'tablet'],
        'rack_location' => ['label' => 'Rack / shelf location'],
        'reorder_level' => ['label' => 'Reorder level (low-stock alert)', 'type' => 'number', 'default' => 10],
        'requires_prescription' => ['label' => 'Requires prescription', 'type' => 'checkbox'],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'col' => 'col-12'],
    ],
    'before_save' => static function (array $data, ?int $id): array {
        // Enforce the subscription plan limit for medicine records on create.
        if ($id === null) {
            $err = SubscriptionService::limitError(tenant_id(), 'medicines');
            if ($err) {
                throw new RuntimeException($err);
            }
        }
        foreach (['category_id', 'brand_id'] as $k) {
            $data[$k] = !empty($data[$k]) ? (int)$data[$k] : null;
        }
        return $data;
    },
]);
admin_footer();
