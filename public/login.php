<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect(is_super_admin() ? 'superadmin/index.php' : 'admin/index.php');
}

$error = null;
if (is_post()) {
    csrf_check();
    $user = Auth::attempt((string)input('email', ''), (string)input('password', ''), $error);
    if ($user) {
        flash('success', 'Welcome back, ' . $user['name'] . '.');
        redirect($user['role_key'] === 'super_admin' ? 'superadmin/index.php' : 'admin/index.php');
    }
}

site_header('Sign In', 'login.php');
?>
<section class="section-pad"><div class="container">
  <div class="row justify-content-center"><div class="col-md-6 col-lg-5">
    <div class="card shadow-sm p-4">
      <div class="text-center mb-3">
        <span class="brand-badge" style="width:46px;height:46px;font-size:1.3rem"><i class="bi bi-capsule"></i></span>
        <h4 class="text-navy fw-bold mt-2">Sign in to MediStock Cloud</h4>
        <p class="text-muted small mb-0">Pharmacy dashboards, POS and the platform Super Admin.</p>
      </div>
      <?= flash_html() ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Email address</label>
          <input class="form-control" type="email" name="email" required autofocus value="<?= e((string)input('email', '')) ?>"></div>
        <div class="mb-3"><label class="form-label">Password</label>
          <input class="form-control" type="password" name="password" required></div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <span class="small text-muted"><i class="bi bi-shield-lock"></i> Rate-limited &amp; encrypted</span>
          <a class="small" href="<?= url('forgot-password.php') ?>">Forgot password?</a>
        </div>
        <button class="btn btn-emerald w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
      </form>
      <hr>
      <p class="text-center small mb-1">New pharmacy? <a href="<?= url('register.php') ?>">Start a free trial</a></p>
      <div class="bg-light rounded p-2 small text-muted">
        <strong>Demo accounts</strong> (password: <code>password</code>)<br>
        Super Admin: <code>super@medistock.test</code> · Pharmacy Admin: <code>admin@demo.com</code><br>
        Pharmacist: <code>pharmacist@demo.com</code> · Cashier: <code>cashier@demo.com</code>
      </div>
    </div>
  </div></div>
</div></section>
<?php site_footer(); ?>
