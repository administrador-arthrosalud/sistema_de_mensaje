-- Motivo del fallo de un recordatorio. 'no_whatsapp' = Gestionalo respondió "No LID for user": el
-- número no tiene WhatsApp (o está mal digitado en SIGES). Esos números se excluyen del envío masivo
-- y la agenda los marca, hasta que el celular cambie en SIGES.
-- Ejecutar dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/014_add_failure_reason.sql
ALTER TABLE reminder_messages ADD COLUMN IF NOT EXISTS failure_reason VARCHAR(20) NULL;
CREATE INDEX IF NOT EXISTS idx_reminder_failure_reason ON reminder_messages (failure_reason, destination) WHERE failure_reason IS NOT NULL;

-- Histórico: antes de guardar el motivo, los números con HTTP 500 repetido y sin ningún envío aceptado
-- eran exactamente estos casos (se comprobó con la respuesta "No LID for user" de Gestionalo).
UPDATE reminder_messages rm SET failure_reason = 'no_whatsapp'
WHERE rm.status = 'failed' AND rm.http_status = 500 AND rm.failure_reason IS NULL
  AND NOT EXISTS (SELECT 1 FROM reminder_messages ok WHERE ok.destination = rm.destination AND ok.status = 'sent');
