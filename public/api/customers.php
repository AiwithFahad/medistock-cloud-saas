<?php
/**
 * MediStock Cloud — JSON API: customer typeahead for the POS and
 * prescription screens. Tenant-scoped, permission-gated.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || is_super_admin() || tenant_id() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
if (!can('pos.access') && !can('customers.manage') && !can('prescriptions.manage')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied.']);
    exit;
}
$tid = tenant_id();
$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT id, name, phone, email FROM customers WHERE tenant_id = ? AND deleted_at IS NULL';
$params = [$tid];
if ($q !== '') {
    $sql .= ' AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$sql .= ' ORDER BY name LIMIT 15';
$stmt = db()->prepare($sql);
$stmt->execute($params);
echo json_encode(['customers' => array_map(static fn($r) => [
    'id' => (int)$r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'],
], $stmt->fetchAll())]);
