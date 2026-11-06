# MediStock Cloud — Security Notes

This document describes the security controls implemented in the codebase
and the operational duties that remain with the deployer.

## Authentication & sessions
- Passwords are hashed with `password_hash()` (bcrypt via `PASSWORD_DEFAULT`)
  and verified with `password_verify()`. Plaintext passwords are never stored.
- **Login rate limiting:** failed attempts are recorded in `login_attempts`;
  5 failures for the same email + IP within 15 minutes locks further attempts.
- Sessions use a custom cookie name with `HttpOnly`, `SameSite=Lax`, and
  `Secure` (when HTTPS) flags. The session ID is regenerated at login.
- Password resets use single-use, expiring (60 min) tokens; only the SHA-256
  hash of the token is stored (`password_resets`).
- Employee invitations and email verification use random 256-bit tokens.

## Authorization & multi-tenancy
- Every business table carries `tenant_id`. Tenant pages resolve the tenant
  from the server-side session and scope **every** query with it; record IDs
  from the request are always re-checked against the tenant (see `app/Crud.php`
  and module pages), so guessing another tenant's ID returns "not found".
- Role-based access control: roles hold permissions via `role_permissions`;
  pages call `require_permission()` / `can()`. Super Admin and Pharmacy Owner
  bypass permission checks only within their own scope.
- Suspended tenants are blocked at login and at page level (`require_tenant()`).

## Input & output safety
- All SQL uses PDO prepared statements with `PDO::ATTR_EMULATE_PREPARES = false`.
- All dynamic values are validated server-side (`validate()` helper) and all
  output is escaped with `e()` (htmlspecialchars, UTF-8, ENT_QUOTES).
- Every state-changing form includes a CSRF token verified with `hash_equals()`.
- File uploads (tenant logo) are validated by MIME type and size, stored with
  a randomised filename. Document/medical files should additionally be stored
  outside the web root in production (see DEPLOYMENT.md).

## Payments integrity
- Manually recorded payments are created with status `pending` and can only
  become `verified` through confirmed receipt: a payment-gateway confirmation
  or an explicit Super Admin verification (`SubscriptionService::verifyPayment()`).
  Nothing in the codebase auto-marks a payment successful.

## Inventory integrity
- Sales, purchases, returns and adjustments run inside DB transactions and
  write to the append-only `stock_movements` ledger.
- Batch rows are locked with `SELECT … FOR UPDATE` during sales so two tills
  cannot oversell the same batch. Expired batches cannot be sold; negative
  stock requires an explicit tenant policy setting and is audit-logged.

## Auditability
- `activity_logs` (business timeline) and `audit_logs` (before/after JSON for
  CRUD changes, security events) are written by helpers throughout the app.

## Deployer checklist
1. Use HTTPS everywhere; set `APP_DEBUG=false`; delete/protect
   `database/install.php` after installation (`APP_INSTALLED=1`).
2. Create a least-privilege MySQL user (no SUPER/DROP on other schemas).
3. Keep `.env` out of version control; rotate any demo passwords
   (`super@medistock.test`, `*@demo.com`) immediately after evaluation.
4. Schedule database backups (see DEPLOYMENT.md) and test restores.
5. Keep PHP and MySQL patched; enable a WAF/rate limiting at the edge.
