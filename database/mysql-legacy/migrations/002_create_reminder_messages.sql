USE siges_dashboard;

CREATE TABLE IF NOT EXISTS reminder_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id BIGINT UNSIGNED NOT NULL,
    destination VARCHAR(20) NOT NULL,
    patient_name VARCHAR(255) NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    doctor_name VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    http_status SMALLINT UNSIGNED NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_reminder_appointment (appointment_id, id),
    KEY idx_reminder_sent_at (status, sent_at),
    KEY idx_reminder_destination (destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
