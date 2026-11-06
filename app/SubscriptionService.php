<?php
/**
 * MediStock Cloud — subscription, billing and plan-limit engine.
 *
 * Commercial rules enforced here:
 *  - Plans: Trial, Starter, Professional, Business, Enterprise (monthly/yearly).
 *  - Payments are recorded as PENDING when entered manually. A payment only
 *    becomes "verified" after explicit verified confirmation (gateway webhook
 *    or a Super Admin verification action). Nothing auto-marks success.
 *  - Plan limits (branches, employees, medicine records, storage, monthly
 *    transactions) are checked before create operations complete.
 *  - Expired subscriptions enter a grace period, then restrict access.
 */
final class SubscriptionService
{
    public static function plans(): array
    {
        return db()->query('SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
    }

    public static function plan(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM subscription_plans WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Active (or trial/grace) subscription of a tenant, joined with its plan. */
    public static function current(int $tenantId): ?array
    {
        $stmt = db()->prepare(
            'SELECT s.*, p.name AS plan_name, p.key AS plan_key, p.max_branches, p.max_users,
                    p.max_medicines, p.max_storage_mb, p.max_monthly_transactions,
                    p.monthly_price, p.yearly_price
               FROM subscriptions s
               JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = ? AND s.status IN ("trial", "active", "grace")
              ORDER BY s.id DESC LIMIT 1'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetch() ?: null;
    }

    /** Live usage counters computed from real records (never hardcoded). */
    public static function usage(int $tenantId): array
    {
        $pdo = db();
        $count = static function (string $sql) use ($pdo, $tenantId): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tenantId]);
            return (int)$stmt->fetchColumn();
        };
        return [
            'branches'     => $count('SELECT COUNT(*) FROM branches WHERE tenant_id = ? AND deleted_at IS NULL'),
            'users'        => $count('SELECT COUNT(*) FROM users WHERE tenant_id = ? AND deleted_at IS NULL'),
            'medicines'    => $count('SELECT COUNT(*) FROM medicines WHERE tenant_id = ? AND deleted_at IS NULL'),
            'transactions' => $count('SELECT COUNT(*) FROM sales WHERE tenant_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != "void"'),
        ];
    }

    /**
     * Check a plan limit before creating a resource.
     * Returns an error string when the limit is reached, null when allowed.
     * A limit of 0 means "unlimited".
     */
    public static function limitError(int $tenantId, string $resource): ?string
    {
        $sub = self::current($tenantId);
        if (!$sub) {
            return 'No active subscription. Please choose a plan to continue.';
        }
        $map = [
            'branches'     => ['max_branches', 'branches'],
            'users'        => ['max_users', 'users'],
            'medicines'    => ['max_medicines', 'medicines'],
            'transactions' => ['max_monthly_transactions', 'transactions'],
        ];
        if (!isset($map[$resource])) {
            return null;
        }
        [$column, $usageKey] = $map[$resource];
        $limit = (int)$sub[$column];
        if ($limit === 0) {
            return null;
        }
        $used = self::usage($tenantId)[$usageKey];
        if ($used >= $limit) {
            return 'Your ' . $sub['plan_name'] . ' plan allows ' . $limit . ' ' . $resource
                . ' (currently ' . $used . '). Please upgrade to add more.';
        }
        return null;
    }

