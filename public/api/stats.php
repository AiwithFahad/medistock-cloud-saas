<?php
/**
 * MediStock Cloud — JSON API: dashboard KPI aggregates.
 *
 * Every number is computed live from the tenant's own tables (no stored
 * counters, no placeholders). Optional ?from=YYYY-MM-DD&to=YYYY-MM-DD
 * filters the sales / purchase figures; stock figures are always current.
 * Tenant-scoped; requires the dashboard.view permission.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || is_super_admin() || tenant_id() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
if (!can('dashboard.view')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied.']);
    exit;
}
$tid = tenant_id();
$pdo = db();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');

$one = static function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};

$stats = [
    'medicines' => (int)$one('SELECT COUNT(*) FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL AND status = "active"', [$tid]),
    'categories' => (int)$one('SELECT COUNT(*) FROM categories WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]),
    'stock_quantity' => (int)$one('SELECT COALESCE(SUM(quantity), 0) FROM batches WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]),
    'inventory_value' => round((float)$one('SELECT COALESCE(SUM(quantity * purchase_price), 0) FROM batches WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]), 2),
    'today_sales' => round((float)$one('SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE tenant_id = ? AND DATE(created_at) = CURDATE() AND status = "completed"', [$tid]), 2),
    'today_sales_count' => (int)$one('SELECT COUNT(*) FROM sales WHERE tenant_id = ? AND DATE(created_at) = CURDATE() AND status = "completed"', [$tid]),
    'period_sales' => round((float)$one('SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = "completed"', [$tid, $from, $to]), 2),
    'month_revenue' => round((float)$one('SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE tenant_id = ? AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01") AND status = "completed"', [$tid]), 2),
    'period_purchases' => round((float)$one('SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = "received"', [$tid, $from, $to]), 2),
    'pending_purchase_orders' => (int)$one('SELECT COUNT(*) FROM purchase_orders WHERE tenant_id = ? AND status IN ("draft","ordered","partially_received") AND deleted_at IS NULL', [$tid]),
    'pending_transfers' => (int)$one('SELECT COUNT(*) FROM stock_transfers WHERE tenant_id = ? AND status IN ("pending","in_transit")', [$tid]),
    'suppliers' => (int)$one('SELECT COUNT(*) FROM suppliers WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]),
    'customers' => (int)$one('SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]),
    'pending_prescriptions' => (int)$one('SELECT COUNT(*) FROM prescriptions WHERE tenant_id = ? AND status IN ("pending","partially_dispensed") AND deleted_at IS NULL', [$tid]),
    'expired_batches' => (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date < CURDATE() AND quantity > 0 AND deleted_at IS NULL', [$tid]),
    'expiring_30' => (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND quantity > 0 AND deleted_at IS NULL', [$tid]),
    'expiring_60' => (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) AND quantity > 0 AND deleted_at IS NULL', [$tid]),
    'expiring_90' => (int)$one('SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND quantity > 0 AND deleted_at IS NULL', [$tid]),
];

// Low stock: medicines whose sellable (non-expired) stock is at/below the
// reorder level — same rule as the dashboard alert strip.
$lowStmt = $pdo->prepare(
    'SELECT m.id, m.name, m.strength, m.reorder_level,
            COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS available
       FROM medicines m LEFT JOIN batches b ON b.medicine_id = m.id AND b.tenant_id = m.tenant_id AND b.deleted_at IS NULL
      WHERE m.tenant_id = ? AND m.deleted_at IS NULL
      GROUP BY m.id
     HAVING available <= m.reorder_level
      ORDER BY available ASC LIMIT 10'
);
$lowStmt->execute([$tid]);
$lowStock = array_map(static fn($r) => [
    'id' => (int)$r['id'], 'name' => $r['name'], 'strength' => $r['strength'],
    'available' => (int)$r['available'], 'reorder_level' => (int)$r['reorder_level'],
], $lowStmt->fetchAll());
$stats['low_stock_count'] = count($lowStock);
$stats['low_stock'] = $lowStock;

$recent = $pdo->prepare(
    'SELECT s.invoice_number, s.grand_total, s.created_at, c.name AS customer
       FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.tenant_id = ? AND s.status = "completed" ORDER BY s.id DESC LIMIT 8'
);
$recent->execute([$tid]);
$stats['recent_transactions'] = array_map(static fn($r) => [
    'invoice_number' => $r['invoice_number'], 'total' => (float)$r['grand_total'],
    'customer' => $r['customer'] ?? 'Walk-in customer', 'created_at' => $r['created_at'],
], $recent->fetchAll());

$stats['range'] = ['from' => $from, 'to' => $to];
echo json_encode(['stats' => $stats]);
