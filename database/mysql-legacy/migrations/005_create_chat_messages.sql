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

GRANT SELECT, INSERT, UPDATE ON siges_dashboard.chat_messages TO 'siges_app'@'localhost';
FLUSH PRIVILEGES;
