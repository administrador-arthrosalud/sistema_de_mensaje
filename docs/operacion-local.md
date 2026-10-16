# Operación local

Esta guía describe el entorno de desarrollo. Para instalar en el servidor oficial sigue `docs/despliegue-produccion.md`.

## Aplicación

Requisitos: XAMPP (Apache/PHP), PHP con `pdo_pgsql`, `curl`, `mbstring` y `fileinfo`, y un servidor PostgreSQL 12+ local activo (XAMPP no lo incluye; se instala aparte). `pdo_mysql` solo hace falta para copiar los datos de la base MySQL anterior. La configuración privada está en `config/local.php`, ignorado por Git. No pegues su contenido en chats ni capturas.

El document root de PHP debe ser `public/`. En esta PC el dashboard se usa en:

```text
http://127.0.0.1:8082/
```

Para desarrollo en otro puerto:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t C:\proyectos\Sistema-de-mensajes\public
```

Antes de iniciar otro servidor o worker, comprueba si ya existe uno. No ejecutes dos copias del sincronizador.

## Sincronizador SIGES

PostgreSQL no debe exponerse a Internet. Abre un túnel SSH desde PowerShell y déjalo abierto:

```powershell
ssh -N -L 15432:127.0.0.1:5432 usuario_rocky@IP_PRIVADA_ROCKY
```

Comprueba que el puerto local acepta conexión:

```powershell
Test-NetConnection 127.0.0.1 -Port 15432
```

`TcpTestSucceeded` debe ser `True`. El usuario PostgreSQL configurado en `config/local.php` debe tener solo `CONNECT`, `USAGE` en `public` y `SELECT` sobre las tablas/vista que necesita la extracción. No uses `postgres` ni `root` como credencial de aplicación.

En otra terminal, ejecuta el worker:

```powershell
C:\xampp\php\php.exe C:\proyectos\Sistema-de-mensajes\scripts\sync-siges.php
```

Para ejecutar una sola pasada y volver al prompt:

```powershell
C:\xampp\php\php.exe C:\proyectos\Sistema-de-mensajes\scripts\sync-siges.php --once
```

La ventana SSH debe permanecer abierta. Si se cierra, el worker reintenta, pero no puede leer cambios de SIGES hasta que vuelva el túnel. Reiniciar XAMPP, Windows o cerrar la sesión de Codex puede detener procesos iniciados manualmente; vuelve a abrir el túnel y el worker.

## Base de datos local (PostgreSQL)

La base local `siges_dashboard` es una base PostgreSQL propia de la aplicación. No la crees dentro de la base de SIGES ni uses las credenciales de solo lectura de SIGES.

### Con Docker (recomendado en esta PC)

`docker-compose.yml` levanta todo el sistema; no hace falta XAMPP:

| Servicio | Contenedor | Qué hace | Puerto en el equipo |
|---|---|---|---|
| `postgres` | `siges-dashboard-db` | Base local PostgreSQL 16 (volumen `pgdata`) | `127.0.0.1:5432` |
| `web` | `siges-dashboard-web` | PHP 8.2 + Apache, document root `public/` | `127.0.0.1:8082` |
| `sync` | `siges-dashboard-sync` | Worker `scripts/sync-siges.php` (cada 3 s) | — |

- El código se monta en solo lectura desde la carpeta del proyecto: los cambios en PHP se ven al recargar, sin reconstruir. Solo hay que reconstruir (`docker compose up -d --build`) si cambia `docker/php/`.
- `config/local.php` se lee igual que antes. Dentro de Docker, `docker-compose.yml` fija `DB_HOST=postgres` y `SIGES_PG_HOST=host.docker.internal`, que tienen prioridad sobre `config/local.php`.
- Las contraseñas de `postgres`, `siges_app` y `siges_sync` se leen de `.env` (ignorado por Git; plantilla en `.env.example`) y deben coincidir con `DB_PASSWORD` y `SIGES_SYNC_DB_PASSWORD` de `config/local.php`. Al crear el volumen por primera vez se aplican solos `schema.sql`, los roles y `grants.sql`.
- El navegador llega a Apache desde la puerta de enlace de la red Docker (`172.28.0.1`); por eso `web` define `LOCAL_ACCESS_EXTRA_IPS`. El puerto solo se publica en `127.0.0.1`, así que otros equipos de la red no pueden entrar. Si cambias la subred en `docker-compose.yml`, cambia también esa IP.
- El túnel SSH a SIGES sigue abriéndose en Windows (`127.0.0.1:15432`); el contenedor `sync` lo alcanza por `host.docker.internal` con el puerto `SIGES_PG_PORT` de `config/local.php`. Mientras falte la configuración de SIGES, `sync` se reinicia en bucle mostrando el aviso en `docker compose logs sync`.

```powershell
docker compose up -d                 # iniciar todo (también arranca solo con Docker Desktop)
docker compose ps                    # estado
docker compose logs -f sync          # seguir el worker
docker compose logs web              # errores de PHP/Apache
docker compose restart sync          # tras cambiar la configuración de SIGES
docker compose stop                  # detener sin perder datos
docker exec -it siges-dashboard-db psql -U postgres -d siges_dashboard   # consola SQL
```

Abre la aplicación en `http://127.0.0.1:8082/`. No inicies además el worker con XAMPP: solo debe haber un sincronizador.

