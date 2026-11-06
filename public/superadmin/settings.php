<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

$keys = [
    'platform_name' => 'Platform name',
    'support_email' => 'Support email',
    'trial_days' => 'Default trial length (days)',
    'grace_days' => 'Subscription grace period (days)',
    'maintenance_mode' => 'Maintenance mode (0/1)',
    'default_currency' => 'Default currency',
];

if (is_post()) {
    csrf_check();
    $stmt = $pdo->prepare(
        'INSERT INTO platform_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    foreach ($keys as $k => $label) {
        $stmt->execute([$k, trim((string)input($k, ''))]);
    }
    audit_log('platform_settings', null, 'updated');
    flash('success', 'Platform settings saved.');
    redirect('superadmin/settings.php');
}

$rows = $pdo->query('SELECT setting_key, setting_value FROM platform_settings')->fetchAll();
$values = array_column($rows, 'setting_value', 'setting_key');

admin_header('Platform Settings', 'superadmin/settings.php');
?>
<h4 class="mb-3"><i class="bi bi-gear text-emerald"></i> Platform Settings</h4>
<div class="card shadow-sm col-lg-7"><div class="card-body">
  <form method="post">
    <?= csrf_field() ?>
    <?php foreach ($keys as $k => $label): ?>
      <div class="mb-3"><label class="form-label"><?= e($label) ?></label>
        <input class="form-control" name="<?= e($k) ?>" value="<?= e($values[$k] ?? '') ?>"></div>
    <?php endforeach; ?>
    <button class="btn btn-emerald" type="submit"><i class="bi bi-check-lg"></i> Save settings</button>
  </form>
</div></div>
<?php admin_footer(); ?>
