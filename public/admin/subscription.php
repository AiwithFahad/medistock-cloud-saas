<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_tenant();
require_permission('billing.manage');
$tid = tenant_id();
$pdo = db();

if (is_post()) {
    csrf_check();
    $op = (string)input('_op', '');
    try {
        if ($op === 'change_plan') {
            [$subId, $paymentId, $invoiceId] = SubscriptionService::changePlan(
                $tid, (int)input('plan_id', 0), (string)input('billing_cycle', 'monthly'),
                trim((string)input('coupon', '')) ?: null
            );
            flash('success', 'Plan change created. Invoice and payment #' . $paymentId . ' are PENDING — the new plan activates only when the payment is verified by the platform (or your payment provider confirms it).');
        } elseif ($op === 'cancel') {
            SubscriptionService::cancel($tid);
            flash('warning', 'Subscription cancelled. Access continues until the end of the current period plus the grace period.');
        } elseif ($op === 'record_payment') {
            // Manual payment recording for an outstanding invoice: stays PENDING.
            $invoiceId = (int)input('invoice_id', 0);
            $inv = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1');
            $inv->execute([$invoiceId, $tid]);
            $invoice = $inv->fetch();
            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }
            $pay = $pdo->prepare(
                'INSERT INTO payments (tenant_id, subscription_id, method, amount, currency, status, reference, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, "pending", ?, NOW(), NOW())'
            );
            $pay->execute([$tid, $invoice['subscription_id'], (string)input('method', 'bank'), (float)$invoice['total'], $GLOBALS['app_config']['currency'] ?? 'USD', trim((string)input('reference', ''))]);
            $pdo->prepare('UPDATE invoices SET payment_id = ?, updated_at = NOW() WHERE id = ?')->execute([(int)$pdo->lastInsertId(), $invoiceId]);
            flash('success', 'Payment recorded as PENDING. It will only be marked verified after confirmed receipt — never automatically.');
        }
        redirect('admin/subscription.php');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('admin/subscription.php');
    }
}

$sub = SubscriptionService::current($tid);
$plans = SubscriptionService::plans();
$usage = SubscriptionService::usage($tid);
$payments = $pdo->prepare('SELECT * FROM payments WHERE tenant_id = ? ORDER BY id DESC LIMIT 20');
$payments->execute([$tid]);
$paymentRows = $payments->fetchAll();
$invoices = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ? ORDER BY id DESC LIMIT 20');
$invoices->execute([$tid]);
$invoiceRows = $invoices->fetchAll();

$limitBar = static function (string $label, int $used, int $limit): void {
    $pct = $limit > 0 ? min(100, (int)round($used / $limit * 100)) : 0;
    echo '<div class="mb-3"><div class="d-flex justify-content-between small"><span>' . e($label) . '</span><span>'
        . number_format($used) . ' / ' . ($limit === 0 ? 'Unlimited' : number_format($limit)) . '</span></div>'
        . '<div class="progress" style="height:8px"><div class="progress-bar bg-' . ($pct >= 90 ? 'danger' : 'success') . '" style="width:' . $pct . '%"></div></div></div>';
};

admin_header('Subscription & Billing', 'admin/subscription.php');
?>
<h4 class="mb-3"><i class="bi bi-stars text-emerald"></i> Subscription &amp; Billing</h4>
<div class="row g-3 mb-4">
  <div class="col-lg-5"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy">Current plan</h6>
    <?php if ($sub): ?>
      <div class="display-6 fw-bold text-emerald"><?= e($sub['plan_name']) ?></div>
      <p class="mb-1">Status: <?= status_badge($sub['status']) ?> · Cycle: <strong><?= e(ucfirst($sub['billing_cycle'])) ?></strong></p>
      <p class="text-muted small mb-3">Period ends <?= fmt_date($sub['ends_at']) ?><?php if ($sub['trial_ends_at']): ?> · Trial ends <?= fmt_date($sub['trial_ends_at']) ?><?php endif; ?></p>
      <form method="post" onsubmit="return confirm('Cancel this subscription?')"><?= csrf_field() ?><input type="hidden" name="_op" value="cancel"><button class="btn btn-outline-danger btn-sm">Cancel subscription</button></form>
    <?php else: ?>
      <p class="text-muted">No active subscription. Choose a plan below to continue.</p>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-7"><div class="card shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Plan usage (live from your data)</h6>
    <?php if ($sub): ?>
      <?php $limitBar('Branches', $usage['branches'], (int)$sub['max_branches']); ?>
      <?php $limitBar('Staff users', $usage['users'], (int)$sub['max_users']); ?>
      <?php $limitBar('Medicine records', $usage['medicines'], (int)$sub['max_medicines']); ?>
      <?php $limitBar('Sales this month', $usage['transactions'], (int)$sub['max_monthly_transactions']); ?>
    <?php else: ?><p class="text-muted mb-0">Usage appears once a plan is active.</p><?php endif; ?>
  </div></div></div>
