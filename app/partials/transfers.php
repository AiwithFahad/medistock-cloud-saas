<?php
/**
 * MediStock Cloud — shared Branch Transfers page body.
 * Included by public/admin/transfers.php and public/inventory/transfers.php
 * after their own guards. Expects: $tid, $user, $base (form/link target,
 * e.g. 'transfers.php'), $activePath (menu highlight).
 *
 * Flow: create (pending, no stock moves) → complete (one transaction:
 * FEFO out of source, find-or-create batch at destination, two transfer
 * movements per allocation) → or cancel (nothing to undo).
 */
$pdo = db();
$uid = (int)$user['id'];

if (is_post()) {
    csrf_check();
    $op = (string)input('_op', '');
    try {
        if ($op === 'create') {
            $items = [];
            foreach ((array)($_POST['medicine_id'] ?? []) as $i => $mid) {
                $qty = (int)($_POST['quantity'][$i] ?? 0);
                if ((int)$mid > 0 && $qty > 0) {
                    $items[] = ['medicine_id' => (int)$mid, 'quantity' => $qty];
                }
            }
            $id = StockService::createTransfer($tid, (int)input('from_branch_id', 0), (int)input('to_branch_id', 0), $items, trim((string)input('notes', '')) ?: null, $uid);
            flash('success', 'Transfer requested. Stock moves when the transfer is completed.');
            redirect($base . '?id=' . $id);
        }
        if ($op === 'complete') {
            StockService::completeTransfer($tid, (int)input('id', 0), $uid);
            flash('success', 'Transfer completed — stock moved and movements recorded.');
            redirect($base);
        }
        if ($op === 'cancel') {
            StockService::cancelTransfer($tid, (int)input('id', 0), $uid);
            flash('success', 'Transfer cancelled.');
            redirect($base);
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect($base);
    }
}

$branches = $pdo->prepare('SELECT id, name, code FROM branches WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY is_main DESC, name');
$branches->execute([$tid]);
$branches = $branches->fetchAll();
$meds = $pdo->prepare('SELECT id, name, strength FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL AND status = "active" ORDER BY name');
$meds->execute([$tid]);
$meds = $meds->fetchAll();

$detail = null;
$detailItems = [];
if ((int)input('id', 0) > 0) {
    $stmt = $pdo->prepare(
        'SELECT t.*, fb.name AS from_name, tb.name AS to_name, ru.name AS requested_by_name, cu.name AS completed_by_name
           FROM stock_transfers t
           JOIN branches fb ON fb.id = t.from_branch_id JOIN branches tb ON tb.id = t.to_branch_id
           LEFT JOIN users ru ON ru.id = t.requested_by LEFT JOIN users cu ON cu.id = t.completed_by
          WHERE t.id = ? AND t.tenant_id = ?'
    );
    $stmt->execute([(int)input('id', 0), $tid]);
    $detail = $stmt->fetch() ?: null;
    if ($detail) {
        $it = $pdo->prepare('SELECT ti.*, m.name, m.strength, b.batch_number FROM stock_transfer_items ti JOIN medicines m ON m.id = ti.medicine_id LEFT JOIN batches b ON b.id = ti.batch_id WHERE ti.transfer_id = ?');
        $it->execute([(int)$detail['id']]);
        $detailItems = $it->fetchAll();
    }
}

$status = (string)input('status', '');
$where = 't.tenant_id = ?';
$params = [$tid];
if (in_array($status, ['pending', 'in_transit', 'completed', 'cancelled'], true)) {
    $where .= ' AND t.status = ?';
    $params[] = $status;
}
$list = $pdo->prepare(
    "SELECT t.*, fb.name AS from_name, tb.name AS to_name,
            (SELECT COUNT(*) FROM stock_transfer_items ti WHERE ti.transfer_id = t.id) AS line_count,
            (SELECT COALESCE(SUM(ti2.quantity), 0) FROM stock_transfer_items ti2 WHERE ti2.transfer_id = t.id) AS units
       FROM stock_transfers t JOIN branches fb ON fb.id = t.from_branch_id JOIN branches tb ON tb.id = t.to_branch_id
      WHERE {$where} ORDER BY t.id DESC LIMIT 50"
);
$list->execute($params);
$list = $list->fetchAll();

admin_header('Branch Transfers', $activePath);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-arrow-left-right text-emerald"></i> Branch Transfers</h4>
  <button class="btn btn-emerald btn-sm" data-bs-toggle="collapse" data-bs-target="#newTransfer"><i class="bi bi-plus-lg"></i> New transfer</button>
</div>

<div class="collapse mb-3" id="newTransfer"><div class="card shadow-sm"><div class="card-body">
  <h6 class="mb-3">Request a transfer (stock moves on completion, FEFO, expired batches never move)</h6>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="_op" value="create">
    <div class="row g-2 mb-2">
      <div class="col-md-4"><label class="form-label small">From branch</label>
        <select class="form-select form-select-sm" name="from_branch_id" required>
          <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?> (<?= e($b['code']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label small">To branch</label>
        <select class="form-select form-select-sm" name="to_branch_id" required>
          <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?> (<?= e($b['code']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label small">Notes</label><input class="form-control form-control-sm" name="notes" placeholder="Reason for the transfer"></div>
    </div>
    <div id="trItems"></div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="trAdd"><i class="bi bi-plus-lg"></i> Add medicine</button>
    <button class="btn btn-emerald btn-sm ms-2" type="submit"><i class="bi bi-check-lg"></i> Create transfer</button>
  </form>
</div></div></div>

<?php if ($detail): ?>
<div class="card shadow-sm mb-3 border-success"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between gap-2">
    <div>
      <h5 class="mb-1"><?= e($detail['transfer_number']) ?> <?= status_badge($detail['status']) ?></h5>
      <p class="text-muted small mb-0"><?= e($detail['from_name']) ?> <i class="bi bi-arrow-right"></i> <?= e($detail['to_name']) ?> · requested by <?= e($detail['requested_by_name'] ?? '') ?> · <?= e(fmt_date($detail['created_at'], 'd M Y H:i')) ?>
      <?php if ($detail['status'] === 'completed'): ?> · completed by <?= e($detail['completed_by_name'] ?? '') ?> on <?= e(fmt_date($detail['completed_at'], 'd M Y H:i')) ?><?php endif; ?></p>
      <?php if ($detail['notes']): ?><p class="small mb-0 mt-1"><?= e($detail['notes']) ?></p><?php endif; ?>
    </div>
    <?php if (in_array($detail['status'], ['pending', 'in_transit'], true) && can('stock.adjust')): ?>
    <div class="d-flex gap-2 align-items-start">
      <form method="post" data-confirm="Complete this transfer now? Stock will move between the branches."><?= csrf_field() ?><input type="hidden" name="_op" value="complete"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>"><button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-check2-circle"></i> Complete transfer</button></form>
      <form method="post" data-confirm="Cancel this transfer request?"><?= csrf_field() ?><input type="hidden" name="_op" value="cancel"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Cancel</button></form>
    </div>
    <?php endif; ?>
  </div>
  <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Medicine</th><th>Source batch (FEFO)</th><th class="text-end">Units</th></tr></thead>
    <tbody>
    <?php foreach ($detailItems as $it): ?><tr><td><?= e($it['name']) ?> <?= e($it['strength'] ?? '') ?></td><td><?= e($it['batch_number'] ?? '— chosen at completion') ?></td><td class="text-end"><?= (int)$it['quantity'] ?></td></tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (['pending', 'in_transit', 'completed', 'cancelled'] as $st): ?><option value="<?= $st ?>"<?= $status === $st ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $st))) ?></option><?php endforeach; ?>
  </select></div>
</form>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Transfer #</th><th>Route</th><th>Lines</th><th>Units</th><th>Created</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4">No transfers yet.</td></tr><?php endif; ?>
  <?php foreach ($list as $t): ?>
    <tr>
      <td><strong><?= e($t['transfer_number']) ?></strong></td>
      <td class="small"><?= e($t['from_name']) ?> <i class="bi bi-arrow-right"></i> <?= e($t['to_name']) ?></td>
      <td><?= (int)$t['line_count'] ?></td><td><?= (int)$t['units'] ?></td>
      <td class="small"><?= e(fmt_date($t['created_at'], 'd M Y')) ?></td>
      <td><?= status_badge($t['status']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url($base) ?>?id=<?= (int)$t['id'] ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<script>
(function () {
  var meds = <?= json_encode(array_map(static fn($m) => ['id' => (int)$m['id'], 'label' => $m['name'] . ($m['strength'] ? ' ' . $m['strength'] : '')], $meds)) ?>;
  var wrap = document.getElementById('trItems');
  function addRow() {
    var row = document.createElement('div');
    row.className = 'row g-2 mb-2';
    row.innerHTML = '<div class="col-md-7"><select class="form-select form-select-sm" name="medicine_id[]" required>'
      + meds.map(function (m) { return '<option value="' + m.id + '">' + m.label.replace(/</g, '&lt;') + '</option>'; }).join('') + '</select></div>'
      + '<div class="col-md-3"><input type="number" min="1" class="form-control form-control-sm" name="quantity[]" value="10" required></div>'
      + '<div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-del><i class="bi bi-x"></i> Remove</button></div>';
    row.querySelector('[data-del]').addEventListener('click', function () { row.remove(); });
    wrap.appendChild(row);
  }
  document.getElementById('trAdd').addEventListener('click', addRow);
  addRow();
})();
</script>
<?php admin_footer(); ?>
