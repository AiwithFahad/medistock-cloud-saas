<?php
/**
 * MediStock Cloud — Super Admin: pharmacies (tenants).
 * Registration lifecycle: approve (pending → active), activate,
 * suspend. Suspending blocks every user of the pharmacy at sign-in
 * (enforced in Auth::attempt). All actions are audit-logged.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

if (is_post()) {
    csrf_check();
    $id = (int)input('tenant_id', 0);
    $op = (string)input('_op', '');
    $map = ['approve' => 'active', 'activate' => 'active', 'suspend' => 'suspended'];
    if ($id > 0 && isset($map[$op])) {
        $pdo->prepare('UPDATE tenants SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$map[$op], $id]);
        audit_log('tenants', $id, $op);
        activity_log('tenant.' . $op, 'Tenant #' . $id . ' → ' . $map[$op], $id);
        flash('success', 'Pharmacy updated to "' . $map[$op] . '".');
        redirect('super-admin/pharmacies.php');
    }
}

$q = trim((string)input('q', ''));
$status = (string)input('status', '');
$sql = 'SELECT t.*,
          (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS user_count,
          (SELECT COUNT(*) FROM medicines m WHERE m.tenant_id = t.id AND m.deleted_at IS NULL) AS medicine_count,
          (SELECT p.name FROM subscription_plans p JOIN subscriptions s ON s.plan_id = p.id WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan_name,
          (SELECT s.status FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS sub_status,
          (SELECT s.ends_at FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS sub_ends
        FROM tenants t WHERE t.deleted_at IS NULL';
$params = [];
if ($q !== '') {
    $sql .= ' AND (t.name LIKE ? OR t.email LIKE ? OR t.slug LIKE ?)';
    $params = ["%$q%", "%$q%", "%$q%"];
}
if (in_array($status, ['pending', 'active', 'suspended'], true)) {
    $sql .= ' AND t.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY t.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll();

admin_header('Pharmacies', 'super-admin/pharmacies.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-building text-emerald"></i> Pharmacies <span class="badge bg-light text-dark"><?= count($tenants) ?></span></h4>
  <div class="d-flex gap-2">
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-secondary <?= $status === '' ? 'active' : '' ?>" href="pharmacies.php">All</a>
      <?php foreach (['pending', 'active', 'suspended'] as $s): ?>
        <a class="btn btn-outline-secondary <?= $status === $s ? 'active' : '' ?>" href="pharmacies.php?status=<?= $s ?>"><?= ucfirst($s) ?></a>
      <?php endforeach; ?>
    </div>
    <form class="d-flex" method="get"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search pharmacies…"><button class="btn btn-outline-secondary btn-sm ms-1" type="submit"><i class="bi bi-search"></i></button></form>
  </div>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Pharmacy</th><th>License</th><th>Location</th><th>Usage</th><th>Plan</th><th>Subscription</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php if (!$tenants): ?><tr><td colspan="8" class="text-center text-muted py-4">No pharmacies found.</td></tr><?php endif; ?>
  <?php foreach ($tenants as $t): ?>
    <tr>
      <td><strong><?= e($t['name']) ?></strong><br><small class="text-muted"><?= e($t['email']) ?> · /<?= e($t['slug']) ?> · since <?= e(fmt_date($t['created_at'])) ?></small></td>
      <td class="small"><?= e($t['license_number'] ?? '—') ?></td>
      <td class="small"><?= e(trim(($t['city'] ?? '') . ', ' . ($t['country'] ?? ''), ', ')) ?: '—' ?></td>
      <td class="small"><?= (int)$t['user_count'] ?> staff<br><?= (int)$t['medicine_count'] ?> medicines</td>
      <td><?= e($t['plan_name'] ?? '—') ?></td>
      <td><?= status_badge($t['sub_status'] ?? null) ?><br><small class="text-muted"><?= $t['sub_ends'] ? 'ends ' . e(fmt_date($t['sub_ends'])) : '' ?></small></td>
      <td><?= status_badge($t['status']) ?></td>
      <td class="text-end text-nowrap">
        <?php if ($t['status'] === 'pending'): ?>
          <form method="post" class="d-inline" data-confirm="Approve this pharmacy registration? Its users will be able to sign in."><?= csrf_field() ?><input type="hidden" name="_op" value="approve"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm btn-emerald"><i class="bi bi-patch-check"></i> Approve</button></form>
        <?php endif; ?>
        <?php if ($t['status'] === 'active'): ?>
          <form method="post" class="d-inline" data-confirm="Suspend this pharmacy? Its users will be blocked from signing in."><?= csrf_field() ?><input type="hidden" name="_op" value="suspend"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-pause-circle"></i> Suspend</button></form>
        <?php else: ?>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="activate"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm btn-outline-success"><i class="bi bi-play-circle"></i> Activate</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php admin_footer(); ?>
