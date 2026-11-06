<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect(is_super_admin() ? 'superadmin/index.php' : 'admin/index.php');
}

$errors = [];
if (is_post()) {
    csrf_check();
    $data = [
        'business_name'  => trim((string)input('business_name', '')),
        'owner_name'     => trim((string)input('owner_name', '')),
        'email'          => strtolower(trim((string)input('email', ''))),
        'phone'          => trim((string)input('phone', '')),
        'license_number' => trim((string)input('license_number', '')),
        'address'        => trim((string)input('address', '')),
        'city'           => trim((string)input('city', '')),
        'country'        => trim((string)input('country', '')),
        'password'       => (string)input('password', ''),
    ];
    $errors = validate($data, [
        'business_name' => 'required', 'owner_name' => 'required',
        'email' => 'required|email', 'password' => 'required|min:8',
    ]);
    if ((string)input('password') !== (string)input('password_confirmation')) {
        $errors[] = 'Password confirmation does not match.';
    }
    if (!$errors) {
        $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $exists->execute([$data['email']]);
        if ((int)$exists->fetchColumn() > 0) {
            $errors[] = 'An account with this email already exists.';
        }
    }
    if (!$errors) {
        try {
            [$tenantId, $userId, $token] = Auth::registerTenant($data);
            // In production the verification link is emailed. It is shown here
            // only when APP_DEBUG is enabled (local development convenience).
            $_SESSION['user_id'] = $userId;
            flash('success', 'Welcome to MediStock Cloud! Your pharmacy "' . $data['business_name'] . '" was created with a '
                . (int)$GLOBALS['app_config']['trial_days'] . '-day free trial. '
                . 'Please verify your email address to secure your account.');
            if ($GLOBALS['app_config']['debug']) {
                flash('info', 'Dev verification link: ' . url('verify.php?token=' . $token));
            }
            redirect('admin/index.php');
        } catch (Throwable $ex) {
            $errors[] = 'Registration failed: ' . $ex->getMessage();
        }
    }
}

site_header('Register Your Pharmacy', 'register.php');
?>
<section class="section-pad"><div class="container">
  <div class="row justify-content-center"><div class="col-lg-8">
    <div class="card shadow-sm p-4">
      <div class="text-center mb-3">
        <h4 class="text-navy fw-bold">Register your pharmacy</h4>
        <p class="text-muted small mb-0">Create your tenant account, get a <?= (int)$GLOBALS['app_config']['trial_days'] ?>-day free trial, invite your team later.</p>
      </div>
      <?= flash_html() ?>
      <?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Pharmacy / business name *</label><input class="form-control" name="business_name" required value="<?= e((string)input('business_name', '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Pharmacy license number</label><input class="form-control" name="license_number" value="<?= e((string)input('license_number', '')) ?>" placeholder="e.g. PH-2026-00123"></div>
          <div class="col-md-6"><label class="form-label">Owner / administrator name *</label><input class="form-control" name="owner_name" required value="<?= e((string)input('owner_name', '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Business email *</label><input class="form-control" type="email" name="email" required value="<?= e((string)input('email', '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e((string)input('phone', '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">City</label><input class="form-control" name="city" value="<?= e((string)input('city', '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= e((string)input('address', '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Country</label><input class="form-control" name="country" value="<?= e((string)input('country', '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Password *</label><input class="form-control" type="password" name="password" required minlength="8"><div class="form-text">Minimum 8 characters. Stored as a secure hash.</div></div>
          <div class="col-md-6"><label class="form-label">Confirm password *</label><input class="form-control" type="password" name="password_confirmation" required></div>
        </div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" required id="tos">
          <label class="form-check-label small" for="tos">I agree to the <a href="<?= url('terms.php') ?>">Terms of Service</a> and <a href="<?= url('privacy.php') ?>">Privacy Policy</a>.</label>
        </div>
        <button class="btn btn-emerald w-100 mt-3" type="submit"><i class="bi bi-rocket-takeoff"></i> Create pharmacy &amp; start trial</button>
      </form>
      <p class="text-center small mt-3 mb-0">Already registered? <a href="<?= url('login.php') ?>">Sign in</a></p>
    </div>
  </div></div>
</div></section>
<?php site_footer(); ?>
