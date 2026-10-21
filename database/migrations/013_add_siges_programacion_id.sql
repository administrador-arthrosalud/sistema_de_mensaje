-- Guarda la programación (médico + consultorio) de la cita en SIGES para que el worker detecte
-- cuando se cambia el médico o el consultorio sin cambiar fecha, hora ni estado.
-- Ejecutar dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/013_add_siges_programacion_id.sql
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS siges_programacion_id BIGINT NULL;
