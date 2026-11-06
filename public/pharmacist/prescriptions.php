<?php
/**
 * MediStock Cloud — Pharmacist prescription management.
 * Create prescriptions with line items (medicine, dosage, quantity,
 * instructions), review them, sell against them at the POS, and record
 * AUTHORIZED dispensing: only pharmacist / admin / owner roles reach
 * this page (server-side RBAC), and dispensing stamps who + when via
 * StockService::dispensePrescription().
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['pharmacist', 'admin', 'owner']);
$tid = tenant_id();
$pdo = db();
$uid = (int)$user['id'];

if (is_post()) {
    csrf_check();
    $op = (string)input('_op', '');
    try {
        if ($op === 'create') {
            $customerId = (int)input('customer_id', 0) ?: null;
            $number = trim((string)input('prescription_number', ''));
            if ($number === '') {
                $number = 'RX-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO prescriptions (tenant_id, customer_id, prescription_number, patient_name, doctor_name, hospital, prescribed_at, status, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?, NOW(), NOW())'
            );
            $stmt->execute([
                $tid, $customerId, $number,
                trim((string)input('patient_name', '')) ?: null,
                trim((string)input('doctor_name', '')),
                trim((string)input('hospital', '')) ?: null,
                input('prescribed_at') ?: date('Y-m-d'),
                trim((string)input('notes', '')) ?: null,
            ]);
            $rxId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO prescription_items (prescription_id, medicine_id, medicine_name, dosage, quantity, instructions) VALUES (?, ?, ?, ?, ?, ?)');
            $names = $_POST['item_name'] ?? [];
            $medIds = $_POST['item_medicine_id'] ?? [];
            $dosages = $_POST['item_dosage'] ?? [];
            $qtys = $_POST['item_qty'] ?? [];
            $instr = $_POST['item_instructions'] ?? [];
            $lines = 0;
            foreach ((array)$names as $i => $name) {
                $name = trim((string)$name);
                if ($name === '') {
                    continue;
                }
                $itemStmt->execute([$rxId, ((int)($medIds[$i] ?? 0)) ?: null, $name, trim((string)($dosages[$i] ?? '')) ?: null, max(1, (int)($qtys[$i] ?? 1)), trim((string)($instr[$i] ?? '')) ?: null]);
                $lines++;
            }
            $pdo->commit();
            audit_log('prescriptions', $rxId, 'created', null, ['number' => $number, 'lines' => $lines]);
            activity_log('prescription.created', 'Prescription ' . $number . ' recorded', $tid, $uid);
            flash('success', 'Prescription ' . $number . ' recorded with ' . $lines . ' line item(s).');
            redirect('pharmacist/prescriptions.php?id=' . $rxId);
        }
        if ($op === 'dispense') {
            StockService::dispensePrescription($tid, (int)input('id', 0), $uid);
            flash('success', 'Prescription marked as dispensed — recorded against your name.');
            redirect('pharmacist/prescriptions.php?id=' . (int)input('id', 0));
        }
        if ($op === 'cancel') {
            $stmt = $pdo->prepare('UPDATE prescriptions SET status = "cancelled", updated_at = NOW() WHERE id = ? AND tenant_id = ? AND status IN ("pending","partially_dispensed")');
            $stmt->execute([(int)input('id', 0), $tid]);
            flash('success', 'Prescription cancelled.');
            redirect('pharmacist/prescriptions.php');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $ex->getMessage());
        redirect('pharmacist/prescriptions.php');
    }
}

$customers = $pdo->prepare('SELECT id, name FROM customers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$customers->execute([$tid]);
$customers = $customers->fetchAll();
$medOptions = $pdo->prepare('SELECT id, name, strength FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$medOptions->execute([$tid]);
$medOptions = $medOptions->fetchAll();

$detail = null;
$detailItems = [];
$detailSales = [];
if ((int)input('id', 0) > 0) {
    $stmt = $pdo->prepare(
        'SELECT p.*, c.name AS customer_name, u.name AS dispensed_by_name FROM prescriptions p
           LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN users u ON u.id = p.dispensed_by
          WHERE p.id = ? AND p.tenant_id = ? AND p.deleted_at IS NULL'
    );
    $stmt->execute([(int)input('id', 0), $tid]);
    $detail = $stmt->fetch() ?: null;
    if ($detail) {
        $it = $pdo->prepare('SELECT * FROM prescription_items WHERE prescription_id = ? ORDER BY id');
        $it->execute([(int)$detail['id']]);
        $detailItems = $it->fetchAll();
        $sl = $pdo->prepare('SELECT id, invoice_number, grand_total, created_at FROM sales WHERE prescription_id = ? AND tenant_id = ? ORDER BY id DESC');
        $sl->execute([(int)$detail['id'], $tid]);
        $detailSales = $sl->fetchAll();
    }
}

$status = (string)input('status', '');
$q = trim((string)input('q', ''));
$where = 'p.tenant_id = ? AND p.deleted_at IS NULL';
$params = [$tid];
if (in_array($status, ['pending', 'dispensed', 'partially_dispensed', 'cancelled'], true)) {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where .= ' AND (p.prescription_number LIKE ? OR p.patient_name LIKE ? OR p.doctor_name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$list = $pdo->prepare(
    "SELECT p.*, c.name AS customer_name, (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = p.id) AS line_count
       FROM prescriptions p LEFT JOIN customers c ON c.id = p.customer_id
      WHERE {$where} ORDER BY p.id DESC LIMIT 50"
);
$list->execute($params);
$list = $list->fetchAll();

admin_header('Prescriptions', 'pharmacist/prescriptions.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-file-earmark-medical text-emerald"></i> Prescriptions</h4>
  <button class="btn btn-emerald btn-sm" data-bs-toggle="collapse" data-bs-target="#newRx"><i class="bi bi-plus-lg"></i> New prescription</button>
</div>

<div class="collapse mb-3" id="newRx"><div class="card shadow-sm"><div class="card-body">
  <h6 class="mb-3">Record a prescription</h6>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="_op" value="create">
    <div class="row g-2">
      <div class="col-md-3"><label class="form-label small">Rx number (blank = auto)</label><input class="form-control form-control-sm" name="prescription_number" placeholder="RX-1003"></div>
      <div class="col-md-3"><label class="form-label small">Customer</label>
        <select class="form-select form-select-sm" name="customer_id"><option value="">Walk-in / not registered</option>
        <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label small">Patient name</label><input class="form-control form-control-sm" name="patient_name"></div>
      <div class="col-md-3"><label class="form-label small">Date</label><input type="date" class="form-control form-control-sm" name="prescribed_at" value="<?= e(date('Y-m-d')) ?>"></div>
      <div class="col-md-4"><label class="form-label small">Doctor *</label><input class="form-control form-control-sm" name="doctor_name" required></div>
      <div class="col-md-4"><label class="form-label small">Hospital / clinic</label><input class="form-control form-control-sm" name="hospital"></div>
      <div class="col-md-4"><label class="form-label small">Notes</label><input class="form-control form-control-sm" name="notes"></div>
    </div>
    <h6 class="mt-3 mb-2 small text-muted">LINE ITEMS</h6>
    <div id="rxItems"></div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="rxAddItem"><i class="bi bi-plus-lg"></i> Add line</button>
    <div class="mt-3"><button class="btn btn-emerald" type="submit"><i class="bi bi-check-lg"></i> Save prescription</button></div>
  </form>
</div></div></div>

<?php if ($detail): ?>
<div class="card shadow-sm mb-3 border-success"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between gap-2">
    <div>
      <h5 class="mb-1"><?= e($detail['prescription_number']) ?> <?= status_badge($detail['status']) ?></h5>
      <p class="text-muted small mb-1">Patient: <strong><?= e($detail['patient_name'] ?? $detail['customer_name'] ?? '—') ?></strong> · Doctor: <?= e($detail['doctor_name']) ?> · <?= e($detail['hospital'] ?? '') ?> · <?= e(fmt_date($detail['prescribed_at'])) ?></p>
      <?php if ($detail['status'] === 'dispensed'): ?>
        <p class="small mb-0"><i class="bi bi-clipboard-check text-success"></i> Dispensed by <strong><?= e($detail['dispensed_by_name'] ?? '') ?></strong> on <?= e(fmt_date($detail['dispensed_at'], 'd M Y H:i')) ?></p>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-start">
      <a class="btn btn-outline-primary btn-sm" href="<?= url('cashier/pos.php') ?>"><i class="bi bi-upc-scan"></i> Sell at POS</a>
      <?php if (in_array($detail['status'], ['pending', 'partially_dispensed'], true)): ?>
      <form method="post" data-confirm="Mark this prescription as dispensed under your name?"><?= csrf_field() ?><input type="hidden" name="_op" value="dispense"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>"><button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-clipboard-check"></i> Dispense</button></form>
      <form method="post" data-confirm="Cancel this prescription?"><?= csrf_field() ?><input type="hidden" name="_op" value="cancel"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Cancel</button></form>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Medicine</th><th>Dosage</th><th>Qty</th><th>Instructions</th></tr></thead>
    <tbody>
    <?php if (!$detailItems): ?><tr><td colspan="4" class="text-muted text-center py-3">No line items recorded.</td></tr><?php endif; ?>
    <?php foreach ($detailItems as $it): ?><tr><td><?= e($it['medicine_name']) ?></td><td><?= e($it['dosage'] ?? '') ?></td><td><?= (int)$it['quantity'] ?></td><td class="small text-muted"><?= e($it['instructions'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($detailSales): ?>
    <p class="small mt-2 mb-0">Linked sales: <?php foreach ($detailSales as $s): ?><a href="<?= url('admin/receipt.php?id=' . (int)$s['id']) ?>"><?= e($s['invoice_number']) ?></a> (<?= money((float)$s['grand_total']) ?>) <?php endforeach; ?></p>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-4"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search number, patient, doctor…"></div>
  <div class="col-md-3"><select class="form-select form-select-sm" name="status">
    <option value="">All statuses</option>
    <?php foreach (['pending', 'partially_dispensed', 'dispensed', 'cancelled'] as $st): ?><option value="<?= $st ?>"<?= $status === $st ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $st))) ?></option><?php endforeach; ?>
  </select></div>
  <div class="col-auto"><button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
</form>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Rx #</th><th>Patient</th><th>Doctor</th><th>Items</th><th>Date</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4">No prescriptions found.</td></tr><?php endif; ?>
  <?php foreach ($list as $rx): ?>
    <tr>
      <td><strong><?= e($rx['prescription_number']) ?></strong></td>
      <td><?= e($rx['patient_name'] ?? $rx['customer_name'] ?? '—') ?></td>
      <td><?= e($rx['doctor_name']) ?></td>
      <td><?= (int)$rx['line_count'] ?></td>
      <td class="small"><?= e(fmt_date($rx['prescribed_at'])) ?></td>
      <td><?= status_badge($rx['status']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?id=<?= (int)$rx['id'] ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<script>
(function () {
  var meds = <?= json_encode(array_map(static fn($m) => ['id' => (int)$m['id'], 'label' => $m['name'] . ($m['strength'] ? ' ' . $m['strength'] : '')], $medOptions)) ?>;
  var wrap = document.getElementById('rxItems');
  function addRow() {
    var row = document.createElement('div');
    row.className = 'row g-2 mb-2';
    var opts = '<option value="">Free text</option>' + meds.map(function (m) { return '<option value="' + m.id + '">' + m.label.replace(/</g, '&lt;') + '</option>'; }).join('');
    row.innerHTML = '<div class="col-md-3"><select class="form-select form-select-sm" name="item_medicine_id[]">' + opts + '</select></div>'
      + '<div class="col-md-3"><input class="form-control form-control-sm" name="item_name[]" placeholder="Medicine name *" required></div>'
      + '<div class="col-md-2"><input class="form-control form-control-sm" name="item_dosage[]" placeholder="Dosage"></div>'
      + '<div class="col-md-1"><input type="number" min="1" class="form-control form-control-sm" name="item_qty[]" value="1"></div>'
      + '<div class="col-md-2"><input class="form-control form-control-sm" name="item_instructions[]" placeholder="Instructions"></div>'
      + '<div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-del><i class="bi bi-x"></i></button></div>';
    var sel = row.querySelector('select');
    sel.addEventListener('change', function () {
      var nameInput = row.querySelector('[name="item_name[]"]');
      if (sel.value) { var m = meds.find(function (x) { return x.id === Number(sel.value); }); if (m) nameInput.value = m.label; }
    });
    row.querySelector('[data-del]').addEventListener('click', function () { row.remove(); });
    wrap.appendChild(row);
  }
  document.getElementById('rxAddItem').addEventListener('click', addRow);
  addRow();
})();
</script>
<?php admin_footer(); ?>
