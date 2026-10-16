-- Sede opcional (LIMA/LINCE) en los contactos temporales, para poder probar el
-- recordatorio con la línea "Sede ...: dirección" sin inventar una cita real.
-- Ejecutar dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/007_add_manual_contact_sede.sql
ALTER TABLE manual_contacts
    ADD COLUMN IF NOT EXISTS sede VARCHAR(10) NULL CHECK (sede IS NULL OR sede IN ('LIMA', 'LINCE'));
