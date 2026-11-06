<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
require_permission('settings.manage');
$tid = tenant_id();
$pdo = db();

if (is_post()) {
    csrf_check();
    try {
        // Pharmacy profile.
        $pdo->prepare('UPDATE tenants SET name = ?, phone = ?, license_number = ?, address = ?, city = ?, country = ?, updated_at = NOW() WHERE id = ?')
            ->execute([
                trim((string)input('name', '')), trim((string)input('phone', '')),
                trim((string)input('license_number', '')), trim((string)input('address', '')),
                trim((string)input('city', '')), trim((string)input('country', '')), $tid,
            ]);

        // Logo upload (validated image, randomised filename).
        if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $mime = mime_content_type($_FILES['logo']['tmp_name']);
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime]) || $_FILES['logo']['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Logo must be a PNG, JPG, SVG or WebP image under 2 MB.');
            }
            $dir = __DIR__ . '/../assets/uploads';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $file = 'logo-' . $tid . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $file);
            $pdo->prepare('UPDATE tenants SET logo_path = ?, updated_at = NOW() WHERE id = ?')->execute(['assets/uploads/' . $file, $tid]);
        }

        // Key/value settings.
        $keys = ['brand_color', 'currency', 'tax_rate', 'receipt_footer', 'low_stock_threshold', 'expiry_alert_days', 'allow_negative_stock'];
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        foreach ($keys as $k) {
            $value = $k === 'allow_negative_stock'
                ? (isset($_POST['allow_negative_stock']) ? '1' : '0')
                : trim((string)input($k, ''));
            $stmt->execute([$tid, $k, $value]);
        }
        audit_log('tenant_settings', $tid, 'updated');
        flash('success', 'Settings saved.');
        redirect('admin/settings.php');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('admin/settings.php');
    }
}

$tenant = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
$tenant->execute([$tid]);
$t = $tenant->fetch();
$get = static fn(string $k, string $d = '') => StockService::tenantSetting($tid, $k, $d) ?? $d;

// Recent support tickets for this tenant (creation posts to ticket-create.php).
$tickets = $pdo->prepare('SELECT * FROM support_tickets WHERE tenant_id = ? ORDER BY id DESC LIMIT 10');
$tickets->execute([$tid]);
$ticketRows = $tickets->fetchAll();

admin_header('Pharmacy Settings', 'admin/settings.php');
?>
<h4 class="mb-3"><i class="bi bi-gear text-emerald"></i> Pharmacy Settings</h4>
<div class="row g-3">
  <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Business profile &amp; preferences</h6>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Pharmacy name</label><input class="form-control" name="name" value="<?= e($t['name']) ?>" required></div>
        <div class="col-md-6"><label class="form-label">License number</label><input class="form-control" name="license_number" value="<?= e($t['license_number'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($t['phone'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Logo (PNG/JPG/SVG/WebP, ≤ 2 MB)</label><input class="form-control" type="file" name="logo" accept="image/*">
          <?php if ($t['logo_path']): ?><div class="form-text">Current: <?= e($t['logo_path']) ?></div><?php endif; ?></div>
        <div class="col-md-6"><label class="form-label">City</label><input class="form-control" name="city" value="<?= e($t['city'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Country</label><input class="form-control" name="country" value="<?= e($t['country'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= e($t['address'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Brand colour</label><input class="form-control form-control-color w-100" type="color" name="brand_color" value="<?= e($get('brand_color', '#059669')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Currency</label><input class="form-control" name="currency" value="<?= e($get('currency', 'USD')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Tax rate % (POS)</label><input class="form-control" type="number" step="0.01" name="tax_rate" value="<?= e($get('tax_rate', '0')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Low-stock threshold (default)</label><input class="form-control" type="number" name="low_stock_threshold" value="<?= e($get('low_stock_threshold', '10')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Expiry alert window (days)</label><input class="form-control" type="number" name="expiry_alert_days" value="<?= e($get('expiry_alert_days', '90')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Receipt footer</label><input class="form-control" name="receipt_footer" value="<?= e($get('receipt_footer', 'Thank you for your purchase!')) ?>"></div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="allow_negative_stock" id="negStock" <?= $get('allow_negative_stock', '0') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="negStock"><strong>Authorized negative-stock policy</strong> — allow sales/adjustments to drive a batch below zero (not recommended; every such movement is audit-logged).</label>
          </div>
        </div>
      </div>
      <button class="btn btn-emerald mt-3" type="submit"><i class="bi bi-check-lg"></i> Save settings</button>
    </form>
  </div></div></div>

  <div class="col-lg-5">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h6 class="fw-bold text-navy mb-3"><i class="bi bi-life-preserver"></i> Support tickets</h6>
      <form method="post" action="<?= url('admin/ticket-create.php') ?>">
        <?= csrf_field() ?>
        <div class="mb-2"><input class="form-control" name="subject" placeholder="Subject" required></div>
        <div class="mb-2"><textarea class="form-control" name="message" rows="3" placeholder="Describe your issue…" required></textarea></div>
        <div class="mb-2"><select class="form-select" name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></div>
        <button class="btn btn-outline-emerald w-100" type="submit">Open ticket</button>
      </form>
      <hr>
      <?php foreach ($ticketRows as $tk): ?>
        <div class="d-flex justify-content-between small border-bottom py-1"><span><?= e($tk['subject']) ?></span><?= status_badge($tk['status']) ?></div>
      <?php endforeach; ?>
      <?php if (!$ticketRows): ?><p class="text-muted small mb-0">No tickets yet.</p><?php endif; ?>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h6 class="fw-bold text-navy mb-2"><i class="bi bi-shield-lock"></i> Data &amp; backups</h6>
      <p class="text-muted small">Your data is tenant-isolated and every change is audit-logged. Database backup &amp; restore procedures (mysqldump-based, with a retention schedule) are documented in <code>docs/DEPLOYMENT.md</code> in the repository.</p>
      <a class="btn btn-outline-secondary btn-sm" href="<?= url('admin/reports.php') ?>?export=sales"><i class="bi bi-download"></i> Export sales data (CSV)</a>
    </div></div>
  </div>
</div>
<?php admin_footer(); ?>
