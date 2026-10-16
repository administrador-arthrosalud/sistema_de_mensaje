<?php
// Plantilla de config/local.php (el real está ignorado por Git y NUNCA se versiona).
// Copia este archivo a config/local.php y reemplaza cada valor "CAMBIAR".
// Las variables de entorno con el mismo nombre tienen prioridad sobre este archivo;
// en Docker, docker-compose.yml ya define DB_HOST, SIGES_SYNC_DB_HOST y SIGES_PG_HOST.
return [
    // Base local de la aplicación (PostgreSQL, contenedor "postgres"). Rol de la web.
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '5432',
    'DB_NAME' => 'siges_dashboard',
    'DB_USER' => 'siges_app',
    'DB_PASSWORD' => 'CAMBIAR', // = SIGES_APP_PASSWORD de .env
    'APP_TIMEZONE' => 'America/Lima',

    // Rol del worker en la base local.
    'SIGES_SYNC_DB_USER' => 'siges_sync',
    'SIGES_SYNC_DB_PASSWORD' => 'CAMBIAR', // = SIGES_SYNC_PASSWORD de .env

    // SIGES (solo lectura). El host lo define docker-compose.yml.
    'SIGES_PG_PORT' => '5432', // 15432 si se usa un túnel SSH local
    'SIGES_PG_DATABASE' => 'isis3_arths',
    'SIGES_PG_USER' => 'siges_reader',
    'SIGES_PG_PASSWORD' => 'CAMBIAR',
    'SIGES_PG_SSLMODE' => 'disable',

    // Gestionalo.
    'CHATBOT_BASE_URL' => 'https://chatbot.gestionalo.pe',
    'CHATBOT_LINE_KEY' => 'CAMBIAR', // serie asignada a la línea
    'CHATBOT_COUNTRY_CODE' => '51',
    'CHATBOT_WEBHOOK_TOKEN' => 'CAMBIAR', // token largo y aleatorio (openssl rand -hex 32)
    // true solo mientras se descubre el formato real del payload de Gestionalo
    // (ver docs/operacion-local.md). Registra nombres de campo y tipo, nunca
    // valores. Vuelve a false en cuanto termines.
    'CHATBOT_WEBHOOK_DEBUG' => false,
];
