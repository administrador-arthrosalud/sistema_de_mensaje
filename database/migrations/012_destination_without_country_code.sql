-- appointments.destination pasa a guardarse en formato local de 9 dígitos (sin el 51):
-- en SIGES algunos celulares se digitan con el código de país y otros sin él.
-- El envío vuelve a anteponer el 51 (ChatbotApiClient::normalizeDestination).
-- reminder_messages.destination NO se toca: es el número exacto que se envió.
-- Ejecutar dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/012_destination_without_country_code.sql
UPDATE appointments SET destination = substr(destination, 3) WHERE destination ~ '^519[0-9]{8}$';
