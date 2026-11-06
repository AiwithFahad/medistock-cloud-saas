<?php
/**
 * MediStock Cloud — JSON API: POS checkout (server-authoritative).
 *
 * The JS cart sends medicine ids, batch ids (or null = FEFO), quantities,
 * per-line discounts and split payments. NOTHING from the client is
 * trusted:
 *   - unit prices are re-read from the batch rows,
 *   - line discounts are capped at the line value,
 *   - tax comes from the tenant's own tax_rate setting,
 *   - stock, batch branch and expiry are validated here AND again inside
 *     StockService::createSale() within the database transaction,
 *   - prescription-only medicines require a valid prescription id,
 *   - payments must cover the server-computed grand total.
 * Only then is the sale committed and a receipt payload returned.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

function pos_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$user = current_user();
if (!$user || is_super_admin() || tenant_id() === null) {
    pos_fail(401, 'Authentication required.');
}
if (!can('pos.access')) {
    pos_fail(403, 'POS access is not permitted for your role.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pos_fail(405, 'POST required.');
}
$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals((string)($_SESSION['_csrf'] ?? ''), $csrf)) {
    pos_fail(419, 'Session expired — reload the POS and try again.');
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    pos_fail(400, 'Invalid JSON body.');
}
$tid = tenant_id();
$pdo = db();
$userId = (int)$user['id'];

$branchId = (int)($body['branch_id'] ?? 0);
$branch = $pdo->prepare('SELECT * FROM branches WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL');
$branch->execute([$branchId, $tid]);
$branchRow = $branch->fetch();
if (!$branchRow) {
    pos_fail(422, 'Branch not found for this pharmacy.');
}

$limitError = SubscriptionService::limitError($tid, 'transactions');
if ($limitError) {
    pos_fail(422, $limitError);
}

$customerId = !empty($body['customer_id']) ? (int)$body['customer_id'] : null;
$customerName = null;
if ($customerId) {
    $c = $pdo->prepare('SELECT name FROM customers WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL');
    $c->execute([$customerId, $tid]);
    $customerName = $c->fetchColumn();
    if ($customerName === false) {
        pos_fail(422, 'Customer not found.');
    }
}

$rxId = !empty($body['prescription_id']) ? (int)$body['prescription_id'] : null;
if ($rxId) {
    $rx = $pdo->prepare('SELECT COUNT(*) FROM prescriptions WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND status IN ("pending","partially_dispensed")');
    $rx->execute([$rxId, $tid]);
    if ((int)$rx->fetchColumn() === 0) {
        pos_fail(422, 'Prescription not found or already dispensed.');
    }
}

$itemsIn = $body['items'] ?? [];
if (!is_array($itemsIn) || $itemsIn === []) {
    pos_fail(422, 'The cart is empty.');
}

/* Resolve every line against the database: batch, price, stock, expiry. */
$resolved = [];
$subtotal = 0.0;
$discountTotal = 0.0;
$needsRx = false;
foreach ($itemsIn as $item) {
    $medicineId = (int)($item['medicine_id'] ?? 0);
    $qty = (int)($item['quantity'] ?? 0);
    if ($medicineId <= 0 || $qty <= 0) {
        pos_fail(422, 'Every cart line needs a medicine and a quantity above zero.');
    }
    $med = $pdo->prepare('SELECT * FROM medicines WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND status = "active"');
    $med->execute([$medicineId, $tid]);
    $medicine = $med->fetch();
    if (!$medicine) {
        pos_fail(422, 'Medicine #' . $medicineId . ' is not available.');
    }
    if (!empty($medicine['requires_prescription'])) {
        $needsRx = true;
    }
    $lineDiscount = max(0.0, (float)($item['discount'] ?? 0));

    // Allocations: explicit batch, or FEFO across sellable batches.
    $allocations = [];
    if (!empty($item['batch_id'])) {
        $allocations[] = ['batch_id' => (int)$item['batch_id'], 'qty' => $qty];
    } else {
        $remaining = $qty;
        foreach (StockService::fefoBatches($tid, $medicineId, $branchId) as $b) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int)$b['quantity']);
            if ($take > 0) {
                $allocations[] = ['batch_id' => (int)$b['id'], 'qty' => $take];
                $remaining -= $take;
            }
        }
        if ($remaining > 0 && StockService::tenantSetting($tid, 'allow_negative_stock', '0') !== '1') {
            pos_fail(422, 'Insufficient stock for ' . $medicine['name'] . ' — only ' . ($qty - $remaining) . ' available.');
        }
        if ($remaining > 0) {
            $first = StockService::fefoBatches($tid, $medicineId, $branchId)[0] ?? null;
            if (!$first) {
                pos_fail(422, 'No batch exists for ' . $medicine['name'] . '.');
            }
            $allocations[] = ['batch_id' => (int)$first['id'], 'qty' => $remaining];
        }
    }

    $firstAlloc = true;
    foreach ($allocations as $alloc) {
        $bStmt = $pdo->prepare('SELECT * FROM batches WHERE id = ? AND tenant_id = ? AND branch_id = ? AND medicine_id = ? AND deleted_at IS NULL');
        $bStmt->execute([$alloc['batch_id'], $tid, $branchId, $medicineId]);
        $batch = $bStmt->fetch();
        if (!$batch) {
            pos_fail(422, 'A selected batch does not belong to this branch.');
        }
        if (strtotime((string)$batch['expiry_date']) < strtotime(date('Y-m-d'))) {
            pos_fail(422, 'Batch ' . $batch['batch_number'] . ' of ' . $medicine['name'] . ' is EXPIRED and cannot be sold.');
        }
        $lineValue = (float)$batch['selling_price'] * $alloc['qty'];
        $subtotal += $lineValue;
        // The line discount rides on the first allocation only, capped at
        // the value of the whole requested line.
        $allocDiscount = 0.0;
        if ($firstAlloc) {
            $totalLineValue = 0.0;
            foreach ($allocations as $a2) {
                $p2 = $pdo->prepare('SELECT selling_price FROM batches WHERE id = ?');
                $p2->execute([$a2['batch_id']]);
                $totalLineValue += (float)$p2->fetchColumn() * $a2['qty'];
            }
            $allocDiscount = min($lineDiscount, $totalLineValue);
            $discountTotal += $allocDiscount;
            $firstAlloc = false;
        }
        $resolved[] = [
            'medicine_id' => $medicineId,
            'batch_id' => (int)$batch['id'],
            'quantity' => $alloc['qty'],
            'discount' => $allocDiscount,
        ];
    }
}
if ($needsRx && !$rxId) {
    pos_fail(422, 'This sale contains prescription-only medicines — a valid prescription is required.');
}

