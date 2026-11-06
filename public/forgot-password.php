<?php
require_once __DIR__ . '/../app/bootstrap.php';

$debugLink = null;
if (is_post()) {
    csrf_check();
    $token = Auth::createPasswordReset((string)input('email', ''));
    flash('success', 'If an account exists for that email, a password reset link has been sent. It expires in 60 minutes.');
    if ($token && $GLOBALS['app_config']['debug']) {
        $debugLink = url('reset-password.php?token=' . $token);
    }
}

site_header('Forgot Password', 'forgot-password.php');
?>
<section class="section-pad"><div class="container">
  <div class="row justify-content-center"><div class="col-md-6 col-lg-5">
    <div class="card shadow-sm p-4">
      <h4 class="text-navy fw-bold text-center">Reset your password</h4>
      <p class="text-muted small text-center">Enter your account email and we will send a secure reset link.</p>
      <?= flash_html() ?>
      <?php if ($debugLink): ?><div class="alert alert-info small">Dev reset link: <a href="<?= e($debugLink) ?>"><?= e($debugLink) ?></a></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Email address</label><input class="form-control" type="email" name="email" required></div>
        <button class="btn btn-emerald w-100" type="submit">Send reset link</button>
      </form>
      <p class="text-center small mt-3 mb-0"><a href="<?= url('login.php') ?>">Back to sign in</a></p>
    </div>
  </div></div>
</div></section>
<?php site_footer(); ?>
