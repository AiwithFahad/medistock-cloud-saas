-- ═══════════════════════════════════════════════════════════════════════════
-- MediStock Cloud — Migration 004: inter-branch stock transfers
-- A transfer is created as 'pending' (no stock moves), then completed in a
-- single transaction: FEFO allocation out of the source branch, find-or-
-- create of the matching batch in the destination branch, and two
-- stock_movements rows (type 'transfer') — one out, one in.
-- Split POS payments need NO migration: sale_payments already stores one
-- row per payment method/amount, so a split sale is simply multiple rows.
-- Run ONCE against an existing install (folded into database.sql).
-- ═══════════════════════════════════════════════════════════════════════════

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
    batch_id    INT UNSIGNED NULL,           -- source batch chosen (FEFO) at completion
    quantity    INT NOT NULL,
    CONSTRAINT fk_tri_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE,
    CONSTRAINT fk_tri_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id)       ON DELETE RESTRICT,
    CONSTRAINT fk_tri_batch    FOREIGN KEY (batch_id)    REFERENCES batches (id)         ON DELETE SET NULL
) ENGINE=InnoDB;