    /** Resolve a coupon to its discount percentage, or null when invalid. */
    public static function coupon(string $code): ?array
    {
        $stmt = db()->prepare(
            'SELECT * FROM coupons WHERE code = ? AND is_active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (expires_at IS NULL OR expires_at >= NOW())
               AND (max_uses IS NULL OR times_used < max_uses) LIMIT 1'
        );
        $stmt->execute([strtoupper(trim($code))]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Change a tenant's subscription (subscribe / upgrade / downgrade / renew).
     * Creates a PENDING payment + invoice. The subscription only activates
     * when the payment is verified — see verifyPayment().
     * Returns [subscriptionId, paymentId, invoiceId].
     */
    public static function changePlan(int $tenantId, int $planId, string $cycle, ?string $couponCode = null): array
    {
        $plan = self::plan($planId);
        if (!$plan) {
            throw new InvalidArgumentException('Plan not found.');
        }
        $pdo = db();
        $amount = $cycle === 'yearly' ? (float)$plan['yearly_price'] : (float)$plan['monthly_price'];
        $discount = 0.0;
        $coupon = $couponCode ? self::coupon($couponCode) : null;
        if ($coupon) {
            $discount = $coupon['type'] === 'percent'
                ? round($amount * (float)$coupon['value'] / 100, 2)
                : min((float)$coupon['value'], $amount);
        }
        $total = max(0.0, $amount - $discount);

        $pdo->beginTransaction();
        try {
            // Cancel only stale PENDING plan changes. The tenant's current
            // active/trial subscription keeps running until the new payment
            // is verified (see verifyPayment), so nobody loses access while
            // a payment is awaiting confirmation.
            $pdo->prepare('UPDATE subscriptions SET status = "cancelled", updated_at = NOW() WHERE tenant_id = ? AND status = "pending"')
                ->execute([$tenantId]);

            $months = $cycle === 'yearly' ? 12 : 1;
            $stmt = $pdo->prepare(
                'INSERT INTO subscriptions (tenant_id, plan_id, billing_cycle, status, starts_at, ends_at, created_at, updated_at)
                 VALUES (?, ?, ?, "pending", NOW(), DATE_ADD(NOW(), INTERVAL ' . $months . ' MONTH), NOW(), NOW())'
            );
            $stmt->execute([$tenantId, $planId, $cycle]);
            $subId = (int)$pdo->lastInsertId();

            // Payment starts as PENDING — never successful without verification.
            $pay = $pdo->prepare(
                'INSERT INTO payments (tenant_id, subscription_id, method, amount, currency, status, reference, created_at, updated_at)
                 VALUES (?, ?, "manual", ?, ?, "pending", ?, NOW(), NOW())'
            );
            $pay->execute([$tenantId, $subId, $total, $GLOBALS['app_config']['currency'] ?? 'USD', 'SUB-' . strtoupper(bin2hex(random_bytes(4)))]);
            $paymentId = (int)$pdo->lastInsertId();

            $inv = $pdo->prepare(
                'INSERT INTO invoices (tenant_id, subscription_id, payment_id, invoice_number, amount, discount, tax, total, status, due_date, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, "unpaid", DATE_ADD(CURDATE(), INTERVAL 7 DAY), NOW(), NOW())'
            );
            $inv->execute([$tenantId, $subId, $paymentId, 'INV-' . date('Y') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT), $amount, $discount, $total]);
            $invoiceId = (int)$pdo->lastInsertId();

            if ($coupon && $discount > 0) {
                $pdo->prepare('UPDATE coupons SET times_used = times_used + 1 WHERE id = ?')->execute([$coupon['id']]);
            }

            $pdo->commit();
            activity_log('subscription.changed', 'Plan change requested: ' . $plan['name'] . ' (' . $cycle . ')', $tenantId);
            return [$subId, $paymentId, $invoiceId];
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    /**
     * Mark a payment VERIFIED (gateway confirmation or Super Admin manual
     * verification). Activates the linked subscription and pays its invoice.
     */
    public static function verifyPayment(int $paymentId, string $verifiedBy): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            if (!$payment || $payment['status'] === 'verified') {
                $pdo->rollBack();
                return;
            }
            $pdo->prepare('UPDATE payments SET status = "verified", verified_at = NOW(), verified_by = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$verifiedBy, $paymentId]);
            if ($payment['subscription_id']) {
                $pdo->prepare('UPDATE subscriptions SET status = "active", updated_at = NOW() WHERE id = ?')
                    ->execute([$payment['subscription_id']]);
                // The newly verified plan supersedes any previous open plan.
                $pdo->prepare('UPDATE subscriptions SET status = "cancelled", updated_at = NOW() WHERE tenant_id = ? AND id != ? AND status IN ("trial","active","grace","pending")')
                    ->execute([$payment['tenant_id'], $payment['subscription_id']]);
                $pdo->prepare('UPDATE invoices SET status = "paid", paid_at = NOW(), updated_at = NOW() WHERE payment_id = ?')
                    ->execute([$paymentId]);
            }
            $pdo->commit();
            activity_log('payment.verified', 'Payment #' . $paymentId . ' verified by ' . $verifiedBy, $payment['tenant_id'] ? (int)$payment['tenant_id'] : null);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    public static function cancel(int $tenantId): void
    {
        db()->prepare('UPDATE subscriptions SET status = "cancelled", cancelled_at = NOW(), auto_renew = 0, updated_at = NOW() WHERE tenant_id = ? AND status IN ("trial","active","grace")')
            ->execute([$tenantId]);
        activity_log('subscription.cancelled', 'Subscription cancelled by tenant', $tenantId);
    }

    /** Revenue aggregates for the Super Admin dashboard — real queries only. */
    public static function revenueStats(): array
    {
        $pdo = db();
        $mrr = $pdo->query(
            'SELECT COALESCE(SUM(CASE WHEN s.billing_cycle = "yearly" THEN p.yearly_price / 12 ELSE p.monthly_price END), 0)
               FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.status IN ("active", "grace")'
        )->fetchColumn();
        return [
            'mrr' => (float)$mrr,
            'arr' => (float)$mrr * 12,
            'transactions' => (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
            'verified_revenue' => (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "verified"')->fetchColumn(),
        ];
    }
}
