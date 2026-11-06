<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
admin_header('Stock Batches', 'admin/batches.php');
Crud::handle([
    'title' => 'Batch', 'icon' => 'bi-box-seam', 'table' => 'batches',
    'permission' => 'stock.view', 'base' => 'batches.php',
    'order' => 'expiry_date ASC',
    'columns' => ['batch_number' => 'Batch #', 'medicine_id' => 'Medicine', 'branch_id' => 'Branch', 'manufacturing_date' => 'Mfg date', 'expiry_date' => 'Expiry', 'purchase_price' => 'Buy price', 'selling_price' => 'Sell price', 'quantity' => 'Qty'],
    'display' => [
        'medicine_id' => static function ($r) {
            $s = db()->prepare('SELECT name FROM medicines WHERE id = ?');
            $s->execute([$r['medicine_id']]);
            return e($s->fetchColumn() ?: '—');
        },
        'branch_id' => static function ($r) {
            $s = db()->prepare('SELECT name FROM branches WHERE id = ?');
            $s->execute([$r['branch_id']]);
            return e($s->fetchColumn() ?: '—');
        },
        'purchase_price' => static fn($r) => money($r['purchase_price']),
        'selling_price' => static fn($r) => money($r['selling_price']),
        'expiry_date' => static function ($r) {
            $days = (int)floor((strtotime($r['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
            if ($days < 0) {
                return '<span class="badge bg-danger">Expired ' . fmt_date($r['expiry_date']) . '</span>';
            }
            if ($days <= 90) {
                return '<span class="badge bg-warning text-dark">' . fmt_date($r['expiry_date']) . ' (' . $days . 'd)</span>';
            }
            return e(fmt_date($r['expiry_date']));
        },
        'quantity' => static fn($r) => '<strong>' . number_format((int)$r['quantity']) . '</strong>',
    ],
    'search' => ['batch_number'],
    'fields' => [
        'medicine_id' => ['label' => 'Medicine', 'type' => 'select', 'required' => true, 'options_sql' => 'SELECT id, name AS label FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'branch_id' => ['label' => 'Branch', 'type' => 'select', 'required' => true, 'options_sql' => 'SELECT id, name AS label FROM branches WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'supplier_id' => ['label' => 'Supplier', 'type' => 'select', 'options_sql' => 'SELECT id, name AS label FROM suppliers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'batch_number' => ['label' => 'Batch number', 'required' => true],
        'manufacturing_date' => ['label' => 'Manufacturing date', 'type' => 'date'],
        'expiry_date' => ['label' => 'Expiry date', 'type' => 'date', 'required' => true],
        'purchase_price' => ['label' => 'Purchase price (per unit)', 'type' => 'number', 'step' => '0.01', 'required' => true],
        'selling_price' => ['label' => 'Selling price (per unit)', 'type' => 'number', 'step' => '0.01', 'required' => true],
        'quantity' => ['label' => 'Opening quantity', 'type' => 'number', 'default' => 0, 'help' => 'For regular stock-in, prefer Purchases — receiving creates batches automatically.'],
    ],
    'before_save' => static function (array $data, ?int $id): array {
        foreach (['medicine_id', 'branch_id'] as $k) {
            $data[$k] = (int)$data[$k];
        }
        $data['supplier_id'] = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;
        if (strtotime((string)$data['expiry_date']) === false) {
            throw new RuntimeException('A valid expiry date is required for every batch.');
        }
        return $data;
    },
    'after_save' => static function (int $id, array $data): void {
        // Record an opening-stock movement for newly created batches so the
        // ledger always explains where units came from.
        $check = db()->prepare("SELECT COUNT(*) FROM stock_movements WHERE batch_id = ? AND type = 'opening'");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() === 0 && (int)($data['quantity'] ?? 0) > 0) {
            db()->prepare(
                'INSERT INTO stock_movements (tenant_id, branch_id, medicine_id, batch_id, type, quantity, balance_after, reference_type, note, created_by, created_at)
                 VALUES (?, ?, ?, ?, "opening", ?, ?, "batch", "Opening stock", ?, NOW())'
            )->execute([tenant_id(), (int)$data['branch_id'], (int)$data['medicine_id'], $id, (int)$data['quantity'], (int)$data['quantity'], current_user()['id']]);
        }
    },
]);
admin_footer();
