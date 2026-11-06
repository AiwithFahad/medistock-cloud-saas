<?php
require_once __DIR__ . '/../app/bootstrap.php';

$token = (string)input('token', '');
$ok = $token !== '' && Auth::verifyEmail($token);

site_header('Verify Email', 'verify.php');
?>
<section class="section-pad"><div class="container">
  <div class="row justify-content-center"><div class="col-md-6 col-lg-5">
    <div class="card shadow-sm p-4 text-center">
      <?php if ($ok): ?>
        <i class="bi bi-patch-check-fill text-emerald display-4"></i>
        <h4 class="text-navy fw-bold mt-2">Email verified</h4>
        <p class="text-muted">Your email address is confirmed. Your account is fully secured.</p>
      <?php else: ?>
        <i class="bi bi-exclamation-circle text-warning display-4"></i>
        <h4 class="text-navy fw-bold mt-2">Link invalid or already used</h4>
        <p class="text-muted">This verification link is not valid. If you already verified, simply sign in.</p>
      <?php endif; ?>
      <a class="btn btn-emerald" href="<?= url('login.php') ?>">Go to sign in</a>
    </div>
  </div></div>
</div></section>
<?php site_footer(); ?>
