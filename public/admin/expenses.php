<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Expenses', 'admin/expenses.php');
Crud::handle([
    'title' => 'Expense', 'icon' => 'bi-wallet2', 'table' => 'expenses',
    'permission' => 'expenses.manage', 'base' => 'expenses.php',
    'order' => 'expense_date DESC',
    'columns' => ['expense_date' => 'Date', 'title' => 'Expense', 'category' => 'Category', 'amount' => 'Amount', 'payment_method' => 'Method'],
    'display' => ['amount' => static fn($r) => money($r['amount'])],
    'search' => ['title', 'category'],
    'fields' => [
        'title' => ['label' => 'Expense title', 'required' => true],
        'category' => ['label' => 'Category', 'type' => 'select', 'options' => ['Rent' => 'Rent', 'Utilities' => 'Utilities', 'Salaries' => 'Salaries', 'Supplies' => 'Supplies', 'Marketing' => 'Marketing', 'Maintenance' => 'Maintenance', 'Other' => 'Other']],
        'branch_id' => ['label' => 'Branch', 'type' => 'select', 'options_sql' => 'SELECT id, name AS label FROM branches WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'amount' => ['label' => 'Amount', 'type' => 'number', 'step' => '0.01', 'required' => true],
        'expense_date' => ['label' => 'Date', 'type' => 'date', 'required' => true],
        'payment_method' => ['label' => 'Payment method', 'type' => 'select', 'options' => ['cash' => 'Cash', 'card' => 'Card', 'bank' => 'Bank transfer']],
        'notes' => ['label' => 'Notes', 'type' => 'textarea', 'col' => 'col-12'],
    ],
    'before_save' => static function (array $data, ?int $id): array {
        $data['branch_id'] = !empty($data['branch_id']) ? (int)$data['branch_id'] : null;
        return $data;
    },
]);
admin_footer();
