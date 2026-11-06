<?php
/**
 * MediStock Cloud — Super Admin: all platform users across pharmacies.
 * Search + role/status filters; activate / deactivate accounts (you can
 * never deactivate your own account from here). Actions audit-logged.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$me = require_role(['super_admin']);
$pdo = db();

if (is_post()) {
    csrf_check();
    $id = (int)input('user_id', 0);
    $op = (string)input('_op', '');
    if ($id > 0 && $id !== (int)$me['id'] && in_array($op, ['activate', 'deactivate'], true)) {
        $status = $op === 'activate' ? 'active' : 'inactive';
        $pdo->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $id]);
        audit_log('users', $id, $op);
        flash('success', 'User ' . $status . '.');
        redirect('super-admin/users.php');
    }
}

$q = trim((string)input('q', ''));
$role = (string)input('role', '');
$status = (string)input('status', '');
$sql = 'SELECT u.id, u.name, u.email, u.phone, u.status, u.last_login_at, u.created_at,
               r.name AS role_name, t.name AS tenant_name
          FROM users u JOIN roles r ON r.id = u.role_id
          LEFT JOIN tenants t ON t.id = u.tenant_id
         WHERE u.deleted_at IS NULL';
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
if ($role !== '') {
    $sql .= ' AND r.key = ?';
    $params[] = $role;
}
if (in_array($status, ['active', 'inactive', 'invited'], true)) {
    $sql .= ' AND u.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY u.id DESC LIMIT 150';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
$roles = $pdo->query('SELECT `key`, name FROM roles ORDER BY id')->fetchAll();

admin_header('Users', 'super-admin/users.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people text-emerald"></i> Platform Users <span class="badge bg-light text-dark"><?= count($users) ?></span></h4>
  <form class="d-flex gap-2" method="get">
    <select class="form-select form-select-sm" name="role" onchange="this.form.submit()">
      <option value="">All roles</option>
      <?php foreach ($roles as $r): ?><option value="<?= e($r['key']) ?>"<?= $role === $r['key'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
    </select>
    <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search name or email…">
    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-search"></i></button>
  </form>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>User</th><th>Pharmacy</th><th>Role</th><th>Last login</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><strong><?= e($u['name']) ?></strong><br><small class="text-muted"><?= e($u['email']) ?><?= $u['phone'] ? ' · ' . e($u['phone']) : '' ?></small></td>
      <td class="small"><?= e($u['tenant_name'] ?? 'Platform') ?></td>
      <td><span class="badge bg-light text-dark"><?= e($u['role_name']) ?></span></td>
      <td class="small"><?= $u['last_login_at'] ? e(fmt_date($u['last_login_at'], 'd M Y H:i')) : 'Never' ?></td>
      <td><?= status_badge($u['status']) ?></td>
      <td class="text-end">
        <?php if ((int)$u['id'] === (int)$me['id']): ?><span class="text-muted small">You</span>
        <?php elseif ($u['status'] === 'active'): ?>
          <form method="post" class="d-inline" data-confirm="Deactivate this user? They will no longer be able to sign in."><?= csrf_field() ?><input type="hidden" name="_op" value="deactivate"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-person-dash"></i> Deactivate</button></form>
        <?php else: ?>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="activate"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-sm btn-outline-success"><i class="bi bi-person-check"></i> Activate</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php admin_footer(); ?>