`docker compose down -v` **borra el volumen y todos los datos locales** (historial de envíos, contactos temporales, chats); úsalo solo si quieres reinicializar la base. Copia de seguridad:

```powershell
docker exec siges-dashboard-db pg_dump -U postgres -d siges_dashboard -Fc > respaldo.dump
```

Guarda el respaldo fuera del repositorio: contiene datos de pacientes.

### Sin Docker

Instalación nueva (como usuario administrador de PostgreSQL):

```powershell
psql -U postgres -c "CREATE DATABASE siges_dashboard ENCODING 'UTF8' TEMPLATE template0"
psql -U postgres -d siges_dashboard -f database\schema.sql
psql -U postgres -d siges_dashboard -c "CREATE ROLE siges_app LOGIN PASSWORD 'clave-segura'"
psql -U postgres -d siges_dashboard -c "CREATE ROLE siges_sync LOGIN PASSWORD 'otra-clave-segura'"
psql -U postgres -d siges_dashboard -f database\grants.sql
```

Después ajusta en `config/local.php` `DB_PORT` (normalmente `5432`), `DB_USER`/`DB_PASSWORD` (`siges_app`) y `SIGES_SYNC_DB_USER`/`SIGES_SYNC_DB_PASSWORD` (`siges_sync`).

### Pasar los datos desde MySQL

Si la instalación anterior tenía historial en MySQL (envíos, contactos temporales, chats):

1. Detén el worker y el servidor web para que nadie escriba durante la copia.
2. Haz una copia de seguridad de MySQL (`mysqldump`).
3. Crea la base PostgreSQL con `schema.sql` (sin datos) como arriba.
4. Añade a `config/local.php` `MYSQL_SOURCE_*` y `PG_MIGRATION_USER`/`PG_MIGRATION_PASSWORD` (un rol dueño de las tablas, por ejemplo `postgres`).
5. Ejecuta una sola vez:

```powershell
C:\xampp\php\php.exe C:\proyectos\Sistema-de-mensajes\scripts\migrate-mysql-to-postgresql.php
```

El script solo imprime conteos de filas, se niega a copiar si alguna tabla de destino ya tiene datos o si el destino es la base de SIGES, y si algo falla no guarda nada. Al terminar, quita las entradas `MYSQL_SOURCE_*` y `PG_MIGRATION_*` de `config/local.php`.

Las migraciones 001-005 de MySQL quedan en `database/mysql-legacy/` solo como referencia; ya están incluidas en `database/schema.sql`. Las migraciones de PostgreSQL están en `database/migrations/`: la última aplicada es `009_` (006 `unaccent`, 007 sede de prueba en `manual_contacts`, 008 celular detectado desde SIGES en `appointments.destination`, 009 tercera sede "LINCE - CONSULTORIO 3"). Todas ya están incluidas en `database/schema.sql` para instalaciones nuevas; la siguiente migración va en `010_`.

### Verificar que todo coincide con SIGES

