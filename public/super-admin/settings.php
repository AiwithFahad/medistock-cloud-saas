<?php
/**
 * MediStock Cloud — Super Admin: platform settings (super-admin tree).
 * Key/value settings incl. the registration-approval switch used by
 * Auth::registerTenant() (require_tenant_approval). Upserts only.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

$keys = [
    'platform_name' => 'Platform name',
    'support_email' => 'Support email',
    'trial_days' => 'Trial length (days)',
    'grace_days' => 'Grace period (days)',
    'default_currency' => 'Default currency',
    'maintenance_mode' => 'Maintenance mode (1 = on, 0 = off)',
    'require_tenant_approval' => 'Require approval for new pharmacies (1 = yes, 0 = no)',
];

if (is_post()) {
    csrf_check();
    $stmt = $pdo->prepare(
        'INSERT INTO platform_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    foreach (array_keys($keys) as $k) {
        $stmt->execute([$k, trim((string)input($k, ''))]);
    }
    audit_log('platform_settings', null, 'updated');
    flash('success', 'Platform settings saved.');
    redirect('super-admin/settings.php');
}

$rows = $pdo->query('SELECT setting_key, setting_value FROM platform_settings')->fetchAll();
$values = array_column($rows, 'setting_value', 'setting_key');

admin_header('Platform Settings', 'super-admin/settings.php');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-gear text-emerald"></i> Platform Settings</h4>
</div>
<div class="row"><div class="col-lg-7">
<div class="card shadow-sm"><div class="card-body">
  <form method="post">
    <?= csrf_field() ?>
    <?php foreach ($keys as $k => $label): ?>
      <div class="mb-3">
        <label class="form-label"><?= e($label) ?></label>
        <input class="form-control" name="<?= e($k) ?>" value="<?= e($values[$k] ?? '') ?>">
        <?php if ($k === 'require_tenant_approval'): ?>
          <div class="form-text">When set to 1, new pharmacy registrations start as <strong>pending</strong> and cannot sign in until approved under Pharmacies. Existing pharmacies are unaffected.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <button class="btn btn-emerald" type="submit"><i class="bi bi-check-lg"></i> Save settings</button>
  </form>
</div></div>
<div class="alert alert-info small mt-3"><i class="bi bi-info-circle"></i> Coupons, announcements, invoices and support tickets are managed from the platform modules in the sidebar.</div>
</div></div>
<?php admin_footer(); ?>
