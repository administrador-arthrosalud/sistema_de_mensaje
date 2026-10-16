-- Celular del paciente para una cita, extraído de cita.contactos en SIGES
-- (JSON de texto, p. ej. '["987654321"]'). Permite el envío masivo de
-- recordatorios sin que el personal escriba cada número a mano. Sigue
-- siendo editable/revisable antes de enviar: no se envía nada sin que una
-- persona confirme (ver BulkReminderController y send-reminder.php).
-- Ejecutar dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/008_add_appointment_destination.sql
ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS destination VARCHAR(20) NULL;
CREATE INDEX IF NOT EXISTS idx_appointments_destination ON appointments (destination) WHERE destination IS NOT NULL;
