-- ═══════════════════════════════════════════════════════════════════════════
-- MediStock Cloud — Pharmacy Management SaaS
-- Complete MySQL schema (normalized, tenant-aware, transaction-safe)
-- Engine: InnoDB · Charset: utf8mb4 · Requires MySQL 5.7+ / MariaDB 10.3+
--
-- Install:
--   CREATE DATABASE medistock_cloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   mysql -u root -p medistock_cloud < database/database.sql
-- ═══════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────── PLATFORM ──

CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`       VARCHAR(50)  NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`       VARCHAR(80)  NOT NULL UNIQUE,
    name        VARCHAR(120) NOT NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenants (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(160) NOT NULL,
    slug           VARCHAR(180) NOT NULL UNIQUE,
    email          VARCHAR(190) NOT NULL,
    phone          VARCHAR(40)  NULL,
    license_number VARCHAR(80)  NULL,
    address        VARCHAR(255) NULL,
    city           VARCHAR(100) NULL,
    country        VARCHAR(100) NULL,
    logo_path      VARCHAR(255) NULL,
    status         ENUM('pending','active','suspended') NOT NULL DEFAULT 'active',
    trial_ends_at  DATETIME NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    deleted_at     TIMESTAMP NULL,
    INDEX idx_tenants_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                 INT UNSIGNED NULL,          -- NULL = platform staff (Super Admin)
    role_id                   INT UNSIGNED NOT NULL,
    name                      VARCHAR(120) NOT NULL,
    email                     VARCHAR(190) NOT NULL UNIQUE,
    password_hash             VARCHAR(255) NOT NULL,
    phone                     VARCHAR(40)  NULL,
    status                    ENUM('active','inactive','invited') NOT NULL DEFAULT 'active',
    email_verified_at         DATETIME NULL,
    email_verification_token  VARCHAR(100) NULL,
    invitation_token          VARCHAR(100) NULL,
    invited_at                DATETIME NULL,
    last_login_at             DATETIME NULL,
    created_at                TIMESTAMP NULL,
    updated_at                TIMESTAMP NULL,
    deleted_at                TIMESTAMP NULL,
    INDEX idx_users_tenant (tenant_id),
    CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_users_role   FOREIGN KEY (role_id)   REFERENCES roles (id)   ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(190) NOT NULL,
    ip_address   VARCHAR(45)  NULL,
    success      TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL,
    INDEX idx_login_attempts (email, ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NOT NULL,
    token      VARCHAR(64)  NOT NULL,             -- SHA-256 hash of the emailed token
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_password_resets (email, token)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_settings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    setting_key   VARCHAR(80)  NOT NULL,
    setting_value TEXT NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_setting (tenant_id, setting_key),
    CONSTRAINT fk_ts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_settings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(80) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────── SUBSCRIPTIONS ──

CREATE TABLE IF NOT EXISTS subscription_plans (
    id                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`                      VARCHAR(50)  NOT NULL UNIQUE,
    name                       VARCHAR(100) NOT NULL,
    description                VARCHAR(255) NULL,
    monthly_price              DECIMAL(10,2) NOT NULL DEFAULT 0,
    yearly_price               DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_branches               INT NOT NULL DEFAULT 0,   -- 0 = unlimited
    max_users                  INT NOT NULL DEFAULT 0,
    max_medicines              INT NOT NULL DEFAULT 0,
    max_storage_mb             INT NOT NULL DEFAULT 0,
    max_monthly_transactions   INT NOT NULL DEFAULT 0,
    features                   TEXT NULL,
    sort_order                 INT NOT NULL DEFAULT 0,
    is_active                  TINYINT(1) NOT NULL DEFAULT 1,
    created_at                 TIMESTAMP NULL,
    updated_at                 TIMESTAMP NULL,
    deleted_at                 TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    plan_id       INT UNSIGNED NOT NULL,
    billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    status        ENUM('trial','pending','active','grace','expired','cancelled') NOT NULL DEFAULT 'trial',
    starts_at     DATETIME NULL,
    ends_at       DATETIME NULL,
    trial_ends_at DATETIME NULL,
    cancelled_at  DATETIME NULL,
    auto_renew    TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    INDEX idx_subscriptions_tenant (tenant_id, status),
    CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_plan   FOREIGN KEY (plan_id)   REFERENCES subscription_plans (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_usage (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    subscription_id     INT UNSIGNED NULL,
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    transactions_count  INT NOT NULL DEFAULT 0,
    storage_used_mb     DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL,
    UNIQUE KEY uq_usage_period (tenant_id, period_start),
    CONSTRAINT fk_usage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_usage_sub    FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coupons (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(50) NOT NULL UNIQUE,
    type       ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    value      DECIMAL(10,2) NOT NULL,
    max_uses   INT NULL,
    times_used INT NOT NULL DEFAULT 0,
    starts_at  DATETIME NULL,
    expires_at DATETIME NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NULL,
    subscription_id INT UNSIGNED NULL,
    method          ENUM('manual','card','bank','cash','mobile','gateway') NOT NULL DEFAULT 'manual',
    amount          DECIMAL(12,2) NOT NULL,
    currency        VARCHAR(3) NOT NULL DEFAULT 'USD',
    -- A payment is only ever 'verified' after verified confirmation
    -- (gateway webhook or Super Admin verification). Never automatic.
    status          ENUM('pending','verified','failed','refunded') NOT NULL DEFAULT 'pending',
    reference       VARCHAR(100) NULL,
    verified_at     DATETIME NULL,
    verified_by     VARCHAR(120) NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    INDEX idx_payments_tenant (tenant_id, status),
    CONSTRAINT fk_pay_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_sub    FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    subscription_id INT UNSIGNED NULL,
    payment_id      INT UNSIGNED NULL,
    invoice_number  VARCHAR(40) NOT NULL UNIQUE,
    amount          DECIMAL(12,2) NOT NULL,
    discount        DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax             DECIMAL(12,2) NOT NULL DEFAULT 0,
    total           DECIMAL(12,2) NOT NULL,
    status          ENUM('unpaid','paid','void') NOT NULL DEFAULT 'unpaid',
    due_date        DATE NULL,
    paid_at         DATETIME NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    CONSTRAINT fk_inv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_sub    FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL,
    CONSTRAINT fk_inv_pay    FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────── PLATFORM CONTENT ──

CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT UNSIGNED NULL,
    user_id    INT UNSIGNED NULL,             -- NULL = whole tenant
    type       VARCHAR(40) NOT NULL DEFAULT 'info',
    title      VARCHAR(200) NOT NULL,
    body       TEXT NULL,
    read_at    DATETIME NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_notifications (tenant_id, user_id, read_at),
    CONSTRAINT fk_notif_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_tickets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT UNSIGNED NULL,
    user_id    INT UNSIGNED NULL,
    subject    VARCHAR(200) NOT NULL,
    message    TEXT NOT NULL,
    priority   ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status     ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_ticket_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_ticket_replies (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    CONSTRAINT fk_reply_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_reply_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(200) NOT NULL,
    body         TEXT NOT NULL,
    audience     ENUM('all','trial','paid') NOT NULL DEFAULT 'all',
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    deleted_at   TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NULL,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  TIMESTAMP NULL,
    INDEX idx_activity (tenant_id, created_at),
    CONSTRAINT fk_act_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL,
    CONSTRAINT fk_act_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NULL,
    user_id     INT UNSIGNED NULL,
    entity      VARCHAR(80) NOT NULL,
    entity_id   INT UNSIGNED NULL,
    action      VARCHAR(40) NOT NULL,
    old_values  JSON NULL,
    new_values  JSON NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  TIMESTAMP NULL,
    INDEX idx_audit (tenant_id, entity, created_at),
    CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(190) NOT NULL,
    subject    VARCHAR(200) NULL,
    message    TEXT NOT NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NOT NULL UNIQUE,
    created_at TIMESTAMP NULL
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────── PHARMACY ────

CREATE TABLE IF NOT EXISTS branches (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  INT UNSIGNED NOT NULL,
    name       VARCHAR(140) NOT NULL,
    code       VARCHAR(30)  NOT NULL,
    phone      VARCHAR(40)  NULL,
    email      VARCHAR(190) NULL,
    address    VARCHAR(255) NULL,
    city       VARCHAR(100) NULL,
    is_main    TINYINT(1) NOT NULL DEFAULT 0,
    status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_branch_code (tenant_id, code),
    CONSTRAINT fk_branch_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    deleted_at  TIMESTAMP NULL,
    UNIQUE KEY uq_category (tenant_id, name),
    CONSTRAINT fk_cat_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS brands (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    name         VARCHAR(120) NOT NULL,
    manufacturer VARCHAR(140) NULL,
    country      VARCHAR(100) NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    deleted_at   TIMESTAMP NULL,
    UNIQUE KEY uq_brand (tenant_id, name),
    CONSTRAINT fk_brand_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medicines (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    category_id           INT UNSIGNED NULL,
    brand_id              INT UNSIGNED NULL,
    name                  VARCHAR(180) NOT NULL,
    generic_name          VARCHAR(180) NULL,
    sku                   VARCHAR(60) NULL,
    barcode               VARCHAR(60) NULL,
    strength              VARCHAR(60) NULL,
    dosage_form           VARCHAR(60) NULL,
    unit                  VARCHAR(30) NOT NULL DEFAULT 'tablet',
    rack_location         VARCHAR(60) NULL,
    photo_path            VARCHAR(255) NULL,
    manufacturer          VARCHAR(140) NULL,
    default_supplier_id   INT UNSIGNED NULL,
    default_purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    default_selling_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
    status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
    reorder_level         INT NOT NULL DEFAULT 10,
    requires_prescription TINYINT(1) NOT NULL DEFAULT 0,
    description           TEXT NULL,
    created_at            TIMESTAMP NULL,
    updated_at            TIMESTAMP NULL,
    deleted_at            TIMESTAMP NULL,
    INDEX idx_med_tenant (tenant_id, name),
    INDEX idx_med_barcode (tenant_id, barcode),
    CONSTRAINT fk_med_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_med_cat    FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_med_brand  FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL,
    CONSTRAINT fk_med_supplier FOREIGN KEY (default_supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suppliers (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT UNSIGNED NOT NULL,
    name           VARCHAR(160) NOT NULL,
    contact_person VARCHAR(120) NULL,
    email          VARCHAR(190) NULL,
    phone          VARCHAR(40)  NULL,
    address        VARCHAR(255) NULL,
    payment_terms  VARCHAR(80)  NULL,
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    deleted_at     TIMESTAMP NULL,
    INDEX idx_sup_tenant (tenant_id, name),
    CONSTRAINT fk_sup_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS batches (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    branch_id          INT UNSIGNED NOT NULL,
    medicine_id        INT UNSIGNED NOT NULL,
    supplier_id        INT UNSIGNED NULL,
    batch_number       VARCHAR(60) NOT NULL,
    manufacturing_date DATE NULL,
    expiry_date        DATE NOT NULL,
    purchase_price     DECIMAL(10,2) NOT NULL DEFAULT 0,
    selling_price      DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantity           INT NOT NULL DEFAULT 0,
    quantity_received  INT NOT NULL DEFAULT 0,
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    deleted_at         TIMESTAMP NULL,
    UNIQUE KEY uq_batch (tenant_id, branch_id, medicine_id, batch_number, expiry_date),
    INDEX idx_batch_expiry (tenant_id, expiry_date),
    INDEX idx_batch_fefo (tenant_id, medicine_id, expiry_date),
    CONSTRAINT fk_batch_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)   ON DELETE CASCADE,
    CONSTRAINT fk_batch_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_batch_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_batch_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    name          VARCHAR(140) NOT NULL,
    phone         VARCHAR(40)  NULL,
    email         VARCHAR(190) NULL,
    gender        ENUM('male','female','other') NULL,
    date_of_birth DATE NULL,
    address       VARCHAR(255) NULL,
    notes         TEXT NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    deleted_at    TIMESTAMP NULL,
    INDEX idx_cust_tenant (tenant_id, name),
    CONSTRAINT fk_cust_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescriptions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    customer_id         INT UNSIGNED NULL,
    prescription_number VARCHAR(60) NOT NULL,
    patient_name        VARCHAR(140) NULL,
    doctor_name         VARCHAR(140) NOT NULL,
    hospital            VARCHAR(160) NULL,
    prescribed_at       DATE NOT NULL,
    status              ENUM('pending','dispensed','partially_dispensed','cancelled') NOT NULL DEFAULT 'pending',
    dispensed_by        INT UNSIGNED NULL,
    dispensed_at        DATETIME NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL,
    deleted_at          TIMESTAMP NULL,
    UNIQUE KEY uq_rx_number (tenant_id, prescription_number),
    CONSTRAINT fk_rx_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)    ON DELETE CASCADE,
    CONSTRAINT fk_rx_customer FOREIGN KEY (customer_id) REFERENCES customers (id)  ON DELETE SET NULL,
    CONSTRAINT fk_rx_dispensed_by FOREIGN KEY (dispensed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescription_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT UNSIGNED NOT NULL,
    medicine_id     INT UNSIGNED NULL,
    medicine_name   VARCHAR(180) NOT NULL,
    dosage          VARCHAR(80) NULL,
    quantity        INT NOT NULL DEFAULT 1,
    instructions    VARCHAR(255) NULL,
    CONSTRAINT fk_rxi_rx       FOREIGN KEY (prescription_id) REFERENCES prescriptions (id) ON DELETE CASCADE,
    CONSTRAINT fk_rxi_medicine FOREIGN KEY (medicine_id)     REFERENCES medicines (id)     ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchases (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT UNSIGNED NOT NULL,
    branch_id         INT UNSIGNED NOT NULL,
    supplier_id       INT UNSIGNED NOT NULL,
    invoice_reference VARCHAR(80) NULL,
    total_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    status            ENUM('received','cancelled') NOT NULL DEFAULT 'received',
    purchased_by      INT UNSIGNED NULL,
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,
    INDEX idx_pur_tenant (tenant_id, created_at),
    CONSTRAINT fk_pur_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)   ON DELETE CASCADE,
    CONSTRAINT fk_pur_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_pur_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pur_user     FOREIGN KEY (purchased_by) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_items (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id    INT UNSIGNED NOT NULL,
    medicine_id    INT UNSIGNED NOT NULL,
    batch_id       INT UNSIGNED NULL,
    quantity       INT NOT NULL,
    purchase_price DECIMAL(10,2) NOT NULL,
    total          DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_pi_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT fk_pi_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pi_batch    FOREIGN KEY (batch_id)    REFERENCES batches (id)   ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    branch_id       INT UNSIGNED NOT NULL,
    customer_id     INT UNSIGNED NULL,
    prescription_id INT UNSIGNED NULL,
    invoice_number  VARCHAR(40) NOT NULL UNIQUE,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount        DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax             DECIMAL(12,2) NOT NULL DEFAULT 0,
    grand_total     DECIMAL(12,2) NOT NULL DEFAULT 0,
    status          ENUM('completed','void') NOT NULL DEFAULT 'completed',
    sold_by         INT UNSIGNED NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    INDEX idx_sales_tenant (tenant_id, created_at),
    CONSTRAINT fk_sale_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_sale_rx FOREIGN KEY (prescription_id) REFERENCES prescriptions (id) ON DELETE SET NULL,
    CONSTRAINT fk_sale_user FOREIGN KEY (sold_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id     INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    batch_id    INT UNSIGNED NULL,
    quantity    INT NOT NULL,
    unit_price  DECIMAL(10,2) NOT NULL,
    discount    DECIMAL(10,2) NOT NULL DEFAULT 0,
    total       DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT fk_si_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_si_batch FOREIGN KEY (batch_id) REFERENCES batches (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_payments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id    INT UNSIGNED NOT NULL,
    method     ENUM('cash','card','mobile','bank','split') NOT NULL DEFAULT 'cash',
    amount     DECIMAL(12,2) NOT NULL,
    reference  VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    CONSTRAINT fk_sp_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS returns (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    branch_id     INT UNSIGNED NOT NULL,
    sale_id       INT UNSIGNED NOT NULL,
    refund_method ENUM('cash','card','mobile','bank') NOT NULL DEFAULT 'cash',
    refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    reason        VARCHAR(255) NULL,
    status        ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    CONSTRAINT fk_ret_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_ret_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ret_sale   FOREIGN KEY (sale_id)   REFERENCES sales (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_ret_user   FOREIGN KEY (created_by) REFERENCES users (id)  ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS return_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id   INT UNSIGNED NOT NULL,
    sale_item_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    batch_id    INT UNSIGNED NULL,
    quantity    INT NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_ri_return FOREIGN KEY (return_id) REFERENCES returns (id) ON DELETE CASCADE,
    CONSTRAINT fk_ri_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ri_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ri_batch FOREIGN KEY (batch_id) REFERENCES batches (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT UNSIGNED NOT NULL,
    branch_id      INT UNSIGNED NULL,
    title          VARCHAR(160) NOT NULL,
    category       VARCHAR(80) NULL,
    amount         DECIMAL(12,2) NOT NULL,
    expense_date   DATE NOT NULL,
    payment_method ENUM('cash','card','bank') NOT NULL DEFAULT 'cash',
    notes          TEXT NULL,
    created_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    deleted_at     TIMESTAMP NULL,
    INDEX idx_exp_tenant (tenant_id, expense_date),
    CONSTRAINT fk_exp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_user   FOREIGN KEY (created_by) REFERENCES users (id)  ON DELETE SET NULL
) ENGINE=InnoDB;

-- Append-only inventory ledger. Every sale / purchase / return / adjustment
-- writes here inside the same database transaction as the stock change.
CREATE TABLE IF NOT EXISTS stock_movements (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT UNSIGNED NOT NULL,
    branch_id      INT UNSIGNED NULL,
    medicine_id    INT UNSIGNED NOT NULL,
    batch_id       INT UNSIGNED NULL,
    type           ENUM('opening','purchase','sale','return','adjustment','transfer') NOT NULL,
    quantity       INT NOT NULL,               -- signed: + in, − out
    balance_after  INT NOT NULL,               -- batch balance after the movement
    reference_type VARCHAR(40) NULL,           -- sale | purchase | return | adjustment | batch
    reference_id   INT UNSIGNED NULL,
    note           VARCHAR(255) NULL,
    created_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP NULL,
    INDEX idx_mov_tenant (tenant_id, created_at),
    INDEX idx_mov_medicine (tenant_id, medicine_id),
    INDEX idx_mov_batch (batch_id),
    CONSTRAINT fk_mov_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)   ON DELETE CASCADE,
    CONSTRAINT fk_mov_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id)  ON DELETE SET NULL,
    CONSTRAINT fk_mov_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_batch    FOREIGN KEY (batch_id)    REFERENCES batches (id)   ON DELETE SET NULL,
    CONSTRAINT fk_mov_user     FOREIGN KEY (created_by)  REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─────────────────────────── UPGRADE TABLES (migrations 002–004) ─────────
-- Purchase orders (partial deliveries), purchase returns, supplier
-- payments (derived outstanding balances) and inter-branch transfers.
-- Split POS payments need no table: sale_payments stores one row per
-- payment method/amount, so a split sale is simply multiple rows.

CREATE TABLE IF NOT EXISTS purchase_orders (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    branch_id     INT UNSIGNED NOT NULL,
    supplier_id   INT UNSIGNED NOT NULL,
    order_number  VARCHAR(40) NOT NULL,
    status        ENUM('draft','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'ordered',
    expected_date DATE NULL,
    notes         VARCHAR(255) NULL,
    total_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    ordered_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    deleted_at    TIMESTAMP NULL,
    UNIQUE KEY uq_po_number (tenant_id, order_number),
    INDEX idx_po_tenant (tenant_id, status, created_at),
    CONSTRAINT fk_po_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)   ON DELETE CASCADE,
    CONSTRAINT fk_po_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_user     FOREIGN KEY (ordered_by)  REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    medicine_id       INT UNSIGNED NOT NULL,
    quantity_ordered  INT NOT NULL,
    quantity_received INT NOT NULL DEFAULT 0,
    purchase_price    DECIMAL(10,2) NOT NULL DEFAULT 0,
    total             DECIMAL(12,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_poi_order    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_medicine FOREIGN KEY (medicine_id)       REFERENCES medicines (id)       ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_returns (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    branch_id   INT UNSIGNED NOT NULL,
    purchase_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    reason      VARCHAR(255) NULL,
    status      ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    INDEX idx_pret_tenant (tenant_id, created_at),
    CONSTRAINT fk_pret_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)   ON DELETE CASCADE,
    CONSTRAINT fk_pret_branch   FOREIGN KEY (branch_id)   REFERENCES branches (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_pret_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pret_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pret_user     FOREIGN KEY (created_by)  REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_return_items (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id INT UNSIGNED NOT NULL,
    purchase_item_id   INT UNSIGNED NOT NULL,
    medicine_id        INT UNSIGNED NOT NULL,
    batch_id           INT UNSIGNED NULL,
    quantity           INT NOT NULL,
    amount             DECIMAL(12,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_pri_return   FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns (id) ON DELETE CASCADE,
    CONSTRAINT fk_pri_pitem    FOREIGN KEY (purchase_item_id)   REFERENCES purchase_items (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_pri_medicine FOREIGN KEY (medicine_id)        REFERENCES medicines (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_pri_batch    FOREIGN KEY (batch_id)           REFERENCES batches (id)          ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_payments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    purchase_id INT UNSIGNED NULL,
    amount      DECIMAL(12,2) NOT NULL,
    method      ENUM('cash','card','bank','mobile') NOT NULL DEFAULT 'bank',
    reference   VARCHAR(100) NULL,
    paid_at     DATE NOT NULL,
    notes       VARCHAR(255) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    INDEX idx_spay_tenant (tenant_id, supplier_id, paid_at),
    CONSTRAINT fk_spay_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)   ON DELETE CASCADE,
    CONSTRAINT fk_spay_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_spay_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE SET NULL,
    CONSTRAINT fk_spay_user     FOREIGN KEY (created_by)  REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_transfers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    from_branch_id  INT UNSIGNED NOT NULL,
    to_branch_id    INT UNSIGNED NOT NULL,
    transfer_number VARCHAR(40) NOT NULL,
    status          ENUM('pending','in_transit','completed','cancelled') NOT NULL DEFAULT 'pending',
    notes           VARCHAR(255) NULL,
    requested_by    INT UNSIGNED NULL,
    completed_by    INT UNSIGNED NULL,
    completed_at    DATETIME NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    UNIQUE KEY uq_transfer_number (tenant_id, transfer_number),
    INDEX idx_transfer_tenant (tenant_id, status, created_at),
    CONSTRAINT fk_tr_tenant FOREIGN KEY (tenant_id)      REFERENCES tenants (id)  ON DELETE CASCADE,
    CONSTRAINT fk_tr_from   FOREIGN KEY (from_branch_id) REFERENCES branches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tr_to     FOREIGN KEY (to_branch_id)   REFERENCES branches (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tr_req    FOREIGN KEY (requested_by)   REFERENCES users (id)    ON DELETE SET NULL,
    CONSTRAINT fk_tr_done   FOREIGN KEY (completed_by)   REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    batch_id    INT UNSIGNED NULL,
    quantity    INT NOT NULL,
    CONSTRAINT fk_tri_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE,
    CONSTRAINT fk_tri_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id)       ON DELETE RESTRICT,
    CONSTRAINT fk_tri_batch    FOREIGN KEY (batch_id)    REFERENCES batches (id)         ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════════
-- SEED DATA — plans, roles, permissions, demo pharmacy & sample stock
-- All demo users share the password:  password
-- (bcrypt hash below; change every demo password before real production use)
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO roles (id, `key`, name, description, created_at, updated_at) VALUES
(1, 'super_admin',       'Super Admin',       'Platform owner — full access to the Super Admin dashboard', NOW(), NOW()),
(2, 'owner',             'Pharmacy Owner',    'Tenant owner — full access within the pharmacy', NOW(), NOW()),
(3, 'admin',             'Pharmacy Admin',    'Day-to-day administrator of the pharmacy', NOW(), NOW()),
(4, 'pharmacist',        'Pharmacist',        'Dispensing, prescriptions, POS and customers', NOW(), NOW()),
(5, 'cashier',           'Cashier',           'Point of sale, sales and returns', NOW(), NOW()),
(6, 'inventory_manager', 'Inventory Manager', 'Medicines, batches, purchases, suppliers and adjustments', NOW(), NOW()),
(7, 'accountant',        'Accountant',        'Sales, expenses, reports and billing', NOW(), NOW()),
(8, 'staff',             'Staff',             'Basic view access', NOW(), NOW());

INSERT INTO permissions (id, `key`, name, created_at, updated_at) VALUES
(1,  'dashboard.view',     'View dashboard',        NOW(), NOW()),
(2,  'pos.access',         'Access POS',            NOW(), NOW()),
(3,  'sales.view',         'View sales',            NOW(), NOW()),
(4,  'sales.manage',       'Manage sales & returns', NOW(), NOW()),
(5,  'medicines.view',     'View medicines',        NOW(), NOW()),
(6,  'medicines.manage',   'Manage medicines',      NOW(), NOW()),
(7,  'stock.view',         'View stock',            NOW(), NOW()),
(8,  'stock.adjust',       'Adjust stock',          NOW(), NOW()),
(9,  'purchases.manage',   'Manage purchases',      NOW(), NOW()),
(10, 'suppliers.manage',   'Manage suppliers',      NOW(), NOW()),
(11, 'customers.manage',   'Manage customers',      NOW(), NOW()),
(12, 'prescriptions.manage','Manage prescriptions', NOW(), NOW()),
(13, 'expenses.manage',    'Manage expenses',       NOW(), NOW()),
(14, 'staff.manage',       'Manage staff',          NOW(), NOW()),
(15, 'branches.manage',    'Manage branches',       NOW(), NOW()),
(16, 'reports.view',       'View reports',          NOW(), NOW()),
(17, 'billing.manage',     'Manage subscription & billing', NOW(), NOW()),
(18, 'settings.manage',    'Manage settings',       NOW(), NOW());

-- Pharmacy Admin: everything. Other roles: scoped subsets (owner & super
-- admin bypass permission checks in the application layer).
INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions;
INSERT INTO role_permissions (role_id, permission_id) VALUES
(4,1),(4,2),(4,3),(4,4),(4,5),(4,7),(4,11),(4,12),
(5,1),(5,2),(5,3),(5,4),(5,11),
(6,1),(6,5),(6,6),(6,7),(6,8),(6,9),(6,10),
(7,1),(7,3),(7,13),(7,16),(7,17),
(8,1),(8,5),(8,7);

INSERT INTO subscription_plans (id, `key`, name, description, monthly_price, yearly_price, max_branches, max_users, max_medicines, max_storage_mb, max_monthly_transactions, features, sort_order, is_active, created_at, updated_at) VALUES
(1, 'trial',        'Free Trial',   'Explore MediStock Cloud with your real stock data', 0.00,   0.00,    1,  3,  100,   100,   100,
 'Batch inventory & FEFO\nPOS (1 branch)\nExpiry & low-stock alerts\nEmail support', 1, 1, NOW(), NOW()),
(2, 'starter',      'Starter',      'For a single pharmacy getting organised', 19.00,  190.00,  1,  5,  500,   500,   1000,
 'Everything in Trial\nUp to 500 medicines\nPurchases & suppliers\nBasic reports', 2, 1, NOW(), NOW()),
(3, 'professional', 'Professional', 'For growing pharmacies that need full control', 49.00,  490.00,  3,  15, 5000,  2000,  5000,
 'Everything in Starter\nUp to 3 branches\nPrescriptions & customers\nAdvanced reports & analytics\nPriority support', 3, 1, NOW(), NOW()),
(4, 'business',     'Business',     'For chains and high-volume stores', 99.00,  990.00,  10, 40, 20000, 10000, 20000,
 'Everything in Professional\nUp to 10 branches\nMulti-branch reporting\nAPI access', 4, 1, NOW(), NOW()),
(5, 'enterprise',   'Enterprise',   'Unlimited scale with dedicated support', 249.00, 2490.00, 0,  0,  0,     50000, 0,
 'Everything in Business\nUnlimited branches, users & medicines\nDedicated account manager\nCustom onboarding', 5, 1, NOW(), NOW());

INSERT INTO coupons (code, type, value, max_uses, is_active, created_at, updated_at) VALUES
('WELCOME10', 'percent', 10.00, NULL, 1, NOW(), NOW());

INSERT INTO platform_settings (setting_key, setting_value, created_at, updated_at) VALUES
('platform_name', 'MediStock Cloud', NOW(), NOW()),
('support_email', 'support@medistockcloud.example', NOW(), NOW()),
('trial_days', '14', NOW(), NOW()),
('grace_days', '7', NOW(), NOW()),
('maintenance_mode', '0', NOW(), NOW()),
('require_tenant_approval', '0', NOW(), NOW()),
('default_currency', 'USD', NOW(), NOW());

INSERT INTO announcements (title, body, audience, is_published, published_at, created_at, updated_at) VALUES
('Welcome to MediStock Cloud', 'Batch-level inventory, FEFO selling and expiry protection are live. Check the Expiry Management screen to see your 90-day radar.', 'all', 1, NOW(), NOW(), NOW());

-- ── Demo tenants ────────────────────────────────────────────────────────────
INSERT INTO tenants (id, name, slug, email, phone, license_number, address, city, country, status, trial_ends_at, created_at, updated_at) VALUES
(1, 'Demo Pharmacy',    'demo-pharmacy', 'hello@demo-pharmacy.example', '+1 555 010 7788', 'PH-DEMO-001', '14 Health Street', 'Springfield', 'USA', 'active', NULL, NOW(), NOW()),
(2, 'CityCare Pharmacy','citycare',      'team@citycare.example',       '+1 555 014 2200', 'PH-CC-2041',  '9 Wellness Avenue', 'Riverton',   'USA', 'active', DATE_ADD(NOW(), INTERVAL 9 DAY), NOW(), NOW());

INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, created_at, updated_at) VALUES
(1, 'brand_color', '#059669', NOW(), NOW()),
(1, 'currency', 'USD', NOW(), NOW()),
(1, 'tax_rate', '0', NOW(), NOW()),
(1, 'receipt_footer', 'Thank you for shopping at Demo Pharmacy!', NOW(), NOW()),
(1, 'low_stock_threshold', '10', NOW(), NOW()),
(1, 'expiry_alert_days', '90', NOW(), NOW()),
(1, 'allow_negative_stock', '0', NOW(), NOW()),
(2, 'brand_color', '#059669', NOW(), NOW()),
(2, 'currency', 'USD', NOW(), NOW()),
(2, 'tax_rate', '0', NOW(), NOW()),
(2, 'receipt_footer', 'Thank you for shopping at CityCare!', NOW(), NOW()),
(2, 'low_stock_threshold', '10', NOW(), NOW()),
(2, 'expiry_alert_days', '90', NOW(), NOW()),
(2, 'allow_negative_stock', '0', NOW(), NOW());

-- Demo users — password for every account: "password"
INSERT INTO users (id, tenant_id, role_id, name, email, password_hash, phone, status, email_verified_at, created_at, updated_at) VALUES
(1, NULL, 1, 'Platform Admin',   'super@medistock.test',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW()),
(2, 1,    2, 'Olivia Owner',     'owner@demo.com',         '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW()),
(3, 1,    3, 'Adam Admin',       'admin@demo.com',         '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW()),
(4, 1,    4, 'Paula Pharmacist', 'pharmacist@demo.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW()),
(5, 1,    5, 'Carl Cashier',     'cashier@demo.com',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW()),
(6, 1,    6, 'Ingrid Inventory', 'inventory@demo.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW()),
(7, 1,    7, 'Aaron Accountant', 'accountant@demo.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW()),
(8, 1,    8, 'Sam Staff',        'staff@demo.com',         '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW()),
(9, 2,    2, 'Chris CityCare',   'owner@citycare.example', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'active', NOW(), NOW(), NOW());

-- Demo subscriptions: tenant 1 on Professional (verified payment), tenant 2 on trial.
INSERT INTO subscriptions (id, tenant_id, plan_id, billing_cycle, status, starts_at, ends_at, trial_ends_at, created_at, updated_at) VALUES
(1, 1, 3, 'monthly', 'active', DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_ADD(NOW(), INTERVAL 20 DAY), NULL, NOW(), NOW()),
(2, 2, 1, 'monthly', 'trial',  NOW(), DATE_ADD(NOW(), INTERVAL 9 DAY), DATE_ADD(NOW(), INTERVAL 9 DAY), NOW(), NOW());

INSERT INTO subscription_usage (tenant_id, subscription_id, period_start, period_end, transactions_count, storage_used_mb, created_at, updated_at) VALUES
(1, 1, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 1, 12.50, NOW(), NOW()),
(2, 2, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 0, 0.00, NOW(), NOW());

INSERT INTO payments (id, tenant_id, subscription_id, method, amount, currency, status, reference, verified_at, verified_by, created_at, updated_at) VALUES
(1, 1, 1, 'bank', 49.00, 'USD', 'verified', 'SUB-DEMO-0001', NOW(), 'gateway:demo', NOW(), NOW());

INSERT INTO invoices (tenant_id, subscription_id, payment_id, invoice_number, amount, discount, tax, total, status, due_date, paid_at, created_at, updated_at) VALUES
(1, 1, 1, 'INV-2026-0001', 49.00, 0.00, 0.00, 49.00, 'paid', CURDATE(), NOW(), NOW(), NOW());

-- ── Demo pharmacy catalogue ────────────────────────────────────────────────
INSERT INTO branches (id, tenant_id, name, code, phone, city, is_main, status, created_at, updated_at) VALUES
(1, 1, 'Demo Pharmacy — Main Branch', 'MAIN', '+1 555 010 7788', 'Springfield', 1, 'active', NOW(), NOW()),
(2, 1, 'Demo Pharmacy — City Branch', 'BR-02', '+1 555 010 7790', 'Springfield', 0, 'active', NOW(), NOW()),
(3, 2, 'CityCare — Main Branch', 'MAIN', '+1 555 014 2200', 'Riverton', 1, 'active', NOW(), NOW());

INSERT INTO categories (id, tenant_id, name, description, created_at, updated_at) VALUES
(1, 1, 'Analgesics', 'Pain relief medicines', NOW(), NOW()),
(2, 1, 'Antibiotics', 'Prescription antibiotics', NOW(), NOW()),
(3, 1, 'Vitamins & Supplements', 'Daily vitamins and supplements', NOW(), NOW()),
(4, 1, 'Cold & Flu', 'Cough, cold and flu remedies', NOW(), NOW()),
(5, 1, 'Digestive Health', 'Acid reflux and digestion', NOW(), NOW()),
(6, 1, 'First Aid', 'Emergency and wound care basics', NOW(), NOW()),
(7, 1, 'Diabetes Care', 'Diabetes management medicines', NOW(), NOW()),
(8, 1, 'Skin Care', 'Creams and dermatology basics', NOW(), NOW());

INSERT INTO brands (id, tenant_id, name, manufacturer, country, created_at, updated_at) VALUES
(1, 1, 'HealthLabs', 'HealthLabs Inc.', 'USA', NOW(), NOW()),
(2, 1, 'NovaGen', 'NovaGen Pharmaceuticals', 'Germany', NOW(), NOW()),
(3, 1, 'MediCore', 'MediCore Ltd', 'UK', NOW(), NOW()),
(4, 1, 'PureVita', 'PureVita Naturals', 'Canada', NOW(), NOW()),
(5, 1, 'CarePlus', 'CarePlus Healthcare', 'USA', NOW(), NOW());

INSERT INTO suppliers (id, tenant_id, name, contact_person, email, phone, address, payment_terms, status, created_at, updated_at) VALUES
(1, 1, 'Nova Distributors', 'Nina Novak', 'orders@nova-dist.example', '+1 555 200 1100', '44 Distribution Way', 'Net 30', 'active', NOW(), NOW()),
(2, 1, 'HealthSource Ltd', 'Henry Hale', 'sales@healthsource.example', '+1 555 200 2250', '7 Pharma Park', 'Net 15', 'active', NOW(), NOW()),
(3, 1, 'MedSupply Co', 'Marta Mills', 'hello@medsupply.example', '+1 555 200 3390', '120 Wholesale Blvd', 'Cash on delivery', 'active', NOW(), NOW());

INSERT INTO medicines (id, tenant_id, category_id, brand_id, name, generic_name, sku, barcode, strength, dosage_form, unit, rack_location, reorder_level, requires_prescription, created_at, updated_at) VALUES
(1,  1, 1, 1, 'Paracetamol 500mg',    'Paracetamol',  'PCM-500', '8901001', '500mg',    'Tablet',  'tablet', 'A1', 50, 0, NOW(), NOW()),
(2,  1, 1, 3, 'Ibuprofen 400mg',      'Ibuprofen',    'IBU-400', '8901002', '400mg',    'Tablet',  'tablet', 'A1', 40, 0, NOW(), NOW()),
(3,  1, 2, 2, 'Amoxicillin 250mg',    'Amoxicillin',  'AMX-250', '8901003', '250mg',    'Capsule', 'capsule','A2', 30, 1, NOW(), NOW()),
(4,  1, 4, 1, 'Cetirizine 10mg',      'Cetirizine',   'CET-10',  '8901004', '10mg',     'Tablet',  'tablet', 'A3', 20, 0, NOW(), NOW()),
(5,  1, 3, 4, 'Vitamin D3 1000 IU',   'Cholecalciferol','VIT-D3','8901005', '1000 IU',  'Capsule', 'capsule','B1', 25, 0, NOW(), NOW()),
(6,  1, 5, 3, 'Omeprazole 20mg',      'Omeprazole',   'OMP-20',  '8901006', '20mg',     'Capsule', 'capsule','B2', 30, 0, NOW(), NOW()),
(7,  1, 7, 2, 'Metformin 500mg',      'Metformin',    'MET-500', '8901007', '500mg',    'Tablet',  'tablet', 'B2', 40, 1, NOW(), NOW()),
(8,  1, 2, 3, 'Azithromycin 500mg',   'Azithromycin', 'AZI-500', '8901008', '500mg',    'Tablet',  'tablet', 'A2', 20, 1, NOW(), NOW()),
(9,  1, 4, 5, 'Ambroxol Cough Syrup', 'Ambroxol',     'AMB-SYR', '8901009', '30mg/5ml', 'Syrup',   'bottle', 'C1', 15, 0, NOW(), NOW()),
(10, 1, 6, 4, 'ORS Sachets',          'Oral Rehydration Salts','ORS-SAC','8901010','—',   'Sachet',  'sachet', 'C2', 60, 0, NOW(), NOW()),
(11, 1, 8, 5, 'Antiseptic Cream 50g', 'Chlorhexidine','ANT-CRM', '8901011', '0.5%',     'Cream',   'tube',   'C3', 15, 0, NOW(), NOW()),
(12, 1, 1, 1, 'Aspirin 75mg',         'Aspirin',      'ASP-75',  '8901012', '75mg',     'Tablet',  'tablet', 'A1', 50, 0, NOW(), NOW());

-- Batches: note the expired batch (#3), two expiring-soon batches (#1, #11)
-- and the deliberately low Cetirizine stock (#6) for the alert screens.
INSERT INTO batches (id, tenant_id, branch_id, medicine_id, supplier_id, batch_number, manufacturing_date, expiry_date, purchase_price, selling_price, quantity, quantity_received, created_at, updated_at) VALUES
(1,  1, 1, 1,  1, 'PCM-2409', DATE_SUB(CURDATE(), INTERVAL 14 MONTH), DATE_ADD(CURDATE(), INTERVAL 55 DAY),   0.80, 1.50, 120, 120, NOW(), NOW()),
(2,  1, 1, 1,  1, 'PCM-2501', DATE_SUB(CURDATE(), INTERVAL 2 MONTH),  DATE_ADD(CURDATE(), INTERVAL 16 MONTH), 0.85, 1.50, 340, 340, NOW(), NOW()),
(3,  1, 1, 1,  2, 'PCM-OLD',  DATE_SUB(CURDATE(), INTERVAL 26 MONTH), DATE_SUB(CURDATE(), INTERVAL 45 DAY),   0.75, 1.50, 40,  40,  NOW(), NOW()),
(4,  1, 1, 2,  3, 'IBU-118',  DATE_SUB(CURDATE(), INTERVAL 4 MONTH),  DATE_ADD(CURDATE(), INTERVAL 14 MONTH), 1.90, 4.20, 210, 210, NOW(), NOW()),
(5,  1, 1, 3,  1, 'AMX-118',  DATE_SUB(CURDATE(), INTERVAL 3 MONTH),  DATE_ADD(CURDATE(), INTERVAL 8 MONTH),  2.60, 5.00, 150, 150, NOW(), NOW()),
(6,  1, 1, 4,  2, 'CET-077',  DATE_SUB(CURDATE(), INTERVAL 5 MONTH),  DATE_ADD(CURDATE(), INTERVAL 10 MONTH), 0.60, 2.25, 5,   100, NOW(), NOW()),
(7,  1, 1, 5,  2, 'VIT-310',  DATE_SUB(CURDATE(), INTERVAL 6 MONTH),  DATE_ADD(CURDATE(), INTERVAL 20 MONTH), 4.10, 8.50, 96,  96,  NOW(), NOW()),
(8,  1, 1, 6,  3, 'OMP-204',  DATE_SUB(CURDATE(), INTERVAL 4 MONTH),  DATE_ADD(CURDATE(), INTERVAL 12 MONTH), 1.40, 3.75, 180, 180, NOW(), NOW()),
(9,  1, 1, 7,  1, 'MET-512',  DATE_SUB(CURDATE(), INTERVAL 5 MONTH),  DATE_ADD(CURDATE(), INTERVAL 15 MONTH), 0.95, 2.80, 260, 260, NOW(), NOW()),
(10, 1, 1, 8,  3, 'AZI-090',  DATE_SUB(CURDATE(), INTERVAL 6 MONTH),  DATE_ADD(CURDATE(), INTERVAL 6 MONTH),  3.80, 7.50, 75,  75,  NOW(), NOW()),
(11, 1, 1, 9,  2, 'AMB-033',  DATE_SUB(CURDATE(), INTERVAL 10 MONTH), DATE_ADD(CURDATE(), INTERVAL 80 DAY),   2.20, 4.95, 48,  48,  NOW(), NOW()),
(12, 1, 1, 10, 1, 'ORS-611',  DATE_SUB(CURDATE(), INTERVAL 4 MONTH),  DATE_ADD(CURDATE(), INTERVAL 24 MONTH), 0.30, 0.90, 500, 500, NOW(), NOW()),
(13, 1, 1, 11, 2, 'ANT-145',  DATE_SUB(CURDATE(), INTERVAL 7 MONTH),  DATE_ADD(CURDATE(), INTERVAL 11 MONTH), 1.75, 3.95, 64,  64,  NOW(), NOW()),
(14, 1, 1, 12, 1, 'ASP-208',  DATE_SUB(CURDATE(), INTERVAL 3 MONTH),  DATE_ADD(CURDATE(), INTERVAL 18 MONTH), 0.45, 1.20, 320, 320, NOW(), NOW()),
(15, 1, 2, 1,  1, 'PCM-2502', DATE_SUB(CURDATE(), INTERVAL 2 MONTH),  DATE_ADD(CURDATE(), INTERVAL 16 MONTH), 0.85, 1.50, 150, 150, NOW(), NOW());

INSERT INTO customers (id, tenant_id, name, phone, email, gender, date_of_birth, created_at, updated_at) VALUES
(1, 1, 'Ahmed Raza',     '+1 555 300 1101', 'ahmed.raza@example.com',  'male',   '1988-04-12', NOW(), NOW()),
(2, 1, 'Sarah Mitchell', '+1 555 300 1102', 'sarah.m@example.com',     'female', '1992-09-03', NOW(), NOW()),
(3, 1, 'John Osei',      '+1 555 300 1103', 'john.osei@example.com',   'male',   '1979-01-25', NOW(), NOW()),
(4, 1, 'Fatima Noor',    '+1 555 300 1104', 'fatima.noor@example.com', 'female', '1995-12-17', NOW(), NOW());

-- One received purchase (created batches #2, #4, #5) + matching ledger rows.
INSERT INTO purchases (id, tenant_id, branch_id, supplier_id, invoice_reference, total_amount, status, purchased_by, created_at, updated_at) VALUES
(1, 1, 1, 1, 'SUP-INV-9918', 1078.00, 'received', 6, NOW(), NOW());
INSERT INTO purchase_items (purchase_id, medicine_id, batch_id, quantity, purchase_price, total) VALUES
(1, 1, 2, 340, 0.85, 289.00),
(1, 2, 4, 210, 1.90, 399.00),
(1, 3, 5, 150, 2.60, 390.00);

-- One completed demo sale (batches #1 and #7) + payment + ledger rows.
INSERT INTO sales (id, tenant_id, branch_id, customer_id, prescription_id, invoice_number, subtotal, discount, tax, grand_total, status, sold_by, created_at, updated_at) VALUES
(1, 1, 1, 1, NULL, 'SAL-DEMO-0001', 11.50, 0.00, 0.00, 11.50, 'completed', 5, NOW(), NOW());
INSERT INTO sale_items (sale_id, medicine_id, batch_id, quantity, unit_price, discount, total) VALUES
(1, 1, 1, 2, 1.50, 0.00, 3.00),
(1, 5, 7, 1, 8.50, 0.00, 8.50);
INSERT INTO sale_payments (sale_id, method, amount, reference, created_at) VALUES
(1, 'cash', 11.50, NULL, NOW());

-- Stock movement ledger for the seeds: purchases from Purchase #1, opening
-- balances for the remaining batches, and the demo sale.
INSERT INTO stock_movements (tenant_id, branch_id, medicine_id, batch_id, type, quantity, balance_after, reference_type, reference_id, note, created_by, created_at) VALUES
(1, 1, 1, 2,  'purchase', 340, 340, 'purchase', 1, 'Purchase SUP-INV-9918 received', 6, NOW()),
(1, 1, 2, 4,  'purchase', 210, 210, 'purchase', 1, 'Purchase SUP-INV-9918 received', 6, NOW()),
(1, 1, 3, 5,  'purchase', 150, 150, 'purchase', 1, 'Purchase SUP-INV-9918 received', 6, NOW()),
(1, 1, 1, 1,  'opening',  122, 122, 'batch', 1,  'Opening stock (pre-sale balance)', 2, NOW()),
(1, 1, 1, 3,  'opening',  40,  40,  'batch', 3,  'Opening stock', 2, NOW()),
(1, 1, 4, 6,  'opening',  5,   5,   'batch', 6,  'Opening stock', 2, NOW()),
(1, 1, 5, 7,  'opening',  97,  97,  'batch', 7,  'Opening stock (pre-sale balance)', 2, NOW()),
(1, 1, 6, 8,  'opening',  180, 180, 'batch', 8,  'Opening stock', 2, NOW()),
(1, 1, 7, 9,  'opening',  260, 260, 'batch', 9,  'Opening stock', 2, NOW()),
(1, 1, 8, 10, 'opening',  75,  75,  'batch', 10, 'Opening stock', 2, NOW()),
(1, 1, 9, 11, 'opening',  48,  48,  'batch', 11, 'Opening stock', 2, NOW()),
(1, 1, 10, 12,'opening',  500, 500, 'batch', 12, 'Opening stock', 2, NOW()),
(1, 1, 11, 13,'opening',  64,  64,  'batch', 13, 'Opening stock', 2, NOW()),
(1, 1, 12, 14,'opening',  320, 320, 'batch', 14, 'Opening stock', 2, NOW()),
(1, 2, 1, 15, 'opening',  150, 150, 'batch', 15, 'Opening stock — City Branch', 2, NOW()),
(1, 1, 1, 1,  'sale',     -2,  120, 'sale', 1, 'POS sale SAL-DEMO-0001', 5, NOW()),
(1, 1, 5, 7,  'sale',     -1,  96,  'sale', 1, 'POS sale SAL-DEMO-0001', 5, NOW());

INSERT INTO expenses (tenant_id, branch_id, title, category, amount, expense_date, payment_method, created_by, created_at, updated_at) VALUES
(1, 1, 'Monthly rent — Main Branch', 'Rent', 450.00, CURDATE(), 'bank', 7, NOW(), NOW()),
(1, 1, 'Electricity bill', 'Utilities', 86.50, CURDATE(), 'bank', 7, NOW(), NOW());

INSERT INTO prescriptions (tenant_id, customer_id, prescription_number, patient_name, doctor_name, hospital, prescribed_at, status, dispensed_by, dispensed_at, created_at, updated_at) VALUES
(1, 2, 'RX-1001', 'Sarah Mitchell', 'Dr. Emily Carter', 'CityCare Hospital', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'pending', NULL, NULL, NOW(), NOW()),
(1, 1, 'RX-1002', 'Ahmed Raza', 'Dr. Imran Sheikh', 'Springfield Clinic', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'dispensed', 4, DATE_SUB(NOW(), INTERVAL 5 DAY), NOW(), NOW());

INSERT INTO prescription_items (prescription_id, medicine_id, medicine_name, dosage, quantity, instructions) VALUES
(1, 3, 'Amoxicillin 250mg', '1 capsule three times daily', 21, 'Complete the full course.'),
(1, 1, 'Paracetamol 500mg', '1 tablet every 6 hours if fever', 10, 'Maximum 4 tablets per day.'),
(2, 7, 'Metformin 500mg', '1 tablet twice daily with meals', 30, 'Monitor blood sugar.');

-- Upgrade demo data: one open purchase order (restock for the low Cetirizine
-- stock), one supplier payment against purchase #1 (leaves a real
-- outstanding balance), and one pending branch transfer.
INSERT INTO purchase_orders (id, tenant_id, branch_id, supplier_id, order_number, status, expected_date, notes, total_amount, ordered_by, created_at, updated_at) VALUES
(1, 1, 1, 2, 'PO-DEMO-0001', 'ordered', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Restock low-selling-out items.', 265.00, 6, NOW(), NOW());

INSERT INTO purchase_order_items (purchase_order_id, medicine_id, quantity_ordered, quantity_received, purchase_price, total) VALUES
(1, 4, 100, 0, 0.60, 60.00),
(1, 5, 50, 0, 4.10, 205.00);

INSERT INTO supplier_payments (tenant_id, supplier_id, purchase_id, amount, method, reference, paid_at, notes, created_by, created_at, updated_at) VALUES
(1, 1, 1, 500.00, 'bank', 'BANK-TRX-DEMO-1', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Part payment for SUP-INV-9918.', 3, NOW(), NOW());

INSERT INTO stock_transfers (id, tenant_id, from_branch_id, to_branch_id, transfer_number, status, notes, requested_by, created_at, updated_at) VALUES
(1, 1, 2, 1, 'TRF-DEMO-0001', 'pending', 'Rebalance Paracetamol to the main branch.', 6, NOW(), NOW());

INSERT INTO stock_transfer_items (transfer_id, medicine_id, quantity) VALUES
(1, 1, 20);

INSERT INTO support_tickets (tenant_id, user_id, subject, message, priority, status, created_at, updated_at) VALUES
(1, 3, 'Barcode scanner setup', 'Which USB barcode scanners are supported by the POS? We want one that works with the medicine barcode field out of the box.', 'medium', 'open', NOW(), NOW());

INSERT INTO notifications (tenant_id, user_id, type, title, body, created_at) VALUES
(1, NULL, 'warning', 'Low stock alert', 'Cetirizine 10mg is at or below its reorder level (5 remaining, reorder at 20).', NOW()),
(1, NULL, 'danger', 'Expired batch on shelf', 'Batch PCM-OLD (Paracetamol 500mg) expired 45 days ago and still holds 40 units. It is blocked from sale.', NOW()),
(1, NULL, 'info', 'Welcome to MediStock Cloud', 'Your Professional plan is active. Invite your team from Staff & Team.', NOW());

INSERT INTO activity_logs (tenant_id, user_id, action, description, created_at) VALUES
(1, 2, 'tenant.registered', 'Pharmacy registered: Demo Pharmacy', NOW()),
(1, 6, 'purchase.received', 'Purchase #1 received — $1,078.00', NOW()),
(1, 5, 'sale.created', 'Sale SAL-DEMO-0001 — $11.50', NOW());
