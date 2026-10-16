ALTER TABLE reminder_messages
    ADD COLUMN origin ENUM('siges', 'manual') NOT NULL DEFAULT 'siges' AFTER appointment_id,
    ADD COLUMN manual_contact_id BIGINT UNSIGNED NULL AFTER origin,
    ADD KEY idx_reminder_manual_contact (origin, manual_contact_id, id);

CREATE TABLE IF NOT EXISTS manual_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_name VARCHAR(255) NOT NULL,
    destination VARCHAR(20) NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    doctor_name VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_manual_contact_appointment (appointment_date, appointment_time),
    KEY idx_manual_contact_name (patient_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE, DELETE ON siges_dashboard.manual_contacts TO 'siges_app'@'localhost';
GRANT SELECT ON siges_dashboard.reminder_messages TO 'siges_app'@'localhost';
FLUSH PRIVILEGES;
