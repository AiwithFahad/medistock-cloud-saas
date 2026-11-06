<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Prescriptions', 'admin/prescriptions.php');
Crud::handle([
    'title' => 'Prescription', 'icon' => 'bi-file-earmark-medical', 'table' => 'prescriptions',
    'permission' => 'prescriptions.manage', 'base' => 'prescriptions.php',
    'order' => 'id DESC',
    'columns' => ['prescription_number' => 'Rx #', 'patient_name' => 'Patient', 'doctor_name' => 'Doctor', 'prescribed_at' => 'Date', 'status' => 'Status'],
    'search' => ['prescription_number', 'patient_name', 'doctor_name'],
    'fields' => [
        'prescription_number' => ['label' => 'Prescription number', 'required' => true],
        'customer_id' => ['label' => 'Customer (patient)', 'type' => 'select', 'options_sql' => 'SELECT id, name AS label FROM customers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'patient_name' => ['label' => 'Patient name (if not a registered customer)'],
        'doctor_name' => ['label' => 'Prescribing doctor', 'required' => true],
        'hospital' => ['label' => 'Hospital / clinic'],
        'prescribed_at' => ['label' => 'Prescription date', 'type' => 'date', 'required' => true],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['pending' => 'Pending', 'dispensed' => 'Dispensed', 'partially_dispensed' => 'Partially dispensed', 'cancelled' => 'Cancelled'], 'default' => 'pending'],
        'notes' => ['label' => 'Dispensing notes', 'type' => 'textarea', 'col' => 'col-12'],
    ],
    'before_save' => static function (array $data, ?int $id): array {
        $data['customer_id'] = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
        return $data;
    },
]);
admin_footer();
