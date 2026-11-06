<?php
/**
 * MediStock Cloud — inventory & stock transaction engine.
 *
 * Safety rules (enforced here, not in the UI):
 *  - Batch-level stock: every unit lives in a batch with an expiry date.
 *  - FEFO: sales allocate from the batch that expires first.
 *  - Expired stock can NEVER be sold.
 *  - Stock can NEVER go negative unless the tenant's authorized
 *    "allow_negative_stock" adjustment policy is enabled.
 *  - EVERY sale / purchase / return / adjustment runs inside a database
 *    transaction and writes a complete stock_movements audit trail.
 */
final class StockService
{
    public static function tenantSetting(int $tenantId, string $key, ?string $default = null): ?string
    {
        $stmt = db()->prepare('SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = ? LIMIT 1');
        $stmt->execute([$tenantId, $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    /** Total available (non-expired) quantity of a medicine across batches. */
    public static function availableQty(int $tenantId, int $medicineId, ?int $branchId = null): int
    {
        $sql = 'SELECT COALESCE(SUM(quantity), 0) FROM batches
                 WHERE tenant_id = ? AND medicine_id = ? AND quantity > 0 AND expiry_date >= CURDATE()';
        $params = [$tenantId, $medicineId];
        if ($branchId) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * FEFO suggestion: sellable batches of a medicine ordered by expiry
     * (first-expiry-first-out). Expired batches are excluded.
     */
    public static function fefoBatches(int $tenantId, int $medicineId, ?int $branchId = null): array
    {
        $sql = 'SELECT * FROM batches
                 WHERE tenant_id = ? AND medicine_id = ? AND quantity > 0 AND expiry_date >= CURDATE()';
        $params = [$tenantId, $medicineId];
        if ($branchId) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY expiry_date ASC, id ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Write one stock_movements row. Caller must be inside a transaction. */
    private static function movement(PDO $pdo, array $m): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO stock_movements
                (tenant_id, branch_id, medicine_id, batch_id, type, quantity, balance_after,
                 reference_type, reference_id, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $m['tenant_id'], $m['branch_id'] ?? null, $m['medicine_id'], $m['batch_id'] ?? null,
            $m['type'], $m['quantity'], $m['balance_after'], $m['reference_type'] ?? null,
            $m['reference_id'] ?? null, $m['note'] ?? null, $m['created_by'] ?? null,
        ]);
    }

    private static function batchBalance(PDO $pdo, int $batchId): int
    {
        $stmt = $pdo->prepare('SELECT quantity FROM batches WHERE id = ? FOR UPDATE');
        $stmt->execute([$batchId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Find the open batch with the same number + expiry in a branch (and top
     * it up), or create a new batch. Tracks quantity_received so batch
     * adjustment history can compare received vs on-hand. Caller must be
     * inside a transaction. Returns the batch id.
     */
    private static function findOrCreateBatch(PDO $pdo, int $tenantId, int $branchId, int $medicineId, ?int $supplierId, string $batchNumber, ?string $mfgDate, string $expiryDate, float $purchasePrice, float $sellingPrice, int $qty): int
    {
        $find = $pdo->prepare(
            'SELECT id FROM batches WHERE tenant_id = ? AND branch_id = ? AND medicine_id = ? AND batch_number = ? AND expiry_date = ? LIMIT 1'
        );
        $find->execute([$tenantId, $branchId, $medicineId, $batchNumber, $expiryDate]);
        $batchId = $find->fetchColumn();
        if (!$batchId) {
            $ins = $pdo->prepare(
                'INSERT INTO batches (tenant_id, branch_id, medicine_id, supplier_id, batch_number, manufacturing_date, expiry_date, purchase_price, selling_price, quantity, quantity_received, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $ins->execute([$tenantId, $branchId, $medicineId, $supplierId, $batchNumber, $mfgDate ?: null, $expiryDate, $purchasePrice, $sellingPrice, $qty, $qty]);
            return (int)$pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE batches SET quantity = quantity + ?, quantity_received = quantity_received + ?, purchase_price = ?, selling_price = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$qty, $qty, $purchasePrice, $sellingPrice, (int)$batchId]);
        return (int)$batchId;
    }

    /* ------------------------------------------------------------- Sales */

    /**
     * Complete a POS sale inside ONE transaction.
     * $items: [ ['medicine_id'=>, 'batch_id'=> (optional, FEFO when null), 'quantity'=>, 'unit_price'=>, 'discount'=>] ]
     * $paymentsIn: [ ['method'=>, 'amount'=>] ]
     * Returns the new sale id. Throws on expired batch / insufficient stock.
     */
    public static function createSale(int $tenantId, int $branchId, ?int $customerId, ?int $prescriptionId, array $items, array $paymentsIn, float $taxRate, ?int $userId): int
    {
        $pdo = db();
        $allowNegative = self::tenantSetting($tenantId, 'allow_negative_stock', '0') === '1';
        $pdo->beginTransaction();
        try {
            $subtotal = 0.0;
            $discountTotal = 0.0;
            $resolved = [];

            foreach ($items as $item) {
                $qty = (int)$item['quantity'];
                if ($qty <= 0) {
                    continue;
                }
                $remaining = $qty;
                // Resolve allocation: explicit batch or FEFO across batches.
                $allocations = [];
                if (!empty($item['batch_id'])) {
                    $allocations[] = ['batch_id' => (int)$item['batch_id'], 'qty' => $qty];
                } else {
                    foreach (self::fefoBatches($tenantId, (int)$item['medicine_id'], $branchId) as $batch) {
                        if ($remaining <= 0) {
                            break;
                        }
                        $take = min($remaining, (int)$batch['quantity']);
                        if ($take > 0) {
                            $allocations[] = ['batch_id' => (int)$batch['id'], 'qty' => $take];
                            $remaining -= $take;
                        }
                    }
                    if ($remaining > 0 && !$allowNegative) {
                        throw new RuntimeException('Insufficient non-expired stock for medicine #' . $item['medicine_id'] . '.');
                    }
                    if ($remaining > 0) {
                        // Negative-stock policy enabled: allocate the rest to the earliest batch anyway.
                        $first = self::fefoBatches($tenantId, (int)$item['medicine_id'], $branchId)[0] ?? null;
                        if (!$first) {
                            throw new RuntimeException('No batch exists for medicine #' . $item['medicine_id'] . '.');
                        }
                        $allocations[] = ['batch_id' => (int)$first['id'], 'qty' => $remaining];
                    }
                }

                foreach ($allocations as $alloc) {
                    $b = $pdo->prepare('SELECT * FROM batches WHERE id = ? AND tenant_id = ? FOR UPDATE');
                    $b->execute([$alloc['batch_id'], $tenantId]);
                    $batch = $b->fetch();
                    if (!$batch) {
                        throw new RuntimeException('Batch not found.');
                    }
                    if (strtotime($batch['expiry_date']) < strtotime(date('Y-m-d'))) {
                        throw new RuntimeException('Batch ' . $batch['batch_number'] . ' is expired and cannot be sold.');
                    }
                    if (!$allowNegative && (int)$batch['quantity'] < $alloc['qty']) {
                        throw new RuntimeException('Insufficient stock in batch ' . $batch['batch_number'] . '.');
                    }
                    $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : (float)$batch['selling_price'];
                    $lineDiscount = (float)($item['discount'] ?? 0);
                    $lineTotal = ($unitPrice * $alloc['qty']) - $lineDiscount;
                    $subtotal += $unitPrice * $alloc['qty'];
                    $discountTotal += $lineDiscount;
                    $resolved[] = [
                        'medicine_id' => (int)$item['medicine_id'], 'batch_id' => (int)$batch['id'],
                        'quantity' => $alloc['qty'], 'unit_price' => $unitPrice,
                        'discount' => $lineDiscount, 'total' => $lineTotal,
                    ];
                }
            }

            if ($resolved === []) {
                throw new RuntimeException('The cart is empty.');
            }

            $tax = round(max(0.0, $subtotal - $discountTotal) * $taxRate / 100, 2);
            $grand = round($subtotal - $discountTotal + $tax, 2);

            $sale = $pdo->prepare(
                'INSERT INTO sales (tenant_id, branch_id, customer_id, prescription_id, invoice_number, subtotal, discount, tax, grand_total, status, sold_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "completed", ?, NOW(), NOW())'
            );
            $invoiceNo = 'SAL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $sale->execute([$tenantId, $branchId, $customerId, $prescriptionId, $invoiceNo, $subtotal, $discountTotal, $tax, $grand, $userId]);
            $saleId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, medicine_id, batch_id, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($resolved as $line) {
                $itemStmt->execute([$saleId, $line['medicine_id'], $line['batch_id'], $line['quantity'], $line['unit_price'], $line['discount'], $line['total']]);
                $pdo->prepare('UPDATE batches SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$line['quantity'], $line['batch_id']]);
                self::movement($pdo, [
                    'tenant_id' => $tenantId, 'branch_id' => $branchId, 'medicine_id' => $line['medicine_id'],
                    'batch_id' => $line['batch_id'], 'type' => 'sale', 'quantity' => -$line['quantity'],
                    'balance_after' => self::batchBalance($pdo, $line['batch_id']),
                    'reference_type' => 'sale', 'reference_id' => $saleId,
                    'note' => 'POS sale ' . $invoiceNo, 'created_by' => $userId,
                ]);
            }

            $payStmt = $pdo->prepare('INSERT INTO sale_payments (sale_id, method, amount, reference, created_at) VALUES (?, ?, ?, ?, NOW())');
            foreach ($paymentsIn as $p) {
                $payStmt->execute([$saleId, $p['method'], (float)$p['amount'], $p['reference'] ?? null]);
            }

            // Monthly transaction usage for plan limits.
            $pdo->prepare(
                'INSERT INTO subscription_usage (tenant_id, subscription_id, period_start, period_end, transactions_count, created_at, updated_at)
                 SELECT ?, id, CURDATE(), LAST_DAY(CURDATE()), 1, NOW(), NOW() FROM subscriptions
                  WHERE tenant_id = ? AND status IN ("trial","active","grace") ORDER BY id DESC LIMIT 1
                 ON DUPLICATE KEY UPDATE transactions_count = transactions_count + 1, updated_at = NOW()'
            )->execute([$tenantId, $tenantId]);

            $pdo->commit();
            activity_log('sale.created', 'Sale ' . $invoiceNo . ' — ' . money($grand), $tenantId, $userId);
            return $saleId;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /* --------------------------------------------------------- Purchases */

    /**
     * Receive a purchase inside ONE transaction: creates/updates batches and
     * writes stock_movements for every received line.
     * $items: [ ['medicine_id'=>, 'batch_number'=>, 'manufacturing_date'=>, 'expiry_date'=>,
     *            'quantity'=>, 'purchase_price'=>, 'selling_price'=>] ]
     */
    public static function receivePurchase(int $tenantId, int $branchId, int $supplierId, array $items, ?int $userId, ?string $invoiceRef = null): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $total = 0.0;
            foreach ($items as $i) {
                $total += (int)$i['quantity'] * (float)$i['purchase_price'];
            }
            $stmt = $pdo->prepare(
                'INSERT INTO purchases (tenant_id, branch_id, supplier_id, invoice_reference, total_amount, status, purchased_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, "received", ?, NOW(), NOW())'
            );
            $stmt->execute([$tenantId, $branchId, $supplierId, $invoiceRef, $total, $userId]);
            $purchaseId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO purchase_items (purchase_id, medicine_id, batch_id, quantity, purchase_price, total) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $i) {
                $batchId = self::findOrCreateBatch(
                    $pdo, $tenantId, $branchId, (int)$i['medicine_id'], $supplierId,
                    (string)$i['batch_number'], $i['manufacturing_date'] ?? null, (string)$i['expiry_date'],
                    (float)$i['purchase_price'], (float)($i['selling_price'] ?? $i['purchase_price']), (int)$i['quantity']
                );
                $itemStmt->execute([$purchaseId, (int)$i['medicine_id'], (int)$batchId, (int)$i['quantity'], (float)$i['purchase_price'], (int)$i['quantity'] * (float)$i['purchase_price']]);
                self::movement($pdo, [
                    'tenant_id' => $tenantId, 'branch_id' => $branchId, 'medicine_id' => (int)$i['medicine_id'],
                    'batch_id' => (int)$batchId, 'type' => 'purchase', 'quantity' => (int)$i['quantity'],
                    'balance_after' => self::batchBalance($pdo, (int)$batchId),
                    'reference_type' => 'purchase', 'reference_id' => $purchaseId,
                    'note' => 'Purchase received', 'created_by' => $userId,
                ]);
            }
            $pdo->commit();
            activity_log('purchase.received', 'Purchase #' . $purchaseId . ' received — ' . money($total), $tenantId, $userId);
            return $purchaseId;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /* ------------------------------------------------------------ Returns */

    /**
     * Process a sales return inside ONE transaction: restores batch stock
     * (only for non-expired batches), records the refund.
     * $items: [ ['sale_item_id'=>, 'quantity'=>] ]
     */
    public static function createReturn(int $tenantId, int $saleId, array $items, string $refundMethod, string $reason, ?int $userId): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $saleStmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? AND tenant_id = ? LIMIT 1');
            $saleStmt->execute([$saleId, $tenantId]);
            $sale = $saleStmt->fetch();
            if (!$sale) {
                throw new RuntimeException('Sale not found.');
            }
            $refundTotal = 0.0;
            $ret = $pdo->prepare(
                'INSERT INTO returns (tenant_id, branch_id, sale_id, refund_method, refund_amount, reason, status, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, "completed", ?, NOW(), NOW())'
            );
            $ret->execute([$tenantId, (int)$sale['branch_id'], $saleId, $refundMethod, $reason, $userId]);
            $returnId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare('INSERT INTO return_items (return_id, sale_item_id, medicine_id, batch_id, quantity, amount) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($items as $i) {
                $si = $pdo->prepare('SELECT * FROM sale_items WHERE id = ? AND sale_id = ? LIMIT 1');
                $si->execute([(int)$i['sale_item_id'], $saleId]);
                $line = $si->fetch();
                if (!$line || (int)$i['quantity'] <= 0 || (int)$i['quantity'] > (int)$line['quantity']) {
                    throw new RuntimeException('Invalid return quantity.');
                }
                $amount = round(((float)$line['total'] / max(1, (int)$line['quantity'])) * (int)$i['quantity'], 2);
                $refundTotal += $amount;
                $itemStmt->execute([$returnId, (int)$line['id'], (int)$line['medicine_id'], (int)$line['batch_id'], (int)$i['quantity'], $amount]);

                // Restore stock only when the batch is still in date.
                $b = $pdo->prepare('SELECT expiry_date FROM batches WHERE id = ?');
                $b->execute([(int)$line['batch_id']]);
                $expiry = $b->fetchColumn();
                if ($expiry && strtotime((string)$expiry) >= strtotime(date('Y-m-d'))) {
                    $pdo->prepare('UPDATE batches SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?')
                        ->execute([(int)$i['quantity'], (int)$line['batch_id']]);
                    self::movement($pdo, [
                        'tenant_id' => $tenantId, 'branch_id' => (int)$sale['branch_id'], 'medicine_id' => (int)$line['medicine_id'],
                        'batch_id' => (int)$line['batch_id'], 'type' => 'return', 'quantity' => (int)$i['quantity'],
                        'balance_after' => self::batchBalance($pdo, (int)$line['batch_id']),
                        'reference_type' => 'return', 'reference_id' => $returnId,
                        'note' => 'Sales return — ' . $reason, 'created_by' => $userId,
                    ]);
                }
            }
            $pdo->prepare('UPDATE returns SET refund_amount = ?, updated_at = NOW() WHERE id = ?')->execute([$refundTotal, $returnId]);
            $pdo->commit();
            activity_log('sale.returned', 'Return #' . $returnId . ' — refund ' . money($refundTotal) . ' via ' . $refundMethod, $tenantId, $userId);
            return $returnId;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /* -------------------------------------------------------- Adjustments */

    /** Manual stock adjustment (damage, loss, correction) — transactional. */
    public static function adjust(int $tenantId, int $batchId, int $delta, string $reason, ?int $userId): void
    {
        $pdo = db();
        $allowNegative = self::tenantSetting($tenantId, 'allow_negative_stock', '0') === '1';
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM batches WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$batchId, $tenantId]);
            $batch = $stmt->fetch();
            if (!$batch) {
                throw new RuntimeException('Batch not found.');
            }
            $newQty = (int)$batch['quantity'] + $delta;
            if ($newQty < 0 && !$allowNegative) {
                throw new RuntimeException('Adjustment would make stock negative. Enable the authorized negative-stock policy in Settings to allow this.');
            }
            $pdo->prepare('UPDATE batches SET quantity = ?, updated_at = NOW() WHERE id = ?')->execute([$newQty, $batchId]);
            self::movement($pdo, [
                'tenant_id' => $tenantId, 'branch_id' => (int)$batch['branch_id'], 'medicine_id' => (int)$batch['medicine_id'],
                'batch_id' => $batchId, 'type' => 'adjustment', 'quantity' => $delta, 'balance_after' => $newQty,
                'reference_type' => 'adjustment', 'reference_id' => null,
                'note' => $reason, 'created_by' => $userId,
            ]);
            $pdo->commit();
            activity_log('stock.adjusted', 'Batch ' . $batch['batch_number'] . ' adjusted by ' . $delta . ' — ' . $reason, $tenantId, $userId);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /* --------------------------------------------------- Purchase orders */

    /**
     * Create a purchase order (no stock movement — stock only moves when
     * goods are received against the order).
     * $items: [ ['medicine_id'=>, 'quantity'=>, 'purchase_price'=>] ]
     */
    public static function createPurchaseOrder(int $tenantId, int $branchId, int $supplierId, array $items, ?string $expectedDate, ?string $note, ?int $userId): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $total = 0.0;
            foreach ($items as $i) {
                $qty = (int)($i['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new RuntimeException('Every purchase order line needs a quantity above zero.');
                }
                $check = $pdo->prepare('SELECT COUNT(*) FROM medicines WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL');
                $check->execute([(int)$i['medicine_id'], $tenantId]);
                if ((int)$check->fetchColumn() === 0) {
                    throw new RuntimeException('Medicine #' . $i['medicine_id'] . ' does not belong to this pharmacy.');
                }
                $total += $qty * (float)($i['purchase_price'] ?? 0);
            }
            $number = 'PO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $pdo->prepare(
                'INSERT INTO purchase_orders (tenant_id, branch_id, supplier_id, order_number, status, expected_date, notes, total_amount, ordered_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, "ordered", ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$tenantId, $branchId, $supplierId, $number, $expectedDate ?: null, $note, $total, $userId]);
            $poId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, medicine_id, quantity_ordered, quantity_received, purchase_price, total) VALUES (?, ?, ?, 0, ?, ?)'
            );
            foreach ($items as $i) {
                $itemStmt->execute([$poId, (int)$i['medicine_id'], (int)$i['quantity'], (float)($i['purchase_price'] ?? 0), (int)$i['quantity'] * (float)($i['purchase_price'] ?? 0)]);
            }
            $pdo->commit();
            activity_log('purchase_order.created', 'Purchase order ' . $number . ' — ' . money($total), $tenantId, $userId);
            return $poId;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /**
     * Receive goods against a purchase order — supports PARTIAL deliveries.
     * $lines: [ ['po_item_id'=>, 'quantity'=>, 'batch_number'=>,
     *            'manufacturing_date'=>, 'expiry_date'=>, 'selling_price'=>] ]
     * Creates a normal purchase record (batches + movements) for what
     * actually arrived and returns the new purchase id.
     */
    public static function receivePurchaseOrder(int $tenantId, int $poId, array $lines, ?int $userId): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $poStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE');
            $poStmt->execute([$poId, $tenantId]);
            $po = $poStmt->fetch();
            if (!$po) {
                throw new RuntimeException('Purchase order not found.');
            }
            if (!in_array($po['status'], ['draft', 'ordered', 'partially_received'], true)) {
                throw new RuntimeException('This purchase order can no longer receive goods (status: ' . $po['status'] . ').');
            }
            $branchId = (int)$po['branch_id'];
            $supplierId = (int)$po['supplier_id'];

            // Validate every line against what is still outstanding.
            $prepared = [];
            $total = 0.0;
            foreach ($lines as $line) {
                $qty = (int)($line['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $li = $pdo->prepare('SELECT * FROM purchase_order_items WHERE id = ? AND purchase_order_id = ? FOR UPDATE');
                $li->execute([(int)$line['po_item_id'], $poId]);
                $poItem = $li->fetch();
                if (!$poItem) {
                    throw new RuntimeException('Purchase order line not found.');
                }
                $remaining = (int)$poItem['quantity_ordered'] - (int)$poItem['quantity_received'];
                if ($qty > $remaining) {
                    throw new RuntimeException('Receiving ' . $qty . ' exceeds the outstanding quantity (' . $remaining . ') on this order line.');
                }
                if (empty($line['batch_number']) || empty($line['expiry_date'])) {
                    throw new RuntimeException('Every received line needs a batch number and an expiry date.');
                }
                $total += $qty * (float)$poItem['purchase_price'];
                $prepared[] = ['po_item' => $poItem, 'line' => $line, 'qty' => $qty];
            }
            if ($prepared === []) {
                throw new RuntimeException('Nothing to receive.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO purchases (tenant_id, branch_id, supplier_id, invoice_reference, total_amount, status, purchased_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, "received", ?, NOW(), NOW())'
            );
            $stmt->execute([$tenantId, $branchId, $supplierId, 'PO:' . $po['order_number'], $total, $userId]);
            $purchaseId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO purchase_items (purchase_id, medicine_id, batch_id, quantity, purchase_price, total) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($prepared as $p) {
                $poItem = $p['po_item'];
                $line = $p['line'];
                $batchId = self::findOrCreateBatch(
                    $pdo, $tenantId, $branchId, (int)$poItem['medicine_id'], $supplierId,
                    (string)$line['batch_number'], $line['manufacturing_date'] ?? null, (string)$line['expiry_date'],
                    (float)$poItem['purchase_price'],
                    (float)($line['selling_price'] ?? 0) > 0 ? (float)$line['selling_price'] : (float)$poItem['purchase_price'],
                    $p['qty']
                );
                $itemStmt->execute([$purchaseId, (int)$poItem['medicine_id'], $batchId, $p['qty'], (float)$poItem['purchase_price'], $p['qty'] * (float)$poItem['purchase_price']]);
                self::movement($pdo, [
                    'tenant_id' => $tenantId, 'branch_id' => $branchId, 'medicine_id' => (int)$poItem['medicine_id'],
                    'batch_id' => $batchId, 'type' => 'purchase', 'quantity' => $p['qty'],
                    'balance_after' => self::batchBalance($pdo, $batchId),
                    'reference_type' => 'purchase', 'reference_id' => $purchaseId,
                    'note' => 'Received against ' . $po['order_number'], 'created_by' => $userId,
                ]);
                $pdo->prepare('UPDATE purchase_order_items SET quantity_received = quantity_received + ? WHERE id = ?')
                    ->execute([$p['qty'], (int)$poItem['id']]);
            }

            $open = $pdo->prepare('SELECT COALESCE(SUM(quantity_ordered - quantity_received), 0) FROM purchase_order_items WHERE purchase_order_id = ?');
            $open->execute([$poId]);
            $status = (int)$open->fetchColumn() > 0 ? 'partially_received' : 'received';
            $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $poId]);

            $pdo->commit();
            activity_log('purchase_order.received', 'Received against ' . $po['order_number'] . ' — ' . money($total) . ' (purchase #' . $purchaseId . ')', $tenantId, $userId);
            return $purchaseId;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /* ------------------------------------------- Purchase returns & payables */

    /**
     * Return stock from a received purchase back to its supplier.
     * $items: [ ['purchase_item_id'=>, 'quantity'=>] ]
     * Decrements the original batch inside a transaction (the batch must
     * still hold the units) and writes a permanent movement record.
     */
    public static function createPurchaseReturn(int $tenantId, int $purchaseId, array $items, string $reason, ?int $userId): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pStmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1');
            $pStmt->execute([$purchaseId, $tenantId]);
            $purchase = $pStmt->fetch();
            if (!$purchase) {
                throw new RuntimeException('Purchase not found.');
            }
            $ret = $pdo->prepare(
                'INSERT INTO purchase_returns (tenant_id, branch_id, purchase_id, supplier_id, total_amount, reason, status, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, "completed", ?, NOW(), NOW())'
            );
            $ret->execute([$tenantId, (int)$purchase['branch_id'], $purchaseId, (int)$purchase['supplier_id'], $reason, $userId]);
            $returnId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare('INSERT INTO purchase_return_items (purchase_return_id, purchase_item_id, medicine_id, batch_id, quantity, amount) VALUES (?, ?, ?, ?, ?, ?)');
            $total = 0.0;
            foreach ($items as $i) {
                $qty = (int)($i['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $pi = $pdo->prepare('SELECT * FROM purchase_items WHERE id = ? AND purchase_id = ? FOR UPDATE');
                $pi->execute([(int)$i['purchase_item_id'], $purchaseId]);
                $line = $pi->fetch();
                if (!$line) {
                    throw new RuntimeException('Purchase line not found.');
                }
                $already = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM purchase_return_items pri JOIN purchase_returns pr ON pr.id = pri.purchase_return_id WHERE pri.purchase_item_id = ? AND pr.status = "completed"');
                $already->execute([(int)$line['id']]);
                if ($qty > (int)$line['quantity'] - (int)$already->fetchColumn()) {
                    throw new RuntimeException('Return quantity exceeds what is returnable on this purchase line.');
                }
                $batchId = (int)$line['batch_id'];
                $balance = self::batchBalance($pdo, $batchId);
                if ($balance < $qty) {
                    throw new RuntimeException('Batch no longer holds ' . $qty . ' units to return (some stock was already sold).');
                }
                $amount = round($qty * (float)$line['purchase_price'], 2);
                $total += $amount;
                $pdo->prepare('UPDATE batches SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?')->execute([$qty, $batchId]);
                $itemStmt->execute([$returnId, (int)$line['id'], (int)$line['medicine_id'], $batchId, $qty, $amount]);
                self::movement($pdo, [
                    'tenant_id' => $tenantId, 'branch_id' => (int)$purchase['branch_id'], 'medicine_id' => (int)$line['medicine_id'],
                    'batch_id' => $batchId, 'type' => 'return', 'quantity' => -$qty,
                    'balance_after' => self::batchBalance($pdo, $batchId),
                    'reference_type' => 'purchase_return', 'reference_id' => $returnId,
                    'note' => 'Purchase return — ' . $reason, 'created_by' => $userId,
                ]);
            }
            if ($total <= 0) {
                throw new RuntimeException('Nothing to return.');
            }
            $pdo->prepare('UPDATE purchase_returns SET total_amount = ?, updated_at = NOW() WHERE id = ?')->execute([$total, $returnId]);
            $pdo->commit();
            audit_log('purchase_returns', $returnId, 'created', null, ['total' => $total, 'reason' => $reason]);
            activity_log('purchase.returned', 'Purchase return #' . $returnId . ' — ' . money($total), $tenantId, $userId);
            return $returnId;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /** Outstanding balance owed to a supplier — always derived, never stored. */
    public static function supplierBalance(int $tenantId, int $supplierId): float
    {
        $pdo = db();
        $bought = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE tenant_id = ? AND supplier_id = ? AND status = "received"');
        $bought->execute([$tenantId, $supplierId]);
        $paid = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE tenant_id = ? AND supplier_id = ?');
        $paid->execute([$tenantId, $supplierId]);
        $returned = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM purchase_returns WHERE tenant_id = ? AND supplier_id = ? AND status = "completed"');
        $returned->execute([$tenantId, $supplierId]);
        return round((float)$bought->fetchColumn() - (float)$paid->fetchColumn() - (float)$returned->fetchColumn(), 2);
    }

    /** Record a payment made to a supplier (reduces the derived balance). */
    public static function recordSupplierPayment(int $tenantId, int $supplierId, ?int $purchaseId, float $amount, string $method, ?string $reference, string $paidAt, ?string $note, ?int $userId): int
    {
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be above zero.');
        }
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO supplier_payments (tenant_id, supplier_id, purchase_id, amount, method, reference, paid_at, notes, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$tenantId, $supplierId, $purchaseId, $amount, $method, $reference, $paidAt ?: date('Y-m-d'), $note, $userId]);
        $id = (int)$pdo->lastInsertId();
        audit_log('supplier_payments', $id, 'created', null, ['amount' => $amount, 'method' => $method]);
        activity_log('supplier.paid', 'Supplier payment ' . money($amount) . ' via ' . $method, $tenantId, $userId);
        return $id;
    }

    /* ---------------------------------------------------- Branch transfers */

    /**
     * Create an inter-branch transfer request. No stock moves yet — stock
     * moves only when the transfer is completed.
     * $items: [ ['medicine_id'=>, 'quantity'=>] ]
     */
    public static function createTransfer(int $tenantId, int $fromBranch, int $toBranch, array $items, ?string $note, ?int $userId): int
    {
        if ($fromBranch === $toBranch) {
            throw new RuntimeException('Source and destination branches must be different.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE tenant_id = ? AND id IN (?, ?) AND deleted_at IS NULL');
            $chk->execute([$tenantId, $fromBranch, $toBranch]);
            if ((int)$chk->fetchColumn() !== 2) {
                throw new RuntimeException('Both branches must belong to this pharmacy.');
            }
            $number = 'TRF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $pdo->prepare(
                'INSERT INTO stock_transfers (tenant_id, from_branch_id, to_branch_id, transfer_number, status, notes, requested_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, "pending", ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$tenantId, $fromBranch, $toBranch, $number, $note, $userId]);
            $transferId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO stock_transfer_items (transfer_id, medicine_id, quantity) VALUES (?, ?, ?)');
            $lines = 0;
            foreach ($items as $i) {
                $qty = (int)($i['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $itemStmt->execute([$transferId, (int)$i['medicine_id'], $qty]);
                $lines++;
            }
            if ($lines === 0) {
                throw new RuntimeException('Add at least one medicine to transfer.');
            }
            $pdo->commit();
            activity_log('transfer.created', 'Transfer ' . $number . ' requested', $tenantId, $userId);
            return $transferId;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /**
     * Complete a transfer in ONE transaction: FEFO allocation out of the
     * source branch (expired batches are never moved), find-or-create of the
     * matching batch in the destination branch, and two 'transfer'
     * stock_movements rows per allocation.
     */
    public static function completeTransfer(int $tenantId, int $transferId, ?int $userId): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM stock_transfers WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$transferId, $tenantId]);
            $transfer = $stmt->fetch();
            if (!$transfer) {
                throw new RuntimeException('Transfer not found.');
            }
            if (!in_array($transfer['status'], ['pending', 'in_transit'], true)) {
                throw new RuntimeException('Transfer is already ' . $transfer['status'] . '.');
            }
            $from = (int)$transfer['from_branch_id'];
            $to = (int)$transfer['to_branch_id'];
            $items = $pdo->prepare('SELECT * FROM stock_transfer_items WHERE transfer_id = ?');
            $items->execute([$transferId]);
            foreach ($items->fetchAll() as $item) {
                $remaining = (int)$item['quantity'];
                $firstSourceBatch = null;
                foreach (self::fefoBatches($tenantId, (int)$item['medicine_id'], $from) as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $balance = self::batchBalance($pdo, (int)$batch['id']);
                    $take = min($remaining, $balance);
                    if ($take <= 0) {
                        continue;
                    }
                    $firstSourceBatch ??= (int)$batch['id'];
                    $pdo->prepare('UPDATE batches SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$take, (int)$batch['id']]);
                    self::movement($pdo, [
                        'tenant_id' => $tenantId, 'branch_id' => $from, 'medicine_id' => (int)$item['medicine_id'],
                        'batch_id' => (int)$batch['id'], 'type' => 'transfer', 'quantity' => -$take,
                        'balance_after' => self::batchBalance($pdo, (int)$batch['id']),
                        'reference_type' => 'transfer', 'reference_id' => $transferId,
                        'note' => 'Transfer ' . $transfer['transfer_number'] . ' out', 'created_by' => $userId,
                    ]);
                    $destBatchId = self::findOrCreateBatch(
                        $pdo, $tenantId, $to, (int)$item['medicine_id'], $batch['supplier_id'] ? (int)$batch['supplier_id'] : null,
                        (string)$batch['batch_number'], $batch['manufacturing_date'], (string)$batch['expiry_date'],
                        (float)$batch['purchase_price'], (float)$batch['selling_price'], $take
                    );
                    self::movement($pdo, [
                        'tenant_id' => $tenantId, 'branch_id' => $to, 'medicine_id' => (int)$item['medicine_id'],
                        'batch_id' => $destBatchId, 'type' => 'transfer', 'quantity' => $take,
                        'balance_after' => self::batchBalance($pdo, $destBatchId),
                        'reference_type' => 'transfer', 'reference_id' => $transferId,
                        'note' => 'Transfer ' . $transfer['transfer_number'] . ' in', 'created_by' => $userId,
                    ]);
                    $remaining -= $take;
                }
                if ($remaining > 0) {
                    throw new RuntimeException('Insufficient non-expired stock in the source branch for medicine #' . $item['medicine_id'] . '.');
                }
                if ($firstSourceBatch) {
                    $pdo->prepare('UPDATE stock_transfer_items SET batch_id = ? WHERE id = ?')->execute([$firstSourceBatch, (int)$item['id']]);
                }
            }
            $pdo->prepare('UPDATE stock_transfers SET status = "completed", completed_by = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute([$userId, $transferId]);
            $pdo->commit();
            activity_log('transfer.completed', 'Transfer ' . $transfer['transfer_number'] . ' completed', $tenantId, $userId);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /** Cancel a pending transfer (no stock has moved, so nothing to undo). */
    public static function cancelTransfer(int $tenantId, int $transferId, ?int $userId): void
    {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE stock_transfers SET status = "cancelled", updated_at = NOW() WHERE id = ? AND tenant_id = ? AND status IN ("pending","in_transit")');
        $stmt->execute([$transferId, $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Transfer cannot be cancelled (already completed, cancelled or not found).');
        }
        activity_log('transfer.cancelled', 'Transfer #' . $transferId . ' cancelled', $tenantId, $userId);
    }

    /* ------------------------------------------------------ Prescriptions */

    /**
     * Mark a prescription dispensed and stamp WHO dispensed it and WHEN —
     * the authorized dispensing record. Only pending / partially dispensed
     * prescriptions can be dispensed.
     */
    public static function dispensePrescription(int $tenantId, int $rxId, ?int $userId): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'UPDATE prescriptions SET status = "dispensed", dispensed_by = ?, dispensed_at = NOW(), updated_at = NOW()
              WHERE id = ? AND tenant_id = ? AND status IN ("pending","partially_dispensed") AND deleted_at IS NULL'
        );
        $stmt->execute([$userId, $rxId, $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Prescription cannot be dispensed (already dispensed, cancelled or not found).');
        }
        audit_log('prescriptions', $rxId, 'dispensed', null, ['dispensed_by' => $userId]);
        activity_log('prescription.dispensed', 'Prescription #' . $rxId . ' dispensed', $tenantId, $userId);
    }
}
