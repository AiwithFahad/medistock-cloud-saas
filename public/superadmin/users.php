<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

if (is_post()) {
    csrf_check();
    $id = (int)input('user_id', 0);
    $op = (string)input('_op', '');
    if ($id > 0 && in_array($op, ['activate', 'deactivate'], true)) {
        $status = $op === 'activate' ? 'active' : 'inactive';
        $pdo->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $id]);
        audit_log('users', $id, $op);
        flash('success', 'User ' . $status . '.');
        redirect('superadmin/users.php');
    }
}

$q = trim((string)input('q', ''));
$sql = 'SELECT u.*, r.name AS role_name, r.key AS role_key, t.name AS tenant_name FROM users u
          JOIN roles r ON r.id = u.role_id LEFT JOIN tenants t ON t.id = u.tenant_id
         WHERE u.deleted_at IS NULL';
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR t.name LIKE ?)';
    $params = ["%$q%", "%$q%", "%$q%"];
}
$sql .= ' ORDER BY u.id DESC LIMIT 150';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

admin_header('Platform Users', 'superadmin/users.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people text-emerald"></i> Users <span class="badge bg-light text-dark"><?= count($users) ?></span></h4>
  <form class="d-flex" method="get"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search users…"><button class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-search"></i></button></form>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>User</th><th>Role</th><th>Pharmacy</th><th>Status</th><th>Last login</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($users as $row): ?>
    <tr>
      <td><strong><?= e($row['name']) ?></strong><br><small class="text-muted"><?= e($row['email']) ?></small></td>
      <td><?= e($row['role_name']) ?></td>
      <td><?= e($row['tenant_name'] ?? '— Platform —') ?></td>
      <td><?= status_badge($row['status']) ?></td>
      <td><?= $row['last_login_at'] ? fmt_date($row['last_login_at'], 'd M Y H:i') : 'Never' ?></td>
      <td class="text-end">
        <?php if ($row['role_key'] ?? '' !== 'super_admin'): ?>
          <?php if ($row['status'] === 'active'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this user?')"><?= csrf_field() ?><input type="hidden" name="_op" value="deactivate"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger">Deactivate</button></form>
          <?php else: ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="activate"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-success">Activate</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<p class="form-text mt-2">Role keys are joined for display; user rows carry their role via the roles table. Deactivation blocks sign-in immediately.</p>
<?php admin_footer(); ?>
