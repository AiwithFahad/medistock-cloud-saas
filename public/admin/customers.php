<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Customers', 'admin/customers.php');
Crud::handle([
    'title' => 'Customer', 'icon' => 'bi-person-heart', 'table' => 'customers',
    'permission' => 'customers.manage', 'base' => 'customers.php',
    'columns' => ['name' => 'Customer', 'phone' => 'Phone', 'email' => 'Email', 'gender' => 'Gender', 'date_of_birth' => 'Date of birth', 'created_at' => 'Since'],
    'search' => ['name', 'phone', 'email'],
    'fields' => [
        'name' => ['label' => 'Full name', 'required' => true],
        'phone' => ['label' => 'Phone'],
        'email' => ['label' => 'Email', 'type' => 'email'],
        'gender' => ['label' => 'Gender', 'type' => 'select', 'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']],
        'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date'],
        'address' => ['label' => 'Address', 'type' => 'textarea', 'col' => 'col-12'],
        'notes' => ['label' => 'Notes (allergies, chronic conditions…)', 'type' => 'textarea', 'col' => 'col-12'],
    ],
]);
admin_footer();