Antes de un envío masivo grande, o cuando algo se vea raro, ejecuta estas tres pruebas (solo imprimen conteos y, cuando hace falta, valores parcialmente ocultos; nunca nombres ni celulares completos):

```powershell
docker compose run --rm --no-deps -T sync php scripts/verify-sync.php              # totales y huella md5 de todas las citas
docker compose run --rm --no-deps -T sync php scripts/verify-destinations.php      # celulares: coinciden con SIGES y tienen formato válido
docker compose run --rm --no-deps -T sync php scripts/verify-appointment-type.php  # solo tipo NORMAL queda visible/enviable
```

Las tres deben terminar en "SINCRONIZADO" / "CORRECTO". Si alguna falla justo después de reiniciar el worker, espera unos segundos y repite: el sincronizador compara y corrige cada 3 segundos, pero recién arrancado puede tardar un par de pasadas en ponerse al día.

## Chats con pacientes

La bandeja (`chats.php`) se usa igual que el resto del dashboard, solo desde el equipo local. Lo distinto es cómo llegan los mensajes entrantes:

`chat-webhook.php` necesita que Gestionalo pueda llamarlo por internet, así que no puede quedar solo en `127.0.0.1` como el resto de `public/`. **Nunca apuntes un túnel directo a `127.0.0.1:8082`**: expondría toda la aplicación (agenda, chats, envío de recordatorios) a quien descubra esa URL, no solo el webhook. Usa siempre el proxy restringido que solo deja pasar `POST /chat-webhook.php` y responde 404 a cualquier otra ruta.

El proxy no arranca con `docker compose up -d`; hace falta su perfil:

```powershell
docker compose --profile webhook up -d webhook-proxy
```

Queda escuchando en `127.0.0.1:8090` (cambia el puerto con `WEBHOOK_PROXY_PORT` en `.env` si ya está en uso). Pruébalo antes de publicarlo:

```powershell
curl.exe -s -o NUL -w "%{http_code}`n" http://127.0.0.1:8090/index.php   # debe dar 404
curl.exe -s -o NUL -w "%{http_code}`n" -X POST "http://127.0.0.1:8090/chat-webhook.php?token=incorrecto"  # debe dar 403
```

### Exponerlo a internet

- **Pruebas (recomendado para empezar)**: un túnel rápido con `cloudflared`, sin cuenta ni configuración previa, apuntado **al proxy** (puerto 8090, no 8082):
  ```powershell
  docker run --rm -it cloudflare/cloudflared:latest tunnel --url http://host.docker.internal:8090
  ```
  Imprime una URL `https://algo-aleatorio.trycloudflare.com`; úsala como webhook en Gestionalo mientras pruebas y cierra la ventana cuando termines (la URL deja de funcionar).
- **Producción real**: dominio propio con HTTPS (reverse proxy, hosting, etc.) apuntado también al puerto del proxy, no al 8082. Ver `docs/despliegue-produccion.md`.

En cualquier caso, protege el endpoint con `CHATBOT_WEBHOOK_TOKEN` (un token largo y aleatorio en `config/local.php`) y nunca publiques ese token ni lo compartas en capturas.

### ⚠️ Pendiente: confirmar si Gestionalo ofrece webhook de mensajes entrantes

**Estado al 16/10/2026: sin confirmar, a la espera de soporte de Gestionalo.**

