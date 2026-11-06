<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Medicine Categories', 'admin/categories.php');
Crud::handle([
    'title' => 'Category', 'icon' => 'bi-tags', 'table' => 'categories',
    'permission' => 'medicines.view', 'base' => 'categories.php',
    'columns' => ['name' => 'Name', 'description' => 'Description', 'created_at' => 'Created'],
    'search' => ['name', 'description'],
    'fields' => [
        'name' => ['label' => 'Category name', 'required' => true],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'col' => 'col-12'],
    ],
]);
admin_footer();
