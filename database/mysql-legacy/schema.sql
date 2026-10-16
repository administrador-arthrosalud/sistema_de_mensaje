CREATE DATABASE IF NOT EXISTS siges_dashboard
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE siges_dashboard;

CREATE TABLE IF NOT EXISTS appointments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_key CHAR(64) NOT NULL,
    siges_cita_id BIGINT UNSIGNED NULL,
    patient_name VARCHAR(255) NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    doctor_name VARCHAR(255) NOT NULL DEFAULT '',
    specialty VARCHAR(160) NOT NULL DEFAULT '',
    sede_nombre VARCHAR(80) NULL,
    consultorio VARCHAR(160) NULL,
    modulo VARCHAR(80) NULL,
    codigo_ipress VARCHAR(8) NULL,
    appointment_note TEXT NULL,
    visit_reason TEXT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'RESERVADO',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_appointments_source_key (source_key),
    UNIQUE KEY uq_appointments_siges_cita_id (siges_cita_id),
    KEY idx_appointments_pending_date (status, appointment_date, appointment_time),
    KEY idx_appointments_doctor (doctor_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS siges_sync_state (
    sync_key VARCHAR(64) NOT NULL,
    last_cita_id BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sync_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id BIGINT UNSIGNED NOT NULL,
    origin ENUM('siges', 'manual') NOT NULL DEFAULT 'siges',
    manual_contact_id BIGINT UNSIGNED NULL,
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
    KEY idx_reminder_manual_contact (origin, manual_contact_id, id),
    KEY idx_reminder_sent_at (status, sent_at),
    KEY idx_reminder_destination (destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    destination VARCHAR(20) NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    body TEXT NOT NULL,
    provider_message_id VARCHAR(128) NULL,
    status ENUM('received', 'pending', 'sent', 'failed') NOT NULL DEFAULT 'received',
    http_status SMALLINT UNSIGNED NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_messages_provider_id (provider_message_id),
    KEY idx_chat_messages_destination (destination, id),
    KEY idx_chat_messages_unread (direction, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
