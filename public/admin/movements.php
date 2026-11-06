<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
$tid = tenant_id();
$pdo = db();

$type = (string)input('type', '');
$medicineId = (int)input('medicine_id', 0);
$sql = 'SELECT sm.*, m.name AS medicine, b.batch_number, u.name AS actor
          FROM stock_movements sm
          JOIN medicines m ON m.id = sm.medicine_id
          LEFT JOIN batches b ON b.id = sm.batch_id
          LEFT JOIN users u ON u.id = sm.created_by
         WHERE sm.tenant_id = ?';
$params = [$tid];
if (in_array($type, ['opening', 'purchase', 'sale', 'return', 'adjustment'], true)) {
    $sql .= ' AND sm.type = ?';
    $params[] = $type;
}
if ($medicineId > 0) {
    $sql .= ' AND sm.medicine_id = ?';
    $params[] = $medicineId;
}
$sql .= ' ORDER BY sm.id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$meds = $pdo->prepare('SELECT id, name FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name');
$meds->execute([$tid]);
$medList = $meds->fetchAll();

admin_header('Stock Movement History', 'admin/movements.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-arrow-left-right text-emerald"></i> Stock Movement History</h4>
  <form class="d-flex gap-1" method="get">
    <select class="form-select form-select-sm" name="type" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach (['opening', 'purchase', 'sale', 'return', 'adjustment'] as $t): ?><option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm" name="medicine_id" onchange="this.form.submit()">
      <option value="0">All medicines</option>
      <?php foreach ($medList as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $medicineId === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>When</th><th>Type</th><th>Medicine</th><th>Batch</th><th class="text-end">Change</th><th class="text-end">Balance</th><th>Reference</th><th>Note</th><th>By</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= fmt_date($r['created_at'], 'd M Y H:i') ?></td>
      <td><span class="badge bg-<?= ['purchase' => 'success', 'sale' => 'primary', 'return' => 'info', 'adjustment' => 'warning', 'opening' => 'secondary'][$r['type']] ?? 'secondary' ?>"><?= e(ucfirst($r['type'])) ?></span></td>
      <td><?= e($r['medicine']) ?></td>
      <td><?= e($r['batch_number'] ?? '—') ?></td>
      <td class="text-end"><strong class="<?= (int)$r['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int)$r['quantity'] > 0 ? '+' : '' ?><?= (int)$r['quantity'] ?></strong></td>
      <td class="text-end"><?= (int)$r['balance_after'] ?></td>
      <td class="small"><?= e($r['reference_type'] ?? '') ?><?= $r['reference_id'] ? ' #' . (int)$r['reference_id'] : '' ?></td>
      <td class="small"><?= e($r['note'] ?? '') ?></td>
      <td class="small"><?= e($r['actor'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No movements recorded.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<p class="form-text mt-2">This ledger is append-only in normal operation: every sale, purchase, return and adjustment writes here inside its database transaction.</p>
<?php admin_footer(); ?>
