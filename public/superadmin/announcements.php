<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['super_admin']);
admin_header('Announcements', 'superadmin/announcements.php');
Crud::handle([
    'title' => 'Announcement', 'icon' => 'bi-megaphone', 'table' => 'announcements',
    'permission' => 'dashboard.view', 'tenant' => false, 'base' => 'announcements.php',
    'order' => 'id DESC',
    'columns' => ['title' => 'Title', 'audience' => 'Audience', 'is_published' => 'Published', 'published_at' => 'Published at'],
    'display' => [
        'is_published' => static fn($r) => $r['is_published'] ? '<span class="badge bg-success">Published</span>' : '<span class="badge bg-secondary">Draft</span>',
    ],
    'search' => ['title', 'body'],
    'fields' => [
        'title' => ['label' => 'Title', 'required' => true, 'col' => 'col-12'],
        'body' => ['label' => 'Message', 'type' => 'textarea', 'col' => 'col-12', 'required' => true],
        'audience' => ['label' => 'Audience', 'type' => 'select', 'options' => ['all' => 'All pharmacies', 'trial' => 'Trial pharmacies', 'paid' => 'Paying pharmacies']],
        'is_published' => ['label' => 'Published', 'type' => 'checkbox', 'default' => 1],
    ],
    'after_save' => static function (int $id, array $data): void {
        if (!empty($data['is_published'])) {
            db()->prepare('UPDATE announcements SET published_at = COALESCE(published_at, NOW()) WHERE id = ?')->execute([$id]);
            // Fan out a notification to every tenant.
            $tenants = db()->query('SELECT id FROM tenants WHERE deleted_at IS NULL')->fetchAll();
            foreach ($tenants as $t) {
                notify((int)$t['id'], null, 'Announcement: ' . $data['title'], (string)$data['body'], 'announcement');
            }
        }
    },
]);
admin_footer();
