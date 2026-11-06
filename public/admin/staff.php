<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('staff.manage');
$tid = tenant_id();
$pdo = db();

$roles = $pdo->query("SELECT id, name, `key` FROM roles WHERE `key` != 'super_admin' ORDER BY id")->fetchAll();

if (is_post()) {
    csrf_check();
    $op = (string)input('_op', '');
    if ($op === 'invite') {
        $err = SubscriptionService::limitError($tid, 'users');
        if ($err) {
            flash('error', $err);
            redirect('admin/staff.php');
        }
        $name = trim((string)input('name', ''));
        $email = strtolower(trim((string)input('email', '')));
        $roleId = (int)input('role_id', 0);
        $errors = validate(['name' => $name, 'email' => $email], ['name' => 'required', 'email' => 'required|email']);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND deleted_at IS NULL');
        $exists->execute([$email]);
        if ((int)$exists->fetchColumn() > 0) {
            $errors[] = 'A user with this email already exists.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $token = Auth::inviteEmployee($tid, $name, $email, $roleId);
            $link = url('accept-invite.php?token=' . $token);
            flash('success', 'Invitation created for ' . $email . '. Share this activation link with your employee: ' . $link);
        }
        redirect('admin/staff.php');
    }
    if ($op === 'status') {
        $id = (int)input('user_id', 0);
        $status = (string)input('status', '');
        if ($id > 0 && in_array($status, ['active', 'inactive'], true) && $id !== (int)$user['id']) {
            $pdo->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?')->execute([$status, $id, $tid]);
            flash('success', 'Staff status updated.');
        }
        redirect('admin/staff.php');
    }
    if ($op === 'role') {
        $id = (int)input('user_id', 0);
        $roleId = (int)input('role_id', 0);
        if ($id > 0 && $roleId > 0 && $id !== (int)$user['id']) {
            $pdo->prepare('UPDATE users SET role_id = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?')->execute([$roleId, $id, $tid]);
            audit_log('users', $id, 'role_changed');
            flash('success', 'Staff role updated.');
        }
        redirect('admin/staff.php');
    }
}

$stmt = $pdo->prepare(
    'SELECT u.*, r.name AS role_name, r.key AS role_key FROM users u JOIN roles r ON r.id = u.role_id
      WHERE u.tenant_id = ? AND u.deleted_at IS NULL ORDER BY u.id'
);
$stmt->execute([$tid]);
$staff = $stmt->fetchAll();
$usage = SubscriptionService::usage($tid);
$sub = SubscriptionService::current($tid);

admin_header('Staff & Team', 'admin/staff.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people text-emerald"></i> Staff &amp; Team <span class="badge bg-light text-dark"><?= count($staff) ?></span></h4>
  <span class="text-muted small">Plan usage: <?= $usage['users'] ?> / <?= $sub && (int)$sub['max_users'] > 0 ? (int)$sub['max_users'] : '∞' ?> users</span>
</div>
<div class="row g-3">
  <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3"><i class="bi bi-person-plus"></i> Invite employee</h6>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_op" value="invite">
      <div class="mb-2"><label class="form-label">Full name</label><input class="form-control" name="name" required></div>
      <div class="mb-2"><label class="form-label">Work email</label><input class="form-control" type="email" name="email" required></div>
      <div class="mb-3"><label class="form-label">Role</label>
        <select class="form-select" name="role_id">
          <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
        </select>
        <div class="form-text">Roles control permissions: e.g. Cashiers can sell but cannot adjust stock or see reports.</div>
      </div>
      <button class="btn btn-emerald w-100" type="submit">Send invitation</button>
    </form>
  </div></div></div>
  <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Employee</th><th>Role</th><th>Status</th><th>Last login</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($staff as $s): ?>
      <tr>
        <td><strong><?= e($s['name']) ?></strong><br><small class="text-muted"><?= e($s['email']) ?></small></td>
        <td>
          <?php if ((int)$s['id'] !== (int)$user['id'] && $s['role_key'] !== 'owner'): ?>
            <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="_op" value="role"><input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
              <select class="form-select form-select-sm" name="role_id" onchange="this.form.submit()">
                <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$s['role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
              </select></form>
          <?php else: ?><?= e($s['role_name']) ?><?php endif; ?>
        </td>
        <td><?= status_badge($s['status']) ?><?php if ($s['status'] === 'invited'): ?> <small class="text-muted">awaiting activation</small><?php endif; ?></td>
        <td class="small"><?= $s['last_login_at'] ? fmt_date($s['last_login_at'], 'd M H:i') : 'Never' ?></td>
        <td class="text-end">
          <?php if ((int)$s['id'] !== (int)$user['id'] && $s['role_key'] !== 'owner'): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_op" value="status"><input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="status" value="<?= $s['status'] === 'active' ? 'inactive' : 'active' ?>">
              <button class="btn btn-sm btn-outline-<?= $s['status'] === 'active' ? 'danger' : 'success' ?>"><?= $s['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
</div>
<?php admin_footer(); ?>
