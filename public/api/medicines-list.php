<?php
/**
 * MediStock Cloud — JSON API: paginated medicine explorer.
 *
 * Powers the live-search medicine screens (pharmacist workspace, AJAX
 * tables): search + category/status filters + sorting + pagination over
 * REAL rows, including sellable availability per medicine. Tenant-scoped;
 * requires medicines.view.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || is_super_admin() || tenant_id() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
if (!can('medicines.view')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied.']);
    exit;
}
$tid = tenant_id();
$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$status = (string)($_GET['status'] ?? '');
$sort = (string)($_GET['sort'] ?? 'name');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(5, min(50, (int)($_GET['per_page'] ?? 15)));

$where = ['m.tenant_id = ?', 'm.deleted_at IS NULL'];
$params = [$tid];
if ($q !== '') {
    $where[] = '(m.name LIKE ? OR m.generic_name LIKE ? OR m.barcode = ? OR m.sku = ?)';
    array_push($params, "%$q%", "%$q%", $q, $q);
}
if ($categoryId > 0) {
    $where[] = 'm.category_id = ?';
    $params[] = $categoryId;
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'm.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);
$availSql = 'COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() AND b.deleted_at IS NULL THEN b.quantity ELSE 0 END), 0)';
$orderMap = [
    'name' => 'm.name ' . $dir,
    'available' => 'available ' . $dir,
    'price' => 'min_price ' . $dir,
];
$order = $orderMap[$sort] ?? 'm.name ASC';

$count = $pdo->prepare("SELECT COUNT(*) FROM medicines m WHERE {$whereSql}");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT m.*, c.name AS category_name, br.name AS brand_name,
            {$availSql} AS available,
            (SELECT MIN(b2.selling_price) FROM batches b2 WHERE b2.medicine_id = m.id AND b2.tenant_id = m.tenant_id AND b2.expiry_date >= CURDATE() AND b2.quantity > 0) AS min_price,
            (SELECT COUNT(*) FROM batches b3 WHERE b3.medicine_id = m.id AND b3.tenant_id = m.tenant_id AND b3.deleted_at IS NULL) AS batch_count
       FROM medicines m
       LEFT JOIN categories c ON c.id = m.category_id
       LEFT JOIN brands br ON br.id = m.brand_id
       LEFT JOIN batches b ON b.medicine_id = m.id AND b.tenant_id = m.tenant_id
      WHERE {$whereSql}
      GROUP BY m.id
      ORDER BY {$order}
      LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = array_map(static fn($r) => [
    'id' => (int)$r['id'],
    'photo' => $r['photo_path'] ? asset($r['photo_path']) : null,
    'name' => $r['name'],
    'generic_name' => $r['generic_name'],
    'brand_name' => $r['brand_name'],
    'manufacturer' => $r['manufacturer'],
    'category' => $r['category_name'],
    'sku' => $r['sku'],
    'barcode' => $r['barcode'],
    'strength' => $r['strength'],
    'unit' => $r['unit'],
    'dosage_form' => $r['dosage_form'],
    'requires_prescription' => (bool)$r['requires_prescription'],
    'status' => $r['status'],
    'reorder_level' => (int)$r['reorder_level'],
    'available' => (int)$r['available'],
    'low_stock' => (int)$r['available'] <= (int)$r['reorder_level'],
    'min_price' => $r['min_price'] !== null ? (float)$r['min_price'] : null,
    'batch_count' => (int)$r['batch_count'],
], $stmt->fetchAll());

echo json_encode(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
