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
                // Reuse an identical open batch (same number + expiry) or create one.
                $find = $pdo->prepare(
                    'SELECT id FROM batches WHERE tenant_id = ? AND branch_id = ? AND medicine_id = ? AND batch_number = ? AND expiry_date = ? LIMIT 1'
                );
                $find->execute([$tenantId, $branchId, (int)$i['medicine_id'], $i['batch_number'], $i['expiry_date']]);
                $batchId = $find->fetchColumn();
                if (!$batchId) {
                    $ins = $pdo->prepare(
                        'INSERT INTO batches (tenant_id, branch_id, medicine_id, supplier_id, batch_number, manufacturing_date, expiry_date, purchase_price, selling_price, quantity, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                    );
                    $ins->execute([
                        $tenantId, $branchId, (int)$i['medicine_id'], $supplierId, $i['batch_number'],
                        $i['manufacturing_date'] ?: null, $i['expiry_date'], (float)$i['purchase_price'],
                        (float)($i['selling_price'] ?? $i['purchase_price']), (int)$i['quantity'],
                    ]);
                    $batchId = (int)$pdo->lastInsertId();
                } else {
                    $pdo->prepare('UPDATE batches SET quantity = quantity + ?, purchase_price = ?, selling_price = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([(int)$i['quantity'], (float)$i['purchase_price'], (float)($i['selling_price'] ?? $i['purchase_price']), $batchId]);
                }
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
}
