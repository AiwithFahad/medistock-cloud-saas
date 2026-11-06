<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Medicine Brands', 'admin/brands.php');
Crud::handle([
    'title' => 'Brand', 'icon' => 'bi-award', 'table' => 'brands',
    'permission' => 'medicines.view', 'base' => 'brands.php',
    'columns' => ['name' => 'Brand', 'manufacturer' => 'Manufacturer', 'country' => 'Country', 'created_at' => 'Created'],
    'search' => ['name', 'manufacturer'],
    'fields' => [
        'name' => ['label' => 'Brand name', 'required' => true],
        'manufacturer' => ['label' => 'Manufacturer'],
        'country' => ['label' => 'Country of origin'],
    ],
]);
admin_footer();
