<?php
/**
 * MediStock Cloud — JSON API: medicine search for POS & integrations.
 * Auth: an active dashboard session (or a tenant API token, see docs/API.md).
 * Always tenant-scoped; expired stock is never returned as sellable.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || is_super_admin() || tenant_id() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
$tid = tenant_id();
$q = trim((string)($_GET['q'] ?? ''));
$branchId = (int)($_GET['branch_id'] ?? 0);

$sql = 'SELECT m.id, m.name, m.generic_name, m.sku, m.barcode, m.strength, m.unit,
               COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS available
          FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id AND b.tenant_id = m.tenant_id'
        . ($branchId ? ' AND b.branch_id = ' . $branchId : '') . '
         WHERE m.tenant_id = ? AND m.deleted_at IS NULL';
$params = [$tid];
if ($q !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.barcode = ? OR m.sku = ?)';
    array_push($params, "%$q%", $q, $q);
}
$sql .= ' GROUP BY m.id ORDER BY m.name LIMIT 25';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$results = [];
foreach ($stmt->fetchAll() as $row) {
    $fefo = StockService::fefoBatches($tid, (int)$row['id'], $branchId ?: null);
    $results[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'generic_name' => $row['generic_name'],
        'sku' => $row['sku'],
        'barcode' => $row['barcode'],
        'strength' => $row['strength'],
        'unit' => $row['unit'],
        'available' => (int)$row['available'],
        'fefo_batch' => $fefo[0]['batch_number'] ?? null,
        'fefo_expiry' => $fefo[0]['expiry_date'] ?? null,
        'selling_price' => $fefo[0]['selling_price'] ?? null,
    ];
}
echo json_encode(['data' => $results]);
