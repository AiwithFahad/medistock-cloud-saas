-- ═══════════════════════════════════════════════════════════════════════════
-- MediStock Cloud — Migration 005: prescription line items, dispensing
-- audit fields, and tenant registration approval state.
--  * prescription_items: the medicines on a prescription (dosage, quantity,
--    instructions) so dispensing is recorded against real lines.
--  * prescriptions.dispensed_by / dispensed_at: who dispensed and when —
--    the authorized dispensing record.
--  * tenants.status gains 'pending' so the platform can require approval
--    (platform setting require_tenant_approval=1) before a new pharmacy
--    can sign in. Existing tenants keep their current status.
-- Run ONCE against an existing install (folded into database.sql).
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS prescription_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT UNSIGNED NOT NULL,
    medicine_id     INT UNSIGNED NULL,           -- NULL = free-text medicine name
    medicine_name   VARCHAR(180) NOT NULL,
    dosage          VARCHAR(80) NULL,            -- e.g. 1 tablet twice daily
    quantity        INT NOT NULL DEFAULT 1,
    instructions    VARCHAR(255) NULL,
    CONSTRAINT fk_rxi_rx       FOREIGN KEY (prescription_id) REFERENCES prescriptions (id) ON DELETE CASCADE,
    CONSTRAINT fk_rxi_medicine FOREIGN KEY (medicine_id)     REFERENCES medicines (id)     ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE prescriptions
    ADD COLUMN dispensed_by INT UNSIGNED NULL AFTER status,
    ADD COLUMN dispensed_at DATETIME NULL AFTER dispensed_by,
    ADD CONSTRAINT fk_rx_dispensed_by FOREIGN KEY (dispensed_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE tenants
    MODIFY status ENUM('pending','active','suspended') NOT NULL DEFAULT 'active';

INSERT INTO platform_settings (setting_key, setting_value, created_at, updated_at) VALUES
('require_tenant_approval', '0', NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;
