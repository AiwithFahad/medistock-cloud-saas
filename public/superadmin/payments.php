<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

if (is_post()) {
    csrf_check();
    $id = (int)input('payment_id', 0);
    $op = (string)input('_op', '');
    if ($id > 0 && $op === 'verify') {
        // Verified confirmation by the platform administrator: this is the
        // ONLY manual path that can turn a pending payment into "verified".
        SubscriptionService::verifyPayment($id, 'super_admin:' . (current_user()['email'] ?? ''));
        flash('success', 'Payment verified and the subscription has been activated.');
        redirect('superadmin/payments.php');
    }
    if ($id > 0 && $op === 'fail') {
        $pdo->prepare("UPDATE payments SET status = 'failed', updated_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$id]);
        flash('warning', 'Payment marked as failed.');
        redirect('superadmin/payments.php');
    }
}

$status = (string)input('status', '');
$sql = 'SELECT p.*, t.name AS tenant_name, s.plan_id FROM payments p
          LEFT JOIN tenants t ON t.id = p.tenant_id
          LEFT JOIN subscriptions s ON s.id = p.subscription_id WHERE 1=1';
$params = [];
if (in_array($status, ['pending', 'verified', 'failed', 'refunded'], true)) {
    $sql .= ' AND p.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY p.id DESC LIMIT 150';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

admin_header('Payments', 'superadmin/payments.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-credit-card text-emerald"></i> Payments</h4>
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary <?= $status === '' ? 'active' : '' ?>" href="payments.php">All</a>
    <?php foreach (['pending', 'verified', 'failed', 'refunded'] as $s): ?>
      <a class="btn btn-outline-secondary <?= $status === $s ? 'active' : '' ?>" href="payments.php?status=<?= $s ?>"><?= ucfirst($s) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> Manual payments are recorded as <strong>pending</strong>. They become <strong>verified</strong> only after confirmed receipt (gateway confirmation or this verification action) — never automatically.</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>#</th><th>Pharmacy</th><th>Reference</th><th>Method</th><th>Amount</th><th>Status</th><th>Verified by</th><th>Date</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
    <tr>
      <td><?= (int)$p['id'] ?></td>
      <td><?= e($p['tenant_name'] ?? '—') ?></td>
      <td><code><?= e($p['reference'] ?? '—') ?></code></td>
      <td><?= e(ucfirst($p['method'])) ?></td>
      <td><strong><?= money($p['amount']) ?></strong></td>
      <td><?= status_badge($p['status']) ?></td>
      <td class="small"><?= e($p['verified_by'] ?? '—') ?></td>
      <td><?= fmt_date($p['created_at']) ?></td>
      <td class="text-end text-nowrap">
        <?php if ($p['status'] === 'pending'): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Verify this payment? This activates the subscription.')"><?= csrf_field() ?><input type="hidden" name="_op" value="verify"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-emerald"><i class="bi bi-patch-check"></i> Verify</button></form>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="fail"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-danger">Fail</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$payments): ?><tr><td colspan="9" class="text-center text-muted py-4">No payments found.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php admin_footer(); ?>
