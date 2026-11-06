<?php
/**
 * MediStock Cloud — Authorized dispensing records.
 * Who dispensed which prescription (dispensed_by / dispensed_at audit
 * fields), plus every sale that was rung up against a prescription.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_role(['pharmacist', 'admin', 'owner']);
$tid = tenant_id();
$pdo = db();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('from', '')) ? input('from') : date('Y-m-d', strtotime('-30 day'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('to', '')) ? input('to') : date('Y-m-d');

$dispensed = $pdo->prepare(
    'SELECT p.prescription_number, p.patient_name, p.doctor_name, p.dispensed_at, u.name AS by_name, u.email AS by_email
       FROM prescriptions p LEFT JOIN users u ON u.id = p.dispensed_by
      WHERE p.tenant_id = ? AND p.status = "dispensed" AND DATE(p.dispensed_at) BETWEEN ? AND ?
      ORDER BY p.dispensed_at DESC LIMIT 100'
);
$dispensed->execute([$tid, $from, $to]);
$dispensed = $dispensed->fetchAll();

$rxSales = $pdo->prepare(
    'SELECT s.id, s.invoice_number, s.grand_total, s.created_at, p.prescription_number, u.name AS sold_by_name
       FROM sales s JOIN prescriptions p ON p.id = s.prescription_id
       LEFT JOIN users u ON u.id = s.sold_by
      WHERE s.tenant_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
      ORDER BY s.id DESC LIMIT 100'
);
$rxSales->execute([$tid, $from, $to]);
$rxSales = $rxSales->fetchAll();

admin_header('Dispensing Records', 'pharmacist/dispensing.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-clipboard-check text-emerald"></i> Dispensing Records</h4>
  <form class="d-flex gap-2" method="get">
    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>">
    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>">
    <button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-funnel"></i></button>
  </form>
</div>

<div class="card shadow-sm mb-3"><div class="card-body">
  <h6 class="mb-3">Prescriptions dispensed (authorized record)</h6>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Rx #</th><th>Patient</th><th>Doctor</th><th>Dispensed by</th><th>Dispensed at</th></tr></thead>
    <tbody>
    <?php if (!$dispensed): ?><tr><td colspan="5" class="text-center text-muted py-3">No dispensing in this range.</td></tr><?php endif; ?>
    <?php foreach ($dispensed as $d): ?>
      <tr><td><strong><?= e($d['prescription_number']) ?></strong></td><td><?= e($d['patient_name'] ?? '—') ?></td><td><?= e($d['doctor_name']) ?></td>
      <td><?= e($d['by_name'] ?? '—') ?> <small class="text-muted"><?= e($d['by_email'] ?? '') ?></small></td>
      <td class="small"><?= e(fmt_date($d['dispensed_at'], 'd M Y H:i')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>

<div class="card shadow-sm"><div class="card-body">
  <h6 class="mb-3">Sales rung up against a prescription</h6>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Invoice</th><th>Rx #</th><th>Sold by</th><th>When</th><th class="text-end">Total</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rxSales): ?><tr><td colspan="6" class="text-center text-muted py-3">No prescription sales in this range.</td></tr><?php endif; ?>
    <?php foreach ($rxSales as $s): ?>
      <tr><td><strong><?= e($s['invoice_number']) ?></strong></td><td><?= e($s['prescription_number']) ?></td><td><?= e($s['sold_by_name'] ?? '') ?></td>
      <td class="small"><?= e(fmt_date($s['created_at'], 'd M Y H:i')) ?></td><td class="text-end"><?= money((float)$s['grand_total']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/receipt.php?id=' . (int)$s['id']) ?>"><i class="bi bi-printer"></i></a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>
<?php admin_footer(); ?>
