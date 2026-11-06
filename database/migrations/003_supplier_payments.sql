-- ═══════════════════════════════════════════════════════════════════════════
-- MediStock Cloud — Migration 003: supplier payments & outstanding balances
-- A supplier's outstanding balance is always DERIVED, never stored:
--   SUM(purchases.total_amount) − SUM(supplier_payments.amount)
--   − SUM(purchase_returns.total_amount)
-- so it can never drift from the underlying records.
-- Run ONCE against an existing install (folded into database.sql).
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS supplier_payments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    purchase_id INT UNSIGNED NULL,           -- optional: payment tied to one purchase
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
