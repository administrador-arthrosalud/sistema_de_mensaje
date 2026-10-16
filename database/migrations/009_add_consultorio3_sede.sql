-- Permite probar el mensaje de "LINCE - CONSULTORIO 3" (Jr. Joaquín Bernal 344)
-- desde Recordatorios temporales, igual que LIMA y LINCE.
-- Ejecutar dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/009_add_consultorio3_sede.sql
-- La columna se creó como VARCHAR(10) (migración 007); "LINCE - CONSULTORIO 3" no entra.
ALTER TABLE manual_contacts ALTER COLUMN sede TYPE VARCHAR(24);
ALTER TABLE manual_contacts DROP CONSTRAINT IF EXISTS manual_contacts_sede_check;
ALTER TABLE manual_contacts
    ADD CONSTRAINT manual_contacts_sede_check
    CHECK (sede IS NULL OR sede IN ('LIMA', 'LINCE', 'LINCE - CONSULTORIO 3'));
