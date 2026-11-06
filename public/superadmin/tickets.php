<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
$pdo = db();

if (is_post()) {
    csrf_check();
    $id = (int)input('ticket_id', 0);
    $op = (string)input('_op', '');
    if ($id > 0 && $op === 'reply') {
        $body = trim((string)input('reply', ''));
        if ($body !== '') {
            $pdo->prepare('INSERT INTO support_ticket_replies (ticket_id, user_id, body, created_at) VALUES (?, ?, ?, NOW())')
                ->execute([$id, current_user()['id'], $body]);
            $pdo->prepare("UPDATE support_tickets SET status = 'answered', updated_at = NOW() WHERE id = ?")->execute([$id]);
            flash('success', 'Reply sent.');
        }
        redirect('superadmin/tickets.php?view=' . $id);
    }
    if ($id > 0 && $op === 'close') {
        $pdo->prepare("UPDATE support_tickets SET status = 'closed', updated_at = NOW() WHERE id = ?")->execute([$id]);
        flash('success', 'Ticket closed.');
        redirect('superadmin/tickets.php');
    }
}

$viewId = (int)input('view', 0);
$ticket = null;
$replies = [];
if ($viewId > 0) {
    $stmt = $pdo->prepare('SELECT st.*, t.name AS tenant_name, u.name AS user_name FROM support_tickets st LEFT JOIN tenants t ON t.id = st.tenant_id LEFT JOIN users u ON u.id = st.user_id WHERE st.id = ?');
    $stmt->execute([$viewId]);
    $ticket = $stmt->fetch() ?: null;
    if ($ticket) {
        $r = $pdo->prepare('SELECT r.*, u.name AS author FROM support_ticket_replies r JOIN users u ON u.id = r.user_id WHERE r.ticket_id = ? ORDER BY r.id');
        $r->execute([$viewId]);
        $replies = $r->fetchAll();
    }
}
$tickets = $pdo->query(
    'SELECT st.*, t.name AS tenant_name FROM support_tickets st LEFT JOIN tenants t ON t.id = st.tenant_id ORDER BY st.id DESC LIMIT 100'
)->fetchAll();

admin_header('Support Tickets', 'superadmin/tickets.php');
?>
<?php if ($ticket): ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-life-preserver text-emerald"></i> <?= e($ticket['subject']) ?></h4>
    <a class="btn btn-outline-secondary btn-sm" href="tickets.php">All tickets</a>
  </div>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <div class="d-flex gap-2 mb-2"><?= status_badge($ticket['status']) ?> <span class="badge bg-light text-dark"><?= e(ucfirst($ticket['priority'])) ?> priority</span></div>
    <p class="mb-1"><?= nl2br(e($ticket['message'])) ?></p>
    <small class="text-muted"><?= e($ticket['user_name'] ?? '') ?> · <?= e($ticket['tenant_name'] ?? 'Platform') ?> · <?= fmt_date($ticket['created_at'], 'd M Y H:i') ?></small>
    <hr>
    <?php foreach ($replies as $r): ?>
      <div class="mb-3"><strong class="small"><?= e($r['author']) ?></strong> <small class="text-muted"><?= fmt_date($r['created_at'], 'd M Y H:i') ?></small><p class="mb-0"><?= nl2br(e($r['body'])) ?></p></div>
    <?php endforeach; ?>
    <form method="post" class="mt-3">
      <?= csrf_field() ?><input type="hidden" name="_op" value="reply"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
      <textarea class="form-control" name="reply" rows="3" placeholder="Write a reply…" required></textarea>
      <div class="mt-2"><button class="btn btn-emerald btn-sm" type="submit"><i class="bi bi-reply"></i> Send reply</button></div>
    </form>
    <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="_op" value="close"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><button class="btn btn-outline-secondary btn-sm" type="submit">Close ticket</button></form>
  </div></div>
<?php else: ?>
  <h4 class="mb-3"><i class="bi bi-life-preserver text-emerald"></i> Support Tickets</h4>
  <div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>#</th><th>Subject</th><th>Pharmacy</th><th>Priority</th><th>Status</th><th>Created</th><th class="text-end">View</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $t): ?>
      <tr><td><?= (int)$t['id'] ?></td><td><strong><?= e($t['subject']) ?></strong></td><td><?= e($t['tenant_name'] ?? '—') ?></td>
      <td><span class="badge bg-<?= $t['priority'] === 'high' ? 'danger' : ($t['priority'] === 'medium' ? 'warning text-dark' : 'secondary') ?>"><?= e(ucfirst($t['priority'])) ?></span></td>
      <td><?= status_badge($t['status']) ?></td><td><?= fmt_date($t['created_at']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="tickets.php?view=<?= (int)$t['id'] ?>"><i class="bi bi-eye"></i></a></td></tr>
    <?php endforeach; ?>
    <?php if (!$tickets): ?><tr><td colspan="7" class="text-center text-muted py-4">No tickets.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>
<?php admin_footer(); ?>
