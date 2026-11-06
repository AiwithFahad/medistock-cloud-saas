<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (is_post()) {
    csrf_check();
    $op = (string)input('_op', 'contact');
    try {
        if ($op === 'newsletter') {
            $email = strtolower(trim((string)input('email', '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                db()->prepare('INSERT IGNORE INTO newsletter_subscribers (email, created_at) VALUES (?, NOW())')->execute([$email]);
                flash('success', 'Thanks for subscribing to the MediStock newsletter.');
            } else {
                flash('error', 'Please enter a valid email address.');
            }
            redirect('index.php');
        }
        // Contact / sales inquiry.
        $data = [
            'name' => trim((string)input('name', '')),
            'email' => strtolower(trim((string)input('email', ''))),
            'subject' => trim((string)input('subject', 'General inquiry')),
            'message' => trim((string)input('message', '')),
        ];
        $errors = validate($data, ['name' => 'required', 'email' => 'required|email', 'message' => 'required']);
        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            db()->prepare('INSERT INTO contact_messages (name, email, subject, message, created_at) VALUES (?, ?, ?, ?, NOW())')
                ->execute([$data['name'], $data['email'], $data['subject'], $data['message']]);
            flash('success', 'Message sent. Our team replies within one business day.');
            redirect('contact.php');
        }
    } catch (Throwable $ex) {
        flash('error', 'Something went wrong. Please try again.');
    }
}

site_header('Contact', 'contact.php');
?>
<section class="page-hero"><div class="container col-lg-8 text-center">
  <h1>Contact Us</h1><p class="lead text-muted">Sales questions, migration help or support — we reply within one business day.</p>
</div></section>
<section class="section-pad pt-4"><div class="container">
  <?= flash_html() ?>
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="feature-card">
        <h5 class="text-navy"><i class="bi bi-envelope text-emerald"></i> Email</h5>
        <p class="text-muted">sales@medistockcloud.example<br>support@medistockcloud.example</p>
        <h5 class="text-navy mt-4"><i class="bi bi-telephone text-emerald"></i> Phone</h5>
        <p class="text-muted">+1 (555) 010-2450<br>Mon–Sat, 9:00–18:00</p>
        <h5 class="text-navy mt-4"><i class="bi bi-geo-alt text-emerald"></i> Office</h5>
        <p class="text-muted mb-0">MediStock Cloud HQ<br>HealthTech District, Innovation Avenue 12</p>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card shadow-sm"><div class="card-body p-4">
        <h5 class="text-navy mb-3">Send us a message</h5>
        <form method="post">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Your name *</label><input class="form-control" name="name" required></div>
            <div class="col-md-6"><label class="form-label">Email *</label><input class="form-control" type="email" name="email" required></div>
            <div class="col-12"><label class="form-label">Subject</label>
              <select class="form-select" name="subject">
                <option>Sales inquiry</option><option>Demo request</option><option>Migration help</option><option>Support</option><option>Partnership</option>
              </select></div>
            <div class="col-12"><label class="form-label">Message *</label><textarea class="form-control" name="message" rows="5" required></textarea></div>
          </div>
          <button class="btn btn-emerald mt-3" type="submit"><i class="bi bi-send"></i> Send message</button>
        </form>
      </div></div>
    </div>
  </div>
</div></section>
<?php site_footer(); ?>
