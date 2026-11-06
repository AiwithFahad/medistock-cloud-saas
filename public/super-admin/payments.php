<?php
/**
 * MediStock Cloud — Super Admin: subscription payments.
 * Manual payments are recorded as PENDING and become VERIFIED only after
 * confirmed receipt — via SubscriptionService::verifyPayment() here, or a
 * gateway webhook. Nothing is ever auto-verified. Verified payments can
 * be refunded (status change only; the money movement happens at the
 * payment provider). Summary figures count verified payments only.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

if (is_post()) {
    csrf_check();
    $id = (int)input('payment_id', 0);
    $op = (string)input('_op', '');
    if ($id > 0 && $op === 'verify') {
        SubscriptionService::verifyPayment($id, 'super_admin:' . (current_user()['email'] ?? ''));
        flash('success', 'Payment verified and the subscription has been activated.');
        redirect('super-admin/payments.php');
    }
    if ($id > 0 && $op === 'fail') {
        $pdo->prepare("UPDATE payments SET status = 'failed', updated_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$id]);
        flash('warning', 'Payment marked as failed.');
        redirect('super-admin/payments.php');
    }
    if ($id > 0 && $op === 'refund') {
        $pdo->prepare("UPDATE payments SET status = 'refunded', updated_at = NOW() WHERE id = ? AND status = 'verified'")->execute([$id]);
        audit_log('payments', $id, 'refunded');
        flash('warning', 'Payment marked as refunded. Process the actual refund at your payment provider.');
        redirect('super-admin/payments.php');
    }
}

$status = (string)input('status', '');
$sql = 'SELECT p.*, t.name AS tenant_name, pl.name AS plan_name FROM payments p
          LEFT JOIN tenants t ON t.id = p.tenant_id
          LEFT JOIN subscriptions s ON s.id = p.subscription_id
          LEFT JOIN subscription_plans pl ON pl.id = s.plan_id WHERE 1=1';
$params = [];
if (in_array($status, ['pending', 'verified', 'failed', 'refunded'], true)) {
    $sql .= ' AND p.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY p.id DESC LIMIT 150';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$verifiedTotal = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "verified"')->fetchColumn();
$pendingTotal = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "pending"')->fetchColumn();
$refundedTotal = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "refunded"')->fetchColumn();

admin_header('Payments', 'super-admin/payments.php');
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
<div class="row g-3 mb-1">
  <?php stat_card('bi-check2-circle', 'Verified revenue', money($verifiedTotal), 'emerald', 'confirmed receipts only'); ?>
  <?php stat_card('bi-hourglass-split', 'Pending', money($pendingTotal), 'amber', 'awaiting verification'); ?>
  <?php stat_card('bi-arrow-counterclockwise', 'Refunded', money($refundedTotal), 'rose'); ?>
</div>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> Manual payments stay <strong>pending</strong> until confirmed receipt. Verifying a payment activates its subscription; nothing is ever verified automatically.</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>#</th><th>Pharmacy</th><th>Plan</th><th>Method</th><th>Reference</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php if (!$payments): ?><tr><td colspan="9" class="text-center text-muted py-4">No payments found.</td></tr><?php endif; ?>
  <?php foreach ($payments as $p): ?>
    <tr>
      <td><?= (int)$p['id'] ?></td>
      <td><?= e($p['tenant_name'] ?? '—') ?></td>
      <td class="small"><?= e($p['plan_name'] ?? '—') ?></td>
      <td><?= e(ucfirst($p['method'])) ?></td>
      <td class="small text-muted"><?= e($p['reference'] ?? '') ?></td>
      <td class="small"><?= e(fmt_date($p['created_at'], 'd M Y H:i')) ?></td>
      <td><?= status_badge($p['status']) ?><?php if ($p['status'] === 'verified' && $p['verified_by']): ?><br><small class="text-muted"><?= e($p['verified_by']) ?></small><?php endif; ?></td>
      <td class="text-end"><strong><?= money((float)$p['amount']) ?></strong></td>
      <td class="text-end text-nowrap">
        <?php if ($p['status'] === 'pending'): ?>
          <form method="post" class="d-inline" data-confirm="Confirm you have RECEIVED this payment. Only verify after confirmed receipt — this activates the subscription."><?= csrf_field() ?><input type="hidden" name="_op" value="verify"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-emerald"><i class="bi bi-check-lg"></i> Verify</button></form>
          <form method="post" class="d-inline" data-confirm="Mark this payment as failed?"><?= csrf_field() ?><input type="hidden" name="_op" value="fail"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button></form>
        <?php elseif ($p['status'] === 'verified'): ?>
          <form method="post" class="d-inline" data-confirm="Mark this payment as refunded? Process the money refund at your payment provider as well."><?= csrf_field() ?><input type="hidden" name="_op" value="refund"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-counterclockwise"></i> Refund</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php admin_footer(); ?>
