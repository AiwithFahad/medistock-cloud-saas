<?php
/** Create a support ticket for the current tenant, then return to settings. */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_tenant();
if (is_post()) {
    csrf_check();
    $subject = trim((string)input('subject', ''));
    $message = trim((string)input('message', ''));
    $priority = (string)input('priority', 'medium');
    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $priority = 'medium';
    }
    if ($subject !== '' && $message !== '') {
        db()->prepare('INSERT INTO support_tickets (tenant_id, user_id, subject, message, priority, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "open", NOW(), NOW())')
            ->execute([tenant_id(), $user['id'], $subject, $message, $priority]);
        activity_log('support.ticket_opened', $subject, tenant_id());
        flash('success', 'Support ticket opened. Our team will reply in your dashboard.');
    } else {
        flash('error', 'Subject and message are required.');
    }
}
redirect('admin/settings.php');
