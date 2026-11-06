-- ═══════════════════════════════════════════════════════════════════════════
-- MediStock Cloud — Migration 001: medicine catalogue fields + batch intake
-- Adds medicine photo, manufacturer, default supplier + default prices,
-- medicine status, and the quantity originally received on each batch.
-- Run ONCE against an existing install (already folded into database.sql
-- for fresh installs). Non-destructive: only ADD COLUMN / ADD KEY.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE medicines
    ADD COLUMN photo_path            VARCHAR(255) NULL AFTER rack_location,
    ADD COLUMN manufacturer          VARCHAR(140) NULL AFTER brand_id,
    ADD COLUMN default_supplier_id   INT UNSIGNED NULL AFTER manufacturer,
    ADD COLUMN default_purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER default_supplier_id,
    ADD COLUMN default_selling_price  DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER default_purchase_price,
    ADD COLUMN status                ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER default_selling_price,
    ADD CONSTRAINT fk_med_supplier FOREIGN KEY (default_supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL;

ALTER TABLE batches
    ADD COLUMN quantity_received INT NOT NULL DEFAULT 0 AFTER quantity;

-- Backfill: for pre-migration rows the received quantity is unknown, so the
-- current on-hand quantity is the best available approximation.
UPDATE batches SET quantity_received = quantity WHERE quantity_received = 0;
