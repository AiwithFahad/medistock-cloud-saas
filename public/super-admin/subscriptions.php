<?php
/**
 * MediStock Cloud — Super Admin: subscriptions across all pharmacies.
 * Lifecycle actions: extend (+30 days and reactivate), cancel, expire.
 * Plan changes with payment still go through the verified-payment flow —
 * nothing here fabricates a paid state.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

if (is_post()) {
    csrf_check();
    $id = (int)input('subscription_id', 0);
    $op = (string)input('_op', '');
    if ($id > 0) {
        if ($op === 'extend') {
            $pdo->prepare('UPDATE subscriptions SET status = "active", ends_at = DATE_ADD(GREATEST(ends_at, NOW()), INTERVAL 30 DAY), updated_at = NOW() WHERE id = ?')->execute([$id]);
            audit_log('subscriptions', $id, 'extended');
            flash('success', 'Subscription extended by 30 days and set active.');
        } elseif ($op === 'cancel') {
            $pdo->prepare('UPDATE subscriptions SET status = "cancelled", cancelled_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([$id]);
            audit_log('subscriptions', $id, 'cancelled');
            flash('success', 'Subscription cancelled.');
        } elseif ($op === 'expire') {
            $pdo->prepare('UPDATE subscriptions SET status = "expired", updated_at = NOW() WHERE id = ?')->execute([$id]);
            audit_log('subscriptions', $id, 'expired');
            flash('success', 'Subscription marked expired.');
        }
        redirect('super-admin/subscriptions.php');
    }
}

$status = (string)input('status', '');
$sql = 'SELECT s.*, t.name AS tenant_name, p.name AS plan_name FROM subscriptions s
          JOIN tenants t ON t.id = s.tenant_id JOIN subscription_plans p ON p.id = s.plan_id WHERE 1=1';
$params = [];
if (in_array($status, ['trial', 'pending', 'active', 'grace', 'expired', 'cancelled'], true)) {
    $sql .= ' AND s.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY s.id DESC LIMIT 150';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subs = $stmt->fetchAll();

admin_header('Subscriptions', 'super-admin/subscriptions.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-arrow-repeat text-emerald"></i> Subscriptions <span class="badge bg-light text-dark"><?= count($subs) ?></span></h4>
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-secondary <?= $status === '' ? 'active' : '' ?>" href="subscriptions.php">All</a>
    <?php foreach (['trial', 'active', 'grace', 'expired', 'cancelled'] as $s): ?>
      <a class="btn btn-outline-secondary <?= $status === $s ? 'active' : '' ?>" href="subscriptions.php?status=<?= $s ?>"><?= ucfirst($s) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Pharmacy</th><th>Plan</th><th>Cycle</th><th>Started</th><th>Ends</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php if (!$subs): ?><tr><td colspan="7" class="text-center text-muted py-4">No subscriptions found.</td></tr><?php endif; ?>
  <?php foreach ($subs as $s): ?>
    <tr>
      <td><strong><?= e($s['tenant_name']) ?></strong></td>
      <td><?= e($s['plan_name']) ?></td>
      <td class="small"><?= e(ucfirst($s['billing_cycle'])) ?></td>
      <td class="small"><?= $s['starts_at'] ? e(fmt_date($s['starts_at'])) : '—' ?></td>
      <td class="small"><?= $s['ends_at'] ? e(fmt_date($s['ends_at'])) : '—' ?></td>
      <td><?= status_badge($s['status']) ?></td>
      <td class="text-end text-nowrap">
        <?php if (in_array($s['status'], ['trial', 'active', 'grace', 'expired'], true)): ?>
          <form method="post" class="d-inline" data-confirm="Extend this subscription by 30 days and set it active?"><?= csrf_field() ?><input type="hidden" name="_op" value="extend"><input type="hidden" name="subscription_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-outline-success"><i class="bi bi-calendar-plus"></i> +30 days</button></form>
        <?php endif; ?>
        <?php if (in_array($s['status'], ['trial', 'active', 'grace', 'pending'], true)): ?>
          <form method="post" class="d-inline" data-confirm="Cancel this subscription?"><?= csrf_field() ?><input type="hidden" name="_op" value="cancel"><input type="hidden" name="subscription_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancel</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php admin_footer(); ?>
