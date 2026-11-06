<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

if (is_post()) {
    csrf_check();
    $id = (int)input('tenant_id', 0);
    $op = (string)input('_op', '');
    if ($id > 0 && in_array($op, ['suspend', 'reactivate'], true)) {
        $status = $op === 'suspend' ? 'suspended' : 'active';
        $pdo->prepare('UPDATE tenants SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $id]);
        audit_log('tenants', $id, $op);
        activity_log('tenant.' . $op, 'Tenant #' . $id . ' ' . $status, $id);
        flash('success', 'Pharmacy ' . $status . '.');
        redirect('superadmin/tenants.php');
    }
}

$q = trim((string)input('q', ''));
$sql = 'SELECT t.*,
          (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS user_count,
          (SELECT p.name FROM subscription_plans p JOIN subscriptions s ON s.plan_id = p.id WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan_name,
          (SELECT s.status FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS sub_status
        FROM tenants t WHERE t.deleted_at IS NULL';
$params = [];
if ($q !== '') {
    $sql .= ' AND (t.name LIKE ? OR t.email LIKE ? OR t.slug LIKE ?)';
    $params = ["%$q%", "%$q%", "%$q%"];
}
$sql .= ' ORDER BY t.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll();

admin_header('Pharmacies (Tenants)', 'superadmin/tenants.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-building text-emerald"></i> Pharmacies (Tenants) <span class="badge bg-light text-dark"><?= count($tenants) ?></span></h4>
  <form class="d-flex" method="get"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search pharmacies…"><button class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-search"></i></button></form>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Pharmacy</th><th>License</th><th>Location</th><th>Users</th><th>Plan</th><th>Subscription</th><th>Status</th><th>Trial ends</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($tenants as $t): ?>
    <tr>
      <td><strong><?= e($t['name']) ?></strong><br><small class="text-muted"><?= e($t['email']) ?> · /<?= e($t['slug']) ?></small></td>
      <td><?= e($t['license_number'] ?? '—') ?></td>
      <td><?= e(trim(($t['city'] ?? '') . ', ' . ($t['country'] ?? ''), ', ')) ?: '—' ?></td>
      <td><?= (int)$t['user_count'] ?></td>
      <td><?= e($t['plan_name'] ?? '—') ?></td>
      <td><?= status_badge($t['sub_status'] ?? null) ?></td>
      <td><?= status_badge($t['status']) ?></td>
      <td><?= fmt_date($t['trial_ends_at']) ?></td>
      <td class="text-end">
        <?php if ($t['status'] === 'active'): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Suspend this pharmacy? Its users will be blocked from signing in.')">
            <?= csrf_field() ?><input type="hidden" name="_op" value="suspend"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-pause-circle"></i> Suspend</button></form>
        <?php else: ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="_op" value="reactivate"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn-sm btn-outline-success"><i class="bi bi-play-circle"></i> Reactivate</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$tenants): ?><tr><td colspan="9" class="text-center text-muted py-4">No pharmacies found.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php admin_footer(); ?>
