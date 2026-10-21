-- Latido del worker de sincronización para la pantalla "Estado del sistema" (solo administradores).
-- Ejecutar dentro de siges_dashboard (no en la base de SIGES):
--   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/011_add_sync_heartbeat.sql
ALTER TABLE siges_sync_state ADD COLUMN IF NOT EXISTS last_run_at TIMESTAMP(0) NULL;
GRANT SELECT ON siges_sync_state TO siges_app;
