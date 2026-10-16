#!/bin/sh
# Crea los roles de la aplicación con las contraseñas de .env. Se ejecuta una
# sola vez, al inicializar el volumen, entre schema.sql y grants.sql.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v app_password="$SIGES_APP_PASSWORD" \
    -v sync_password="$SIGES_SYNC_PASSWORD" <<'SQL'
CREATE ROLE siges_app LOGIN PASSWORD :'app_password';
CREATE ROLE siges_sync LOGIN PASSWORD :'sync_password';
SQL
