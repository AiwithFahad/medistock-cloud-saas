<?php
/**
 * MediStock Cloud — Medicines catalogue.
 * Full fields incl. photo upload, manufacturer, default supplier and
 * default prices, prescription classification and status — plus CSV
 * import/export. The list itself is the shared Crud engine (search,
 * sorting, pagination); export/import are handled before it renders.
 *
 * CSV columns: name, generic_name, sku, barcode, strength, dosage_form,
 * unit, category, brand, manufacturer, reorder_level,
 * requires_prescription (1/0), default_purchase_price,
 * default_selling_price, status (active/inactive).
 * Category and brand are matched by name (created when missing).
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
$tid = tenant_id();
$pdo = db();

/* ------------------------------------------------------------ CSV export */
if ((string)input('export', '') === 'csv' && can('medicines.view')) {
    $stmt = $pdo->prepare(
        'SELECT m.*, c.name AS category, b.name AS brand FROM medicines m
           LEFT JOIN categories c ON c.id = m.category_id
           LEFT JOIN brands b ON b.id = m.brand_id
          WHERE m.tenant_id = ? AND m.deleted_at IS NULL ORDER BY m.name'
    );
    $stmt->execute([$tid]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="medicines.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'generic_name', 'sku', 'barcode', 'strength', 'dosage_form', 'unit', 'category', 'brand', 'manufacturer', 'reorder_level', 'requires_prescription', 'default_purchase_price', 'default_selling_price', 'status']);
    foreach ($stmt->fetchAll() as $m) {
        fputcsv($out, [
            $m['name'], $m['generic_name'], $m['sku'], $m['barcode'], $m['strength'], $m['dosage_form'], $m['unit'],
            $m['category'], $m['brand'], $m['manufacturer'], $m['reorder_level'], (int)$m['requires_prescription'],
            $m['default_purchase_price'], $m['default_selling_price'], $m['status'],
        ]);
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------ CSV import */
if (is_post() && (string)input('_op', '') === 'import') {
    csrf_check();
    try {
        if (!can('medicines.manage')) {
            throw new RuntimeException('You do not have permission to import medicines.');
        }
        $limitErr = SubscriptionService::limitError($tid, 'medicines');
        if ($limitErr) {
            throw new RuntimeException($limitErr);
        }
        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose a CSV file to import.');
        }
        $handle = fopen((string)$_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            throw new RuntimeException('Could not read the uploaded file.');
        }
        $header = fgetcsv($handle);
        if (!$header) {
            throw new RuntimeException('The CSV file is empty.');
        }
        $cols = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
        $idx = array_flip($cols);
        if (!isset($idx['name'])) {
            throw new RuntimeException('The CSV needs at least a "name" column.');
        }
        $get = static function (array $row, array $idx, string $key): string {
            return isset($idx[$key]) ? trim((string)($row[$idx[$key]] ?? '')) : '';
        };
        $findOrCreate = static function (string $table, string $name) use ($pdo, $tid): ?int {
            if ($name === '') {
                return null;
            }
            $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE tenant_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$tid, $name]);
            $id = $stmt->fetchColumn();
            if ($id) {
                return (int)$id;
            }
            $ins = $pdo->prepare("INSERT INTO {$table} (tenant_id, name, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
            $ins->execute([$tid, $name]);
            return (int)$pdo->lastInsertId();
        };
        $insert = $pdo->prepare(
            'INSERT INTO medicines (tenant_id, category_id, brand_id, name, generic_name, sku, barcode, strength, dosage_form, unit, manufacturer, default_purchase_price, default_selling_price, status, reorder_level, requires_prescription, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $imported = 0;
        $skipped = 0;
        $pdo->beginTransaction();
        while (($row = fgetcsv($handle)) !== false && $imported + $skipped < 500) {
            $name = $get($row, $idx, 'name');
            if ($name === '') {
                $skipped++;
                continue;
            }
            $insert->execute([
                $tid,
                $findOrCreate('categories', $get($row, $idx, 'category')),
                $findOrCreate('brands', $get($row, $idx, 'brand')),
                $name,
                $get($row, $idx, 'generic_name') ?: null,
                $get($row, $idx, 'sku') ?: null,
                $get($row, $idx, 'barcode') ?: null,
                $get($row, $idx, 'strength') ?: null,
                $get($row, $idx, 'dosage_form') ?: null,
                $get($row, $idx, 'unit') ?: 'tablet',
                $get($row, $idx, 'manufacturer') ?: null,
                (float)$get($row, $idx, 'default_purchase_price'),
                (float)$get($row, $idx, 'default_selling_price'),
                $get($row, $idx, 'status') === 'inactive' ? 'inactive' : 'active',
                max(0, (int)$get($row, $idx, 'reorder_level')),
                $get($row, $idx, 'requires_prescription') === '1' ? 1 : 0,
            ]);
            $imported++;
        }
        $pdo->commit();
        fclose($handle);
        audit_log('medicines', null, 'csv_import', null, ['imported' => $imported, 'skipped' => $skipped]);
        flash('success', "CSV import complete: {$imported} medicine(s) imported, {$skipped} row(s) skipped.");
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Import failed: ' . $ex->getMessage());
    }
    redirect('admin/medicines.php');
}

admin_header('Medicines', 'admin/medicines.php');
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-outline-secondary btn-sm" href="medicines.php?export=csv"><i class="bi bi-download"></i> Export CSV</a>
  <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#csvImport"><i class="bi bi-upload"></i> Import CSV</button>
</div>
<div class="collapse mb-3" id="csvImport"><div class="card shadow-sm"><div class="card-body">
  <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
    <?= csrf_field() ?><input type="hidden" name="_op" value="import">
    <input type="file" class="form-control form-control-sm w-auto" name="csv_file" accept=".csv,text/csv" required>
    <button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-check-lg"></i> Import</button>
    <span class="text-muted small">Columns: name, generic_name, sku, barcode, strength, dosage_form, unit, category, brand, manufacturer, reorder_level, requires_prescription (1/0), default_purchase_price, default_selling_price, status. Max 500 rows; categories/brands are created when missing.</span>
  </form>
</div></div></div>
<?php
Crud::handle([
    'title' => 'Medicine', 'icon' => 'bi-capsule', 'table' => 'medicines',
    'permission' => 'medicines.view', 'base' => 'medicines.php',
    'columns' => [
        'photo_path' => 'Photo', 'name' => 'Medicine', 'generic_name' => 'Generic', 'sku' => 'SKU', 'barcode' => 'Barcode',
        'strength' => 'Strength', 'unit' => 'Unit', 'reorder_level' => 'Reorder level', 'requires_prescription' => 'Rx', 'status' => 'Status',
    ],
    'display' => [
        'photo_path' => static fn($r) => $r['photo_path']
            ? '<img class="thumb-sm" src="' . e(asset($r['photo_path'])) . '" alt="">'
            : '<span class="thumb-sm d-inline-flex align-items-center justify-content-center bg-light"><i class="bi bi-capsule text-muted"></i></span>',
        'requires_prescription' => static fn($r) => $r['requires_prescription'] ? '<span class="badge bg-warning text-dark">Rx</span>' : '<span class="badge bg-light text-dark">OTC</span>',
    ],
    'search' => ['name', 'generic_name', 'sku', 'barcode', 'manufacturer'],
    'fields' => [
        'name' => ['label' => 'Medicine name', 'required' => true],
        'generic_name' => ['label' => 'Generic name'],
        'photo_path' => ['label' => 'Photo', 'type' => 'file', 'help' => 'JPG/PNG/WEBP, max 2MB'],
        'category_id' => ['label' => 'Category', 'type' => 'select', 'options_sql' => 'SELECT id, name AS label FROM categories WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'brand_id' => ['label' => 'Brand', 'type' => 'select', 'options_sql' => 'SELECT id, name AS label FROM brands WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'manufacturer' => ['label' => 'Manufacturer'],
        'default_supplier_id' => ['label' => 'Default supplier', 'type' => 'select', 'options_sql' => 'SELECT id, name AS label FROM suppliers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name'],
        'sku' => ['label' => 'SKU'],
        'barcode' => ['label' => 'Barcode'],
        'strength' => ['label' => 'Strength', 'help' => 'e.g. 500mg, 250mg/5ml'],
        'dosage_form' => ['label' => 'Dosage form', 'type' => 'select', 'options' => ['Tablet' => 'Tablet', 'Capsule' => 'Capsule', 'Syrup' => 'Syrup', 'Injection' => 'Injection', 'Cream' => 'Cream', 'Drops' => 'Drops', 'Inhaler' => 'Inhaler', 'Other' => 'Other']],
        'unit' => ['label' => 'Unit', 'help' => 'e.g. tablet, strip, bottle', 'default' => 'tablet'],
        'default_purchase_price' => ['label' => 'Default purchase price', 'type' => 'number', 'step' => '0.01', 'default' => 0],
        'default_selling_price' => ['label' => 'Default selling price', 'type' => 'number', 'step' => '0.01', 'default' => 0],
        'rack_location' => ['label' => 'Rack / shelf location'],
        'reorder_level' => ['label' => 'Reorder level (low-stock alert)', 'type' => 'number', 'default' => 10],
        'requires_prescription' => ['label' => 'Requires prescription', 'type' => 'checkbox'],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'default' => 'active'],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'col' => 'col-12'],
    ],
    'before_save' => static function (array $data, ?int $id): array {
        // Enforce the subscription plan limit for medicine records on create.
        if ($id === null) {
            $err = SubscriptionService::limitError(tenant_id(), 'medicines');
            if ($err) {
                throw new RuntimeException($err);
            }
        }
        foreach (['category_id', 'brand_id', 'default_supplier_id'] as $k) {
            $data[$k] = !empty($data[$k]) ? (int)$data[$k] : null;
        }
        return $data;
    },
]);
admin_footer();
