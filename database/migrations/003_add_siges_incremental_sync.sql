USE siges_dashboard;

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS siges_cita_id BIGINT UNSIGNED NULL AFTER source_key,
    ADD UNIQUE KEY uq_appointments_siges_cita_id (siges_cita_id);

CREATE TABLE IF NOT EXISTS siges_sync_state (
    sync_key VARCHAR(64) NOT NULL,
    last_cita_id BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sync_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