- El panel de la línea (`chatbot.gestionalo.pe/vincular/...`) solo tiene opciones de **envío**: vincular el dispositivo, probar un mensaje y dos ejemplos de API de salida (`/chat/sendmedia/...` por formulario, usado por `ChatbotApiClient`, y `/api/v1/sendmedia/...` con `X-API-Key` y JSON). Ningún campo para registrar una URL de webhook.
- La pestaña **"Log"** de ese panel solo lista los últimos envíos salientes (celular de origen, destino, estado "Enviado"). No muestra mensajes entrantes.
- Se envió una consulta directa al soporte de Gestionalo preguntando si existe webhook (configurable por ellos aunque no aparezca en el panel) y, si no, si hay alguna otra forma de consultar los mensajes entrantes. **Pendiente de respuesta.**
- Mientras tanto: `public/chat-webhook.php`, el proxy restringido (`docker/nginx/webhook-only.conf`, perfil `webhook`) y el modo diagnóstico (`CHATBOT_WEBHOOK_DEBUG`) ya están listos y probados (ver más abajo); si Gestionalo confirma el webhook, falta ajustar el bloque `AJUSTA SOLO ESTE BLOQUE` con los nombres reales de campo y nada más.
- **La plantilla de recordatorio no pide confirmar/reprogramar/cancelar por número** (1/2/3) mientras esto no se confirme: pedirlo sería engañoso si nadie va a leer la respuesta. Ver `buildReminderMessage()` en `public/assets/js/app.js`. En cuanto se confirme el webhook, hay que: (1) restaurar el bloque de opciones en la plantilla, (2) construir la lectura del número de respuesta y su guardado, (3) decidir qué pasa en la agenda cuando el paciente confirma/reprograma/cancela.

### Descubrir el formato real del payload

El formato que usa Gestionalo no está documentado públicamente. En vez de esperar su confirmación, actívalo y provócalo con un mensaje real:

1. En `config/local.php`, agrega `'CHATBOT_WEBHOOK_DEBUG' => true,`.
2. Reinicia la web: `docker compose restart web`.
3. Configura la URL del túnel como webhook en el panel de Gestionalo y envía una respuesta real desde un celular a la línea (por ejemplo el tuyo).
4. Revisa `docker compose logs web | grep "Webhook debug"`. Verás solo los **nombres de los campos y su tipo** (p. ej. `{"celular":"string","mensaje":"string","id":"string"}`), nunca el celular ni el texto del mensaje.
5. Ajusta el bloque `AJUSTA SOLO ESTE BLOQUE` de `public/chat-webhook.php` con esos nombres reales.
6. Vuelve a poner `'CHATBOT_WEBHOOK_DEBUG' => false,` (o quita la línea) y reinicia la web. No lo dejes activo: cada vez que se active el token ya validó la petición, así que solo lo ve quien conoce el token, pero sigue siendo una grabación extra que no debe quedar permanente.

Si los mensajes no llegan a la bandeja:

- Revisa que la URL configurada en Gestionalo incluya `?token=` con el valor exacto de `CHATBOT_WEBHOOK_TOKEN`, y que apunte al **proxy** (8090 o el dominio), no directo a 8082.
- Comprueba que el túnel o el dominio público sigue activo; un túnel cerrado no avisa, simplemente deja de recibir.
- HTTP 403 en `chat-webhook.php`: el token no coincide, o la petición no es POST.
- HTTP 422: Gestionalo entregó el mensaje, pero `chat-webhook.php` no pudo identificar el celular o el texto en el payload. Activa el modo diagnóstico (arriba) para ver los nombres reales de los campos.

## Diagnóstico rápido

- `ERR_CONNECTION_REFUSED` en `127.0.0.1:8082`: el servidor PHP no está escuchando en ese puerto.
- Error de base de datos local: comprueba que el servicio de PostgreSQL está activo (`Test-NetConnection 127.0.0.1 -Port 5432`) y revisa la configuración privada sin copiarla al chat.
- `permission denied for table ...`: falta aplicar `database/grants.sql` después de crear los roles.
- Error PostgreSQL `connection refused` a `127.0.0.1:15432`: el túnel SSH no está abierto en esta PC o PostgreSQL no escucha en el destino del túnel.
- `ATENDIDO`, `ANULADO` o una cita `ADICIONAL`/`PROCEDIMIENTO` visible en la agenda: comprueba que el worker puede llegar a SIGES y que compara estados existentes; la consulta de agenda siempre debe filtrar `status = 'RESERVADO' AND tipo = 'NORMAL'`. Ejecuta `scripts/verify-appointment-type.php` para confirmarlo con números.
- HTTP 401/403 en Gestionalo: valida la clave asignada a la línea con el proveedor. No intercambies la clave de línea con el celular del destinatario.

Nunca compartas contraseñas, claves de línea, tokens o capturas de `config/local.php`. Si una credencial aparece en una captura compartida, rótala y actualiza la configuración local.
