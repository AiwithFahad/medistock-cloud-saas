<?php
require_once __DIR__ . '/../app/bootstrap.php';

$token = (string)input('token', '');
if (is_post()) {
    csrf_check();
    $password = (string)input('password', '');
    if (strlen($password) < 8) {
        flash('error', 'Password must be at least 8 characters.');
    } elseif ($password !== (string)input('password_confirmation')) {
        flash('error', 'Password confirmation does not match.');
    } elseif (Auth::acceptInvitation((string)input('token', ''), $password)) {
        flash('success', 'Your account is active. Please sign in.');
        redirect('login.php');
    } else {
        flash('error', 'This invitation is invalid or has already been accepted.');
    }
}

site_header('Accept Invitation', 'accept-invite.php');
?>
<section class="section-pad"><div class="container">
  <div class="row justify-content-center"><div class="col-md-6 col-lg-5">
    <div class="card shadow-sm p-4">
      <h4 class="text-navy fw-bold text-center">Join your pharmacy team</h4>
      <p class="text-muted small text-center">Set a password to activate your MediStock Cloud account.</p>
      <?= flash_html() ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required minlength="8"></div>
        <div class="mb-3"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="password_confirmation" required></div>
        <button class="btn btn-emerald w-100" type="submit">Activate account</button>
      </form>
    </div>
  </div></div>
</div></section>
<?php site_footer(); ?>