$taxRate = (float)StockService::tenantSetting($tid, 'tax_rate', '0');
$tax = round(max(0.0, $subtotal - $discountTotal) * $taxRate / 100, 2);
$grand = round($subtotal - $discountTotal + $tax, 2);

/* Split payments: methods validated, total must cover the server total. */
$payments = [];
$paid = 0.0;
foreach (($body['payments'] ?? []) as $p) {
    $method = (string)($p['method'] ?? '');
    if (!in_array($method, ['cash', 'card', 'mobile', 'bank'], true)) {
        pos_fail(422, 'Unsupported payment method: ' . $method);
    }
    $amount = round((float)($p['amount'] ?? 0), 2);
    if ($amount <= 0) {
        continue;
    }
    $payments[] = ['method' => $method, 'amount' => $amount, 'reference' => isset($p['reference']) ? (string)$p['reference'] : null];
    $paid += $amount;
}
if ($payments === []) {
    pos_fail(422, 'Add at least one payment.');
}
if ($paid + 0.001 < $grand) {
    pos_fail(422, 'Payments cover ' . number_format($paid, 2) . ' of the ' . number_format($grand, 2) . ' total. Add the remaining amount.');
}

try {
    $saleId = StockService::createSale($tid, $branchId, $customerId, $rxId, $resolved, $payments, $taxRate, $userId);
} catch (Throwable $ex) {
    pos_fail(422, $ex->getMessage());
}

/* Receipt payload — read back what was actually committed. */
$saleStmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? AND tenant_id = ?');
$saleStmt->execute([$saleId, $tid]);
$sale = $saleStmt->fetch();
$linesStmt = $pdo->prepare(
    'SELECT si.quantity, si.unit_price, si.total, m.name, m.strength, b.batch_number
       FROM sale_items si JOIN medicines m ON m.id = si.medicine_id
       LEFT JOIN batches b ON b.id = si.batch_id WHERE si.sale_id = ? ORDER BY si.id'
);
$linesStmt->execute([$saleId]);
$tenantStmt = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
$tenantStmt->execute([$tid]);

echo json_encode([
    'sale_id' => $saleId,
    'invoice_number' => $sale['invoice_number'],
    'grand_total' => (float)$sale['grand_total'],
    'change' => round(max(0.0, $paid - (float)$sale['grand_total']), 2),
    'receipt' => [
        'pharmacy' => $tenantStmt->fetchColumn(),
        'branch' => $branchRow['name'],
        'invoice_number' => $sale['invoice_number'],
        'created_at' => fmt_date($sale['created_at'], 'd M Y H:i'),
        'customer' => $customerName,
        'items' => array_map(static fn($l) => [
            'name' => $l['name'] . ($l['strength'] ? ' ' . $l['strength'] : ''),
            'batch_number' => $l['batch_number'],
            'quantity' => (int)$l['quantity'],
            'unit_price' => (float)$l['unit_price'],
            'total' => (float)$l['total'],
        ], $linesStmt->fetchAll()),
        'subtotal' => (float)$sale['subtotal'],
        'discount' => (float)$sale['discount'],
        'tax' => (float)$sale['tax'],
        'grand_total' => (float)$sale['grand_total'],
        'payments' => $payments,
        'change' => round(max(0.0, $paid - (float)$sale['grand_total']), 2),
        'footer' => StockService::tenantSetting($tid, 'receipt_footer', 'Thank you for your purchase!'),
    ],
]);
