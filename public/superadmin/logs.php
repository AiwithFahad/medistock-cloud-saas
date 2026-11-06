<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();
$tab = (string)input('tab', 'activity');

admin_header('System Logs', 'superadmin/logs.php');
?>
<h4 class="mb-3"><i class="bi bi-clock-history text-emerald"></i> System Activity &amp; Audit Logs</h4>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'activity' ? 'active' : '' ?>" href="logs.php?tab=activity">Activity logs</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="logs.php?tab=audit">Audit logs</a></li>
</ul>
<?php if ($tab === 'audit'): ?>
  <?php $rows = $pdo->query('SELECT a.*, u.name AS user_name, t.name AS tenant_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tenants t ON t.id = a.tenant_id ORDER BY a.id DESC LIMIT 100')->fetchAll(); ?>
  <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>When</th><th>Actor</th><th>Tenant</th><th>Entity</th><th>Action</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><?= fmt_date($r['created_at'], 'd M Y H:i') ?></td><td><?= e($r['user_name'] ?? 'System') ?></td><td><?= e($r['tenant_name'] ?? '—') ?></td>
      <td><code><?= e($r['entity']) ?><?= $r['entity_id'] ? '#' . (int)$r['entity_id'] : '' ?></code></td><td><?= e($r['action']) ?></td><td class="small"><?= e($r['ip_address'] ?? '') ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
<?php else: ?>
  <?php $rows = $pdo->query('SELECT a.*, u.name AS user_name, t.name AS tenant_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tenants t ON t.id = a.tenant_id ORDER BY a.id DESC LIMIT 100')->fetchAll(); ?>
  <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>When</th><th>Actor</th><th>Tenant</th><th>Action</th><th>Description</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><?= fmt_date($r['created_at'], 'd M Y H:i') ?></td><td><?= e($r['user_name'] ?? 'System') ?></td><td><?= e($r['tenant_name'] ?? '—') ?></td>
      <td><code><?= e($r['action']) ?></code></td><td><?= e($r['description'] ?? '') ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php admin_footer(); ?>
