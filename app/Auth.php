<?php
/**
 * MediStock Cloud — authentication & account lifecycle.
 * Registration (tenant onboarding), login with rate limiting, logout,
 * employee invitations, email verification tokens and password resets.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    /* ------------------------------------------------------------ Login */

    /** Count recent failed attempts for an email + IP pair. */
    private static function recentFailures(string $email): int
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND ip_address = ? AND success = 0
                AND attempted_at > (NOW() - INTERVAL ' . self::LOCK_MINUTES . ' MINUTE)'
        );
        $stmt->execute([$email, $_SERVER['REMOTE_ADDR'] ?? '']);
        return (int)$stmt->fetchColumn();
    }

    private static function recordAttempt(string $email, bool $success): void
    {
        $stmt = db()->prepare(
            'INSERT INTO login_attempts (email, ip_address, success, attempted_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$email, $_SERVER['REMOTE_ADDR'] ?? '', $success ? 1 : 0]);
    }

    /**
     * Attempt a login. Returns the user row on success, null on failure.
     * $error receives a human-readable reason.
     */
    public static function attempt(string $email, string $password, ?string &$error = null): ?array
    {
        $email = strtolower(trim($email));

        if (self::recentFailures($email) >= self::MAX_ATTEMPTS) {
            $error = 'Too many failed attempts. Please try again in ' . self::LOCK_MINUTES . ' minutes.';
            return null;
        }

        $stmt = db()->prepare(
            'SELECT u.*, r.key AS role_key FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.email = ? AND u.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordAttempt($email, false);
            $error = 'Invalid email or password.';
            return null;
        }

        if ($user['status'] !== 'active') {
            self::recordAttempt($email, false);
            $error = 'This account is ' . $user['status'] . '. Please contact support.';
            return null;
        }

        if ($user['tenant_id'] !== null) {
            $t = db()->prepare('SELECT status FROM tenants WHERE id = ?');
            $t->execute([$user['tenant_id']]);
            if ($t->fetchColumn() === 'suspended') {
                self::recordAttempt($email, false);
                $error = 'This pharmacy account is suspended. Please contact support.';
                return null;
            }
        }

        self::recordAttempt($email, true);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));

        $upd = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $upd->execute([$user['id']]);
        activity_log('auth.login', 'User signed in', $user['tenant_id'] ? (int)$user['tenant_id'] : null, (int)$user['id']);

        return $user;
    }

    public static function logout(): void
    {
        activity_log('auth.logout', 'User signed out');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }

    /* -------------------------------------------------- Tenant registration */

    /**
     * Register a new pharmacy (tenant) with its owner account and a free
     * trial subscription. Returns [tenantId, userId, verificationToken].
     * Everything runs in one transaction.
     */
    public static function registerTenant(array $data): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $slug = slugify($data['business_name']);
            $check = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE slug = ?');
            $check->execute([$slug]);
            if ((int)$check->fetchColumn() > 0) {
                $slug .= '-' . bin2hex(random_bytes(2));
            }

            $trialDays = (int)($GLOBALS['app_config']['trial_days'] ?? 14);
            $stmt = $pdo->prepare(
                'INSERT INTO tenants (name, slug, email, phone, license_number, address, city, country, status, trial_ends_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active", DATE_ADD(NOW(), INTERVAL ' . $trialDays . ' DAY), NOW(), NOW())'
            );
            $stmt->execute([
                $data['business_name'], $slug, $data['email'], $data['phone'] ?? null,
                $data['license_number'] ?? null, $data['address'] ?? null,
                $data['city'] ?? null, $data['country'] ?? null,
            ]);
            $tenantId = (int)$pdo->lastInsertId();

            // Default tenant settings (branding + preferences).
            $settings = $pdo->prepare(
                'INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
            );
            foreach ([
                'brand_color' => '#059669', 'currency' => 'USD', 'tax_rate' => '0',
                'receipt_footer' => 'Thank you for your purchase!', 'low_stock_threshold' => '10',
                'expiry_alert_days' => '90', 'allow_negative_stock' => '0',
            ] as $k => $v) {
                $settings->execute([$tenantId, $k, $v]);
            }

            // Owner user.
            $role = $pdo->prepare("SELECT id FROM roles WHERE `key` = 'owner' LIMIT 1");
            $role->execute();
            $token = bin2hex(random_bytes(32));
            $user = $pdo->prepare(
                'INSERT INTO users (tenant_id, role_id, name, email, password_hash, phone, status, email_verification_token, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, "active", ?, NOW(), NOW())'
            );
            $user->execute([
                $tenantId, (int)$role->fetchColumn(), $data['owner_name'], strtolower($data['email']),
                password_hash($data['password'], PASSWORD_DEFAULT), $data['phone'] ?? null, $token,
            ]);
            $userId = (int)$pdo->lastInsertId();

            // Default branch.
            $branch = $pdo->prepare(
                'INSERT INTO branches (tenant_id, name, code, is_main, status, created_at, updated_at) VALUES (?, ?, "MAIN", 1, "active", NOW(), NOW())'
            );
            $branch->execute([$tenantId, $data['business_name'] . ' — Main Branch']);

            // Free trial subscription on the Trial plan.
            $plan = $pdo->prepare("SELECT id FROM subscription_plans WHERE `key` = 'trial' LIMIT 1");
            $plan->execute();
            $planId = (int)$plan->fetchColumn();
            $sub = $pdo->prepare(
                'INSERT INTO subscriptions (tenant_id, plan_id, billing_cycle, status, starts_at, ends_at, trial_ends_at, created_at, updated_at)
                 VALUES (?, ?, "monthly", "trial", NOW(), DATE_ADD(NOW(), INTERVAL ' . $trialDays . ' DAY), DATE_ADD(NOW(), INTERVAL ' . $trialDays . ' DAY), NOW(), NOW())'
            );
            $sub->execute([$tenantId, $planId]);
            $subId = (int)$pdo->lastInsertId();
            $usage = $pdo->prepare(
                'INSERT INTO subscription_usage (tenant_id, subscription_id, period_start, period_end, transactions_count, storage_used_mb, created_at, updated_at)
                 VALUES (?, ?, CURDATE(), LAST_DAY(CURDATE()), 0, 0, NOW(), NOW())'
            );
            $usage->execute([$tenantId, $subId]);

            $pdo->commit();
            activity_log('tenant.registered', 'Pharmacy registered: ' . $data['business_name'], $tenantId, $userId);
            return [$tenantId, $userId, $token];
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /* ------------------------------------------------------- Invitations */

    /** Invite an employee: creates an inactive user with an activation token. */
    public static function inviteEmployee(int $tenantId, string $name, string $email, int $roleId): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = db()->prepare(
            'INSERT INTO users (tenant_id, role_id, name, email, password_hash, status, invitation_token, invited_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "invited", ?, NOW(), NOW(), NOW())'
        );
        // Random unusable password hash until the invite is accepted.
        $stmt->execute([$tenantId, $roleId, $name, strtolower(trim($email)), password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $token]);
        activity_log('staff.invited', 'Invitation sent to ' . $email, $tenantId);
        return $token;
    }

    public static function acceptInvitation(string $token, string $password): bool
    {
        $stmt = db()->prepare('SELECT id FROM users WHERE invitation_token = ? AND status = "invited" LIMIT 1');
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            return false;
        }
        $upd = db()->prepare(
            'UPDATE users SET password_hash = ?, status = "active", invitation_token = NULL, email_verified_at = NOW(), updated_at = NOW() WHERE id = ?'
        );
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        return true;
    }

    /* --------------------------------------------------- Password resets */

    public static function createPasswordReset(string $email): ?string
    {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        if (!$stmt->fetchColumn()) {
            return null; // Do not leak whether the account exists.
        }
        $token = bin2hex(random_bytes(32));
        $ins = db()->prepare(
            'INSERT INTO password_resets (email, token, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())'
        );
        $ins->execute([strtolower(trim($email)), hash('sha256', $token)]);
        return $token;
    }

    public static function resetPassword(string $token, string $password): bool
    {
        $stmt = db()->prepare(
            'SELECT email FROM password_resets WHERE token = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $email = $stmt->fetchColumn();
        if (!$email) {
            return false;
        }
        $upd = db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE email = ?');
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), $email]);
        $mark = db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE token = ?');
        $mark->execute([hash('sha256', $token)]);
        return true;
    }

    public static function verifyEmail(string $token): bool
    {
        $stmt = db()->prepare(
            'UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL, updated_at = NOW()
              WHERE email_verification_token = ? AND email_verified_at IS NULL'
        );
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    }
}
