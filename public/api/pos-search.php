<?php
/**
 * MediStock Cloud — JSON API: POS product search with FULL batch detail.
 *
 * Unlike api/medicines.php (summary), this returns every batch of each
 * matching medicine at the selected branch — including expired and empty
 * batches, explicitly flagged — so the JS POS can offer batch selection
 * with expiry warnings. Expired batches are returned for visibility only;
 * they are marked "expired": true and the checkout endpoint refuses them.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || is_super_admin() || tenant_id() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
if (!can('pos.access')) {
    http_response_code(403);
    echo json_encode(['error' => 'POS access is not permitted for your role.']);
    exit;
}
$tid = tenant_id();
$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$branchId = (int)($_GET['branch_id'] ?? 0);
if ($branchId <= 0) {
    $branchId = (int)$pdo->query('SELECT id FROM branches WHERE tenant_id = ' . $tid . ' AND deleted_at IS NULL ORDER BY is_main DESC, id ASC LIMIT 1')->fetchColumn();
}
$branchCheck = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL');
$branchCheck->execute([$branchId, $tid]);
if ((int)$branchCheck->fetchColumn() === 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Branch not found for this pharmacy.']);
    exit;
}

$sql = 'SELECT m.* FROM medicines m
         WHERE m.tenant_id = ? AND m.deleted_at IS NULL AND m.status = "active"';
$params = [$tid];
if ($q !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.generic_name LIKE ? OR m.barcode = ? OR m.sku = ?)';
    array_push($params, "%$q%", "%$q%", $q, $q);
}
$sql .= ' ORDER BY m.name LIMIT 30';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$batchStmt = $pdo->prepare(
    'SELECT id, batch_number, expiry_date, quantity, selling_price,
            DATEDIFF(expiry_date, CURDATE()) AS days_left
       FROM batches
      WHERE tenant_id = ? AND branch_id = ? AND medicine_id = ? AND deleted_at IS NULL
      ORDER BY expiry_date ASC'
);
$results = [];
foreach ($stmt->fetchAll() as $m) {
    $batchStmt->execute([$tid, $branchId, (int)$m['id']]);
    $batches = [];
    $available = 0;
    foreach ($batchStmt->fetchAll() as $b) {
        $expired = (int)$b['days_left'] < 0;
        if (!$expired) {
            $available += (int)$b['quantity'];
        }
        $batches[] = [
            'id' => (int)$b['id'],
            'batch_number' => $b['batch_number'],
            'expiry_date' => $b['expiry_date'],
            'days_left' => (int)$b['days_left'],
            'quantity' => (int)$b['quantity'],
            'selling_price' => (float)$b['selling_price'],
            'expired' => $expired,
        ];
    }
    if ($q === '' || $available > 0 || $batches !== []) {
        $results[] = [
            'id' => (int)$m['id'],
            'name' => $m['name'],
            'generic_name' => $m['generic_name'],
            'sku' => $m['sku'],
            'barcode' => $m['barcode'],
            'strength' => $m['strength'],
            'unit' => $m['unit'],
            'requires_prescription' => (bool)$m['requires_prescription'],
            'available' => $available,
            'batches' => $batches,
        ];
    }
}
echo json_encode(['results' => $results, 'branch_id' => $branchId]);
