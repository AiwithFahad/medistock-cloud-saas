<?php
/**
 * MediStock Cloud — JSON API: Chart.js data series.
 *
 * ?series=daily_sales|monthly_revenue|purchases|top_medicines|movements|
 *         expiry|inventory_by_category|platform_revenue
 * Optional ?days= (daily series), ?months= (monthly series) and
 * ?from=&to= date filters. All series are real aggregates over the
 * caller's own data — tenant series are tenant-scoped; platform_revenue
 * is Super Admin only and counts VERIFIED subscription payments only.
 *
 * Response shape matches the shared chart loader in assets/js/app.js:
 * { labels: [...], datasets: [ { label, data: [...] } ] }
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
$pdo = db();
$series = (string)($_GET['series'] ?? 'daily_sales');
$days = max(7, min(90, (int)($_GET['days'] ?? 14)));
$months = max(3, min(24, (int)($_GET['months'] ?? 12)));

$out = ['labels' => [], 'datasets' => []];

if ($series === 'platform_revenue') {
    if (!is_super_admin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Super Admin only.']);
        exit;
    }
    $stmt = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(amount) AS total
           FROM payments WHERE status = 'verified'
            AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL " . ($months - 1) . " MONTH), '%Y-%m-01')
          GROUP BY ym"
    );
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['ym']] = (float)$r['total'];
    }
    $labels = [];
    $data = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} month"));
        $labels[] = date('M Y', strtotime($ym . '-01'));
        $data[] = $map[$ym] ?? 0;
    }
    echo json_encode(['labels' => $labels, 'datasets' => [['label' => 'Verified platform revenue', 'data' => $data]]]);
    exit;
}

if (is_super_admin() || tenant_id() === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Tenant account required.']);
    exit;
}
$tid = tenant_id();

switch ($series) {
    case 'daily_sales':
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS d, SUM(grand_total) AS total FROM sales
              WHERE tenant_id = ? AND status = "completed" AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY d'
        );
        $stmt->execute([$tid, $days - 1]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[$r['d']] = (float)$r['total'];
        }
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            $out['labels'][] = date('d M', strtotime($d));
            $out['datasets'][0]['label'] = 'Daily sales';
            $out['datasets'][0]['data'][] = $map[$d] ?? 0;
        }
        break;

    case 'monthly_revenue':
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(grand_total) AS total FROM sales
              WHERE tenant_id = ? AND status = 'completed'
                AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m-01')
              GROUP BY ym"
        );
        $stmt->execute([$tid, $months - 1]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[$r['ym']] = (float)$r['total'];
        }
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} month"));
            $out['labels'][] = date('M Y', strtotime($ym . '-01'));
            $out['datasets'][0]['label'] = 'Monthly revenue';
            $out['datasets'][0]['data'][] = $map[$ym] ?? 0;
        }
        break;

    case 'purchases':
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total_amount) AS total FROM purchases
              WHERE tenant_id = ? AND status = 'received'
                AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m-01')
              GROUP BY ym"
        );
        $stmt->execute([$tid, $months - 1]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[$r['ym']] = (float)$r['total'];
        }
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} month"));
            $out['labels'][] = date('M Y', strtotime($ym . '-01'));
            $out['datasets'][0]['label'] = 'Purchases';
            $out['datasets'][0]['data'][] = $map[$ym] ?? 0;
        }
        break;

    case 'top_medicines':
        $stmt = $pdo->prepare(
            'SELECT m.name, SUM(si.quantity) AS units FROM sale_items si
               JOIN sales s ON s.id = si.sale_id AND s.tenant_id = ?
               JOIN medicines m ON m.id = si.medicine_id
              WHERE s.status = "completed" GROUP BY si.medicine_id ORDER BY units DESC LIMIT 8'
        );
        $stmt->execute([$tid]);
        $out['datasets'][0] = ['label' => 'Units sold', 'data' => []];
        foreach ($stmt->fetchAll() as $r) {
            $out['labels'][] = $r['name'];
            $out['datasets'][0]['data'][] = (int)$r['units'];
        }
        break;

    case 'movements':
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS d, type, SUM(ABS(quantity)) AS units FROM stock_movements
              WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY d, type'
        );
        $stmt->execute([$tid, $days - 1]);
        $types = ['purchase' => 'Stock in (purchases)', 'sale' => 'Stock out (sales)', 'adjustment' => 'Adjustments', 'transfer' => 'Transfers', 'return' => 'Returns'];
        $grid = [];
        foreach ($stmt->fetchAll() as $r) {
            $grid[$r['type']][$r['d']] = (int)$r['units'];
        }
        for ($i = $days - 1; $i >= 0; $i--) {
            $out['labels'][] = date('d M', strtotime("-{$i} day"));
        }
        foreach ($types as $type => $label) {
            if (empty($grid[$type])) {
                continue;
            }
            $ds = ['label' => $label, 'data' => []];
            for ($i = $days - 1; $i >= 0; $i--) {
                $ds['data'][] = $grid[$type][date('Y-m-d', strtotime("-{$i} day"))] ?? 0;
            }
            $out['datasets'][] = $ds;
        }
        if (!$out['datasets']) {
            $out['datasets'][] = ['label' => 'No movements in range', 'data' => array_fill(0, count($out['labels']), 0)];
        }
        break;

    case 'expiry':
        $buckets = [
            'Expired' => 'expiry_date < CURDATE()',
            '≤ 30 days' => 'expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)',
            '31–60 days' => 'expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)',
            '61–90 days' => 'expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 61 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)',
            '> 90 days' => 'expiry_date > DATE_ADD(CURDATE(), INTERVAL 90 DAY)',
        ];
        $out['datasets'][0] = ['label' => 'Batches', 'data' => []];
        foreach ($buckets as $label => $cond) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE tenant_id = ? AND quantity > 0 AND deleted_at IS NULL AND {$cond}");
            $stmt->execute([$tid]);
            $out['labels'][] = $label;
            $out['datasets'][0]['data'][] = (int)$stmt->fetchColumn();
        }
        break;

    case 'inventory_by_category':
        $stmt = $pdo->prepare(
            'SELECT COALESCE(c.name, "Uncategorised") AS cat, SUM(b.quantity * b.purchase_price) AS value
               FROM batches b JOIN medicines m ON m.id = b.medicine_id
               LEFT JOIN categories c ON c.id = m.category_id
              WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.quantity > 0
              GROUP BY cat ORDER BY value DESC LIMIT 8'
        );
        $stmt->execute([$tid]);
        $out['datasets'][0] = ['label' => 'Inventory value', 'data' => []];
        foreach ($stmt->fetchAll() as $r) {
            $out['labels'][] = $r['cat'];
            $out['datasets'][0]['data'][] = round((float)$r['value'], 2);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown series.']);
        exit;
}

echo json_encode($out);
