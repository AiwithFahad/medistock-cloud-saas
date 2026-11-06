<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Suppliers', 'admin/suppliers.php');
Crud::handle([
    'title' => 'Supplier', 'icon' => 'bi-truck', 'table' => 'suppliers',
    'permission' => 'suppliers.manage', 'base' => 'suppliers.php',
    'columns' => ['name' => 'Supplier', 'contact_person' => 'Contact', 'email' => 'Email', 'phone' => 'Phone', 'payment_terms' => 'Terms', 'status' => 'Status'],
    'search' => ['name', 'contact_person', 'email', 'phone'],
    'fields' => [
        'name' => ['label' => 'Supplier name', 'required' => true],
        'contact_person' => ['label' => 'Contact person'],
        'email' => ['label' => 'Email', 'type' => 'email'],
        'phone' => ['label' => 'Phone'],
        'address' => ['label' => 'Address', 'type' => 'textarea', 'col' => 'col-12'],
        'payment_terms' => ['label' => 'Payment terms', 'help' => 'e.g. Net 30, Cash on delivery'],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'default' => 'active'],
    ],
]);
admin_footer();
