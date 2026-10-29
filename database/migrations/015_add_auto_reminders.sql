-- Envío masivo automático (víspera, 17:00): interruptor y resumen en app_settings, y marca de los
-- recordatorios enviados por el worker (reminder_messages.auto_sent; sent_by_user_id queda NULL).
-- Ejecutar dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/015_add_auto_reminders.sql
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP(0) NOT NULL DEFAULT LOCALTIMESTAMP(0),
    updated_by_user_id BIGINT NULL
);

-- Apagado por defecto: lo enciende un administrador en Estado del sistema.
INSERT INTO app_settings (setting_key, value) VALUES ('auto_reminders_enabled', '0')
ON CONFLICT (setting_key) DO NOTHING;

ALTER TABLE reminder_messages ADD COLUMN IF NOT EXISTS auto_sent BOOLEAN NOT NULL DEFAULT FALSE;

GRANT SELECT, INSERT, UPDATE ON app_settings TO siges_app;
