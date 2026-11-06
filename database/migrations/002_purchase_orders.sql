-- ═══════════════════════════════════════════════════════════════════════════
-- MediStock Cloud — Migration 002: purchase orders + purchase returns
-- Purchase orders support partial deliveries: ordered vs received quantity
-- is tracked per line, and receiving an order creates a normal purchase
-- (batches + stock_movements) through StockService::receivePurchaseOrder().
-- Purchase returns send stock back to a supplier, decrementing the original
-- batch inside a transaction with a permanent movement record.
-- Run ONCE against an existing install (folded into database.sql).
-- ═══════════════════════════════════════════════════════════════════════════

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
