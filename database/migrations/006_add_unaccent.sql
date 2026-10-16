-- Búsqueda sin distinguir tildes en la agenda ("traumatologia" encuentra "TRAUMATOLOGÍA").
-- Ejecutar como administrador dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/006_add_unaccent.sql
CREATE EXTENSION IF NOT EXISTS unaccent;