</div>

<div class="card shadow-sm mb-4"><div class="card-body">
  <h6 class="fw-bold text-navy mb-3">Change plan (upgrade / downgrade / renew)</h6>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="_op" value="change_plan">
    <div class="row g-3">
      <?php foreach ($plans as $plan): ?>
        <div class="col-md-6 col-lg-4"><label class="price-card p-3 d-block h-100" style="cursor:pointer">
          <input type="radio" name="plan_id" value="<?= (int)$plan['id'] ?>" <?= $sub && (int)$sub['plan_id'] === (int)$plan['id'] ? 'checked' : '' ?>>
          <strong class="text-navy"><?= e($plan['name']) ?></strong>
          <div class="price-amount fs-4"><?= money($plan['monthly_price']) ?><small class="fs-6 text-muted">/mo</small></div>
          <div class="small text-muted"><?= money($plan['yearly_price']) ?> billed yearly</div>
        </label></div>
      <?php endforeach; ?>
    </div>
    <div class="row g-3 mt-1">
      <div class="col-md-4"><label class="form-label">Billing cycle</label>
        <select class="form-select" name="billing_cycle"><option value="monthly">Monthly</option><option value="yearly">Yearly (2 months free)</option></select></div>
      <div class="col-md-4"><label class="form-label">Coupon code</label><input class="form-control" name="coupon" placeholder="e.g. WELCOME10"></div>
      <div class="col-md-4 d-flex align-items-end"><button class="btn btn-emerald w-100" type="submit"><i class="bi bi-arrow-up-circle"></i> Change plan</button></div>
    </div>
    <div class="form-text">Changing plan creates an invoice and a <strong>pending</strong> payment. The plan activates when payment is verified — we never mark payments successful without verified confirmation.</div>
  </form>
</div></div>

<div class="row g-3">
  <div class="col-lg-6"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Invoices</h6>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Invoice</th><th>Total</th><th>Status</th><th>Due</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($invoiceRows as $inv): ?>
        <tr><td><code><?= e($inv['invoice_number']) ?></code></td><td><?= money($inv['total']) ?></td><td><?= status_badge($inv['status'] === 'paid' ? 'paid' : $inv['status']) ?></td><td><?= fmt_date($inv['due_date']) ?></td>
        <td class="text-end"><?php if ($inv['status'] !== 'paid'): ?>
          <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="_op" value="record_payment"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
            <select class="form-select form-select-sm" name="method" style="width:110px"><option value="bank">Bank</option><option value="card">Card</option><option value="cash">Cash</option><option value="mobile">Mobile</option></select>
            <button class="btn btn-sm btn-outline-emerald">Record payment</button></form>
        <?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$invoiceRows): ?><tr><td colspan="5" class="text-center text-muted py-3">No invoices yet.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div></div></div>
  <div class="col-lg-6"><div class="card shadow-sm"><div class="card-body">
    <h6 class="fw-bold text-navy mb-3">Payment history</h6>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Reference</th><th>Method</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($paymentRows as $p): ?>
        <tr><td><code><?= e($p['reference'] ?? '—') ?></code></td><td><?= e(ucfirst($p['method'])) ?></td><td><?= money($p['amount']) ?></td><td><?= status_badge($p['status']) ?></td><td><?= fmt_date($p['created_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$paymentRows): ?><tr><td colspan="5" class="text-center text-muted py-3">No payments yet.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div></div></div>
</div>
<?php admin_footer(); ?>
