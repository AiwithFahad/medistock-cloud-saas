<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

$stmt = $pdo->query(
    'SELECT i.*, t.name AS tenant_name FROM invoices i JOIN tenants t ON t.id = i.tenant_id ORDER BY i.id DESC LIMIT 150'
);
$invoices = $stmt->fetchAll();

admin_header('Invoices', 'superadmin/invoices.php');
?>
<h4 class="mb-3"><i class="bi bi-receipt text-emerald"></i> Subscription Invoices</h4>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Invoice</th><th>Pharmacy</th><th>Amount</th><th>Discount</th><th>Total</th><th>Status</th><th>Due</th><th>Paid at</th></tr></thead>
  <tbody>
  <?php foreach ($invoices as $inv): ?>
    <tr>
      <td><code><?= e($inv['invoice_number']) ?></code></td>
      <td><?= e($inv['tenant_name']) ?></td>
      <td><?= money($inv['amount']) ?></td>
      <td>−<?= money($inv['discount']) ?></td>
      <td><strong><?= money($inv['total']) ?></strong></td>
      <td><?= status_badge($inv['status'] === 'paid' ? 'paid' : $inv['status']) ?></td>
      <td><?= fmt_date($inv['due_date']) ?></td>
      <td><?= fmt_date($inv['paid_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$invoices): ?><tr><td colspan="8" class="text-center text-muted py-4">No invoices yet.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php admin_footer(); ?>
