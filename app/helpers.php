<?php
/**
 * MediStock Cloud — global helper functions.
 * Output escaping, URLs, CSRF, flash messages, RBAC, logging, formatting.
 */

/** Escape a value for safe HTML output (XSS prevention). */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Build an absolute application URL for a public path. */
function url(string $path = ''): string
{
    $app = $GLOBALS['app_config'] ?? ['url' => ''];
    return rtrim($app['url'], '/') . '/' . ltrim($path, '/');
}

/** URL of a static asset inside public/assets. */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Read a request value (POST first, then GET) with a default. */
function input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/* ---------------------------------------------------------------- CSRF */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Abort with 419 when the CSRF token is missing or invalid. */
function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['_csrf'] ?? '', (string)$token)) {
        http_response_code(419);
        exit('CSRF token mismatch. Please go back and try again.');
    }
}

/* ------------------------------------------------------- Flash messages */

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function flash_html(): string
{
    $html = '';
    foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $key => $class) {
        if ($msg = flash($key)) {
            $html .= '<div class="alert alert-' . $class . ' alert-dismissible fade show" role="alert">'
                . e($msg)
                . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
        }
    }
    return $html;
}

/* ------------------------------------------------------------ Formatting */

function money($amount): string
{
    $currency = ($GLOBALS['app_config']['currency'] ?? 'USD');
    $symbol = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'PKR' => 'Rs '][$currency] ?? ($currency . ' ');
    return $symbol . number_format((float)$amount, 2);
}

function fmt_date($value, string $format = 'd M Y'): string
{
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime((string)$value);
    return $ts ? date($format, $ts) : '—';
}

function slugify(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text), '-'));
    return $slug !== '' ? $slug : 'tenant-' . bin2hex(random_bytes(3));
}

function status_badge(?string $status): string
{
    $map = [
        'active' => 'success', 'verified' => 'success', 'paid' => 'success', 'completed' => 'success',
        'pending' => 'warning', 'trial' => 'info', 'grace' => 'warning', 'open' => 'info',
        'suspended' => 'danger', 'expired' => 'danger', 'failed' => 'danger', 'cancelled' => 'secondary',
        'refunded' => 'secondary', 'closed' => 'secondary', 'inactive' => 'secondary',
    ];
    $class = $map[$status ?? ''] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . e(ucfirst((string)$status)) . '</span>';
}

/* ------------------------------------------------------------------ Auth */

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user ?: null;
    }
    if (empty($_SESSION['user_id'])) {
        $user = null;
        return null;
    }
    $stmt = db()->prepare(
        'SELECT u.*, r.key AS role_key, r.name AS role_name
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.id = ? AND u.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $user = $row ?: null;
    return $user;
}

/** Tenant id of the signed-in user (NULL for the platform Super Admin). */
function tenant_id(): ?int
{
    $u = current_user();
    return $u && $u['tenant_id'] !== null ? (int)$u['tenant_id'] : null;
}

function is_super_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role_key'] === 'super_admin';
}

/** Tenant-aware authorization: does the current user's role hold a permission? */
function can(string $permission): bool
{
    $u = current_user();
    if ($u === null) {
        return false;
    }
    if ($u['role_key'] === 'super_admin' || $u['role_key'] === 'owner') {
        return true;
    }
    static $cache = [];
    $roleId = (int)$u['role_id'];
    if (!isset($cache[$roleId])) {
        $stmt = db()->prepare(
            'SELECT p.key FROM permissions p
               JOIN role_permissions rp ON rp.permission_id = p.id
              WHERE rp.role_id = ?'
        );
        $stmt->execute([$roleId]);
        $cache[$roleId] = array_column($stmt->fetchAll(), 'key');
    }
    return in_array($permission, $cache[$roleId], true);
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }
    if ($u['status'] !== 'active') {
        session_destroy();
        redirect('login.php');
    }
    return $u;
}

function require_role(array $roles): array
{
    $u = require_login();
    if (!in_array($u['role_key'], $roles, true)) {
        http_response_code(403);
        exit('403 — You do not have access to this area.');
    }
    return $u;
}

function require_permission(string $permission): array
{
    $u = require_login();
    if (!can($permission)) {
        http_response_code(403);
        exit('403 — Your role does not include the "' . e($permission) . '" permission.');
    }
    return $u;
}

/**
 * Guard for tenant pages: signed-in user must belong to a tenant that is
 * not suspended, and every query in the page must be scoped by tenant_id().
 */
function require_tenant(): array
{
    $u = require_login();
    if (is_super_admin()) {
        redirect('superadmin/index.php');
    }
    $tid = tenant_id();
    if ($tid === null) {
        http_response_code(403);
        exit('403 — No pharmacy is attached to this account.');
    }
    $stmt = db()->prepare('SELECT status FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tid]);
    if ($stmt->fetchColumn() === 'suspended') {
        http_response_code(403);
        exit('This pharmacy account is suspended. Please contact MediStock Cloud support.');
    }
    return $u;
}

/* ---------------------------------------------------------------- Logging */

/** Write to activity_logs (user-facing timeline) — never throws. */
function activity_log(string $action, string $description = '', ?int $tid = null, ?int $userId = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (tenant_id, user_id, action, description, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $u = current_user();
        $stmt->execute([
            $tid ?? ($u['tenant_id'] ?? null),
            $userId ?? ($u['id'] ?? null),
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $ignored) {
        // Logging must never break the request.
    }
}

/** Write to audit_logs (security/compliance trail) — never throws. */
function audit_log(string $entity, ?int $entityId, string $action, $before = null, $after = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (tenant_id, user_id, entity, entity_id, action, old_values, new_values, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $u = current_user();
        $stmt->execute([
            $u['tenant_id'] ?? null,
            $u['id'] ?? null,
            $entity,
            $entityId,
            $action,
            $before !== null ? json_encode($before) : null,
            $after !== null ? json_encode($after) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $ignored) {
    }
}

/** Create an in-app notification for one user or a whole tenant. */
function notify(?int $tenantId, ?int $userId, string $title, string $body, string $type = 'info'): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO notifications (tenant_id, user_id, type, title, body, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$tenantId, $userId, $type, $title, $body]);
    } catch (Throwable $ignored) {
    }
}

/** Simple server-side validation helper: returns list of error strings. */
function validate(array $data, array $rules): array
{
    $errors = [];
    foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null;
        foreach (explode('|', $ruleString) as $rule) {
            if ($rule === 'required' && ($value === null || $value === '')) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
            if ($rule === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a valid email address.';
            }
            if (str_starts_with($rule, 'min:') && $value !== null && strlen((string)$value) < (int)substr($rule, 4)) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be at least ' . substr($rule, 4) . ' characters.';
            }
            if ($rule === 'numeric' && $value !== null && $value !== '' && !is_numeric($value)) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be numeric.';
            }
        }
    }
    return $errors;
}
