-- Permisos mínimos para los dos roles de la aplicación. Ejecútalo como
-- administrador dentro de siges_dashboard después de schema.sql.
-- Crea antes los roles (las contraseñas no se guardan en este repositorio):
--   CREATE ROLE siges_app LOGIN PASSWORD '...';   -- aplicación web
--   CREATE ROLE siges_sync LOGIN PASSWORD '...';  -- worker de sincronización

GRANT CONNECT ON DATABASE siges_dashboard TO siges_app, siges_sync;
GRANT USAGE ON SCHEMA public TO siges_app, siges_sync;

-- Aplicación web: lee la agenda y registra envíos, contactos temporales y chats.
GRANT SELECT ON appointments TO siges_app;
GRANT SELECT, INSERT, UPDATE ON reminder_messages, chat_messages TO siges_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON manual_contacts TO siges_app;
-- Inicio de sesión (migración 010).
GRANT SELECT, INSERT, UPDATE ON users TO siges_app;
-- Estado del sistema: lectura del latido del worker (migración 011).
GRANT SELECT ON siges_sync_state TO siges_app;
-- Envío automático: interruptor y resumen (migración 015).
GRANT SELECT, INSERT, UPDATE ON app_settings TO siges_app;
-- Cola de alertas técnicas por WhatsApp (migración 016).
GRANT SELECT, INSERT, UPDATE ON system_error_alerts TO siges_app;

-- Worker: único proceso que escribe la copia local de citas.
GRANT SELECT, INSERT, UPDATE ON appointments, siges_sync_state TO siges_sync;

-- Secuencias de las columnas identity.
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO siges_app, siges_sync;
