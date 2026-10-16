# SIGES · Dashboard de citas pendientes

Aplicación PHP con PDO y PostgreSQL para consultar citas programadas y enviar recordatorios por WhatsApp (Gestionalo). La agenda muestra solo registros con `siges_cita_id` enlazado a SIGES, estado exacto `RESERVADO`, tipo `NORMAL` (SIGES también tiene cupos `ADICIONAL` y otros tipos como `PROCEDIMIENTO`, que se excluyen siempre) y fecha/hora aún no pasada. Se puede filtrar por paciente, médico, sede o motivo, por rango de fechas y por una de las 3 sedes (LIMA, LINCE, LINCE - Consultorio 3). El celular se detecta automáticamente desde SIGES y permite enviar recordatorios uno por uno o en lote por fecha (**Envío masivo**); el texto, siempre editable antes de enviar, incluye paciente, fecha, hora, médico y la dirección de la sede.

## Guías del proyecto

- `AGENTS.md`: reglas obligatorias para agentes que trabajen en el código.
- `docs/arquitectura.md`: flujo SIGES → worker → PostgreSQL local → dashboard y reglas de estados.
- `docs/operacion-local.md`: entorno de desarrollo (Docker Desktop), túnel SSH, worker y diagnóstico.
- `docs/despliegue-produccion.md`: instalación paso a paso en el servidor oficial, verificación, respaldos y actualización.

## Estructura

```text
app/
  Controllers/DashboardController.php      # Control de la vista y errores de conexión
  Controllers/ManualReminderController.php # Alta/baja de contactos temporales
  Controllers/ChatController.php           # Bandeja de chats y envío de respuestas
  Controllers/BulkReminderController.php   # Envío masivo por fecha, confirmado una vez
  Repositories/AppointmentRepository.php   # Consultas PDO parametrizadas
  Repositories/ManualContactRepository.php # CRUD de contactos temporales
  Repositories/ChatRepository.php          # Conversaciones y mensajes del chat
  Services/DashboardService.php           # Filtros, fechas y paginación
  Services/ChatbotApiClient.php            # Cliente Gestionalo para texto y archivos
  Services/ReminderMessageBuilder.php      # Plantilla del recordatorio (puerto PHP de app.js)
  Services/SigesAppointmentSyncService.php # Importación incremental desde SIGES
config/database.php                       # Conexión por variables de entorno
docker-compose.yml                        # Base, web y worker en Docker (puertos solo en 127.0.0.1)
docker-compose.prod.yml                   # Ajustes del servidor oficial (SELinux, rotación de logs)
config/local.example.php                  # Plantilla de config/local.php (sin secretos)
scripts/verify-sync.php                   # Compara SIGES con la copia local (solo totales y huella)
scripts/verify-destinations.php           # Revisa que los celulares guardados coincidan con SIGES y tengan formato válido
scripts/verify-appointment-type.php       # Confirma que solo tipo NORMAL quede visible/enviable (ninguna ADICIONAL/otra)
docker/php/                               # Imagen PHP 8.2 + Apache (pdo_pgsql, php.ini de la app)
docker/postgres/20-roles.sh               # Crea los roles al inicializar el contenedor
docker/nginx/webhook-only.conf            # Proxy que solo expone POST /chat-webhook.php (perfil "webhook")
config/access.php                         # Filtro de acceso local compartido por public/
database/schema.sql                       # Esquema PostgreSQL de la base local
database/grants.sql                       # Permisos de los roles siges_app y siges_sync
database/migrations/                      # Migraciones PostgreSQL: 006 unaccent, 007 sede de prueba en contactos
                                            # temporales, 008 celular detectado desde SIGES, 009 tercera sede
database/mysql-legacy/                    # Esquema y migraciones MySQL anteriores (solo referencia)
database/seed.sql                         # Datos locales de SIGES (ignorado por Git)
database/extract_pending_appointments.sql # Consulta de lectura de SIGES
public/                                    # Único directorio que debe exponerse al navegador
  index.php                                # Agenda de citas RESERVADO
  send-reminder.php                        # Envío manual de recordatorio (POST)
  manual-reminders.php                     # Alta/baja de contactos temporales (POST)
  bulk-reminders.php                       # Envío masivo por fecha, con confirmación única (POST)
  messages.php                             # Historial de contactos enviados
  chats.php                                # Bandeja de chats (ver y responder)
  chat-messages.php                        # Sondeo JSON de conversaciones y mensajes
  chat-webhook.php                         # Receptor de mensajes entrantes de Gestionalo
  appointments-version.php                 # Versión local para la actualización del dashboard
  assets/                                   # CSS y JS del dashboard
scripts/sync-siges.php                     # Worker CLI que consulta cada 3 segundos
scripts/migrate-mysql-to-postgresql.php    # Copia única de datos desde la base MySQL anterior
views/dashboard.php                       # Interfaz
views/manual-reminders.php                # Interfaz de Recordatorios temporales
views/bulk-reminders.php                  # Interfaz de Envío masivo
views/sent-messages.php                   # Historial de contactos y recordatorios aceptados
views/chats.php                           # Interfaz de la bandeja de chats
```

## Requisitos

- PHP 8.1 o superior con extensiones `pdo_pgsql`, `mbstring`, `curl` y `fileinfo` (`pdo_mysql` solo para copiar datos de la base MySQL anterior).
- PostgreSQL 12+ para la base local `siges_dashboard`, separada de la base de SIGES.

## Preparar la base local

Con Docker (recomendado) todo corre en contenedores: base PostgreSQL, aplicación web (PHP + Apache en `http://127.0.0.1:8082/`) y worker de sincronización. Crea `.env` con `POSTGRES_PASSWORD`, `SIGES_APP_PASSWORD` y `SIGES_SYNC_PASSWORD` (ver `.env.example`), configura `config/local.php` y ejecuta `docker compose up -d --build`; la base aplica sola el esquema, los roles y los permisos. Detalles en `docs/operacion-local.md`. Sin Docker:

```sh
psql -U postgres -c "CREATE DATABASE siges_dashboard ENCODING 'UTF8' TEMPLATE template0"
psql -U postgres -d siges_dashboard -f database/schema.sql
psql -U postgres -d siges_dashboard -c "CREATE ROLE siges_app LOGIN PASSWORD 'usa-una-clave-segura'"
psql -U postgres -d siges_dashboard -c "CREATE ROLE siges_sync LOGIN PASSWORD 'otra-clave-segura'"
psql -U postgres -d siges_dashboard -f database/grants.sql
```

`siges_app` (la aplicación web) solo lee `appointments` y escribe en `reminder_messages`, `manual_contacts` y `chat_messages`. `siges_sync` (el worker) es el único que inserta y actualiza `appointments` y `siges_sync_state`. No uses `postgres` para ejecutar PHP.

No hace falta cargar datos: la primera ejecución del worker importa las citas de hoy en adelante desde SIGES. El antiguo `database/seed.sql` (ignorado por Git) era un volcado MySQL y no se aplica a PostgreSQL. Para pasar el historial de una instalación MySQL existente usa `scripts/migrate-mysql-to-postgresql.php`; los pasos están en `docs/operacion-local.md`.

El módulo **Recordatorios temporales** guarda contactos ingresados manualmente solo en la base local; no crea ni modifica personas o citas de SIGES. Permite quitar un contacto de la lista y conserva el registro de los mensajes aceptados en **Contactos enviados**.

La sede se deriva del código IPRESS del consultorio en SIGES: `00027276` corresponde a LIMA y `00033237` a LINCE. Dentro de LINCE hay una tercera ubicación física, "Consultorio 3 Lince" (Jr. Joaquín Bernal 344), que comparte el mismo IPRESS que los consultorios 1 y 2 pero se distingue por el nombre del consultorio, no por el código. El consultorio y el módulo también se conservan como detalle de ubicación. Revisa `database/extract_pending_appointments.sql` para la consulta de lectura de SIGES.

Configura las variables de entorno del proceso PHP según `.env.example`, o crea `config/local.php` (ya ignorado por Git) con un arreglo PHP que contenga `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD`, y para el worker `SIGES_SYNC_DB_USER` y `SIGES_SYNC_DB_PASSWORD`:

```text
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=siges_dashboard
DB_USER=siges_app
DB_PASSWORD=usa-una-clave-segura
APP_TIMEZONE=America/Lima
```

`APP_TIMEZONE` fija la zona horaria de cada sesión de PostgreSQL (local y SIGES) para que "hoy" sea el mismo día en ambas bases.

PHP no carga `.env` automáticamente; configura las variables en Apache/PHP-FPM, el contenedor o el servicio que inicie PHP.

## Iniciar en desarrollo

En PowerShell, después de configurar las variables:

```powershell
php -S 127.0.0.1:8080 -t public
```

Abre `http://127.0.0.1:8080` (puerto de ejemplo para desarrollo; `docs/operacion-local.md` describe el puerto `8082` usado en la instalación habitual). La búsqueda cubre paciente, médico, especialidad, sede, observación y motivo, sin distinguir mayúsculas ni tildes; cada palabra buscada debe aparecer en alguna columna. El filtro de sede y las fechas Desde/Hasta se aplican solos al elegirlos, sin botón "Filtrar". La lista se pagina de 25 en 25 y comprueba cambios locales cada 3 segundos.

## Cliente PHP de Gestionalo

El cliente PHP permite enviar texto, archivos base64 o un archivo local por `/chat/sendmedia/{celular}?number={serie}`. Requiere PHP cURL; no guardes claves en el repositorio ni en una URL que se muestre en el navegador:

```text
CHATBOT_BASE_URL=https://chatbot.gestionalo.pe
CHATBOT_LINE_KEY=serie_asignada_a_la_linea
CHATBOT_COUNTRY_CODE=51
```

El celular completo con código de país va en la ruta, la serie de la línea va en `number` y el texto se envía en `message` como formulario `application/x-www-form-urlencoded`. Este flujo PHP no envía un header `X-API-Key`. Los celulares peruanos locales de 9 dígitos reciben el prefijo `51` automáticamente. La aplicación usa una sola serie configurada; para seleccionar `$serie` por sucursal hace falta integrar el catálogo o procedimiento de sucursales de la otra aplicación. Guarda estas entradas en `config/local.php`, que es local y no se publica:

```php
'CHATBOT_LINE_KEY' => 'clave_asignada_a_la_linea',
'CHATBOT_COUNTRY_CODE' => '51',
```

Ejemplo de uso desde código PHP del servidor (requiere cargar el cliente y configurar primero las variables):

```php
require_once __DIR__ . '/../app/Services/ChatbotApiClient.php';

$chatbot = ChatbotApiClient::fromEnvironment();
$result = $chatbot->sendText('51999888777', 'Mensaje de prueba');
```

Los formularios de envío (agenda, Recordatorios temporales y Chats) permiten adjuntar una imagen opcional PNG/JPG de hasta 5 MB; con imagen se llama a `sendMedia()` usando el texto como `caption`. PHP debe permitir al menos 6 MB en `upload_max_filesize` y `post_max_size`.

Para enviar un archivo local, usa `$chatbot->sendFile($destino, $caption, $rutaDelArchivo)`. También está disponible `sendMedia()` cuando el contenido ya está codificado en base64.

El dashboard permite abrir **Enviar recordatorio** en una cita pendiente, revisar o corregir el celular (detectado automáticamente desde SIGES cuando está disponible) y revisar el mensaje antes del envío. El texto lo arma `buildReminderMessage()` en `app.js` (con un puerto idéntico en PHP, `ReminderMessageBuilder`, para el envío masivo); no incluye motivo clínico. Formato actual:

```text
*Hola, Nombre Del Paciente.*
Le recordamos que tiene una cita programada en la Clínica Arthrosalud.

📅*Fecha:* 16/10/2026
🕐*Hora:* 9:00 a. m.
🩺*Médico:* Dr(a). Nombre Del Médico
📍*Sede LINCE:* Av. P.º de la República 2772, Lince 15046
```

Las direcciones de las 3 sedes (LIMA, LINCE, LINCE - Consultorio 3) están en `SEDE_ADDRESSES` en `public/assets/js/app.js` y deben mantenerse iguales en `ReminderMessageBuilder.php`. No pide confirmar/reprogramar/cancelar por número mientras no se confirme el webhook de respuestas de Gestionalo (ver `docs/operacion-local.md`). Cada intento se registra en `reminder_messages`; el módulo **Contactos enviados** muestra los aceptados por Gestionalo. Los endpoints de envío e historial aceptan solicitudes solo desde el mismo equipo (`127.0.0.1`/`::1`). La aceptación de la API no confirma entrega o lectura en WhatsApp.

### Envío masivo por fecha

**Envío masivo** (`bulk-reminders.php`) permite enviar el recordatorio a todos los pacientes con cita `RESERVADO` en una fecha, en vez de abrir cada cita una por una. El celular se detecta automáticamente desde SIGES (`cita.contactos`); las citas sin un número reconocible no aparecen en la lista y hay que enviarlas desde **Citas pendientes** escribiendo el celular a mano. El flujo sigue exigiendo una persona: se revisa la lista completa (se puede excluir a cualquiera destildando su casilla), se confirma una sola vez y recién ahí se dispara el lote, con una pausa entre cada mensaje. No hay ningún envío programado ni automático sin que alguien lo confirme en el formulario.

### Sincronización de nuevas citas desde SIGES

Configura `SIGES_PG_HOST`, `SIGES_PG_PORT`, `SIGES_PG_DATABASE`, `SIGES_PG_USER`, `SIGES_PG_PASSWORD` y `SIGES_PG_SSLMODE` en `config/local.php`. Se recomienda un túnel SSH local hacia PostgreSQL (por ejemplo `127.0.0.1:15432`) y un rol PostgreSQL con permisos de solo lectura en las tablas/vista consultadas. `SIGES_SYNC_DB_USER` y `SIGES_SYNC_DB_PASSWORD` son credenciales de la base PostgreSQL local (rol `siges_sync`) para el worker; el instalador local las guarda aparte del usuario web.

Inicia una sola instancia del worker desde otra terminal de PowerShell:

```powershell
C:\xampp\php\php.exe C:\proyectos\Sistema-de-mensajes\scripts\sync-siges.php
```

La primera ejecución establece el máximo actual de `cita.id` e importa las citas de hoy en adelante. Cada 3 segundos el worker compara el ID, estado, fecha y hora de las citas vigentes; incorpora citas nuevas y actualiza o marca como no vigentes las que cambiaron o dejaron de estar programadas. El dashboard muestra solo registros enlazados a SIGES con estado `RESERVADO` y actualiza la vista al detectar cambios.

## Chats con pacientes

El módulo **Chats** (`chats.php`) muestra las conversaciones agrupadas por celular y permite responder. Las respuestas salen por `ChatbotApiClient`, igual que los recordatorios. La lista y el hilo abierto se actualizan solos (sondeo cada 3-4 segundos vía `chat-messages.php`), sin recargar la página ni perder lo que estés escribiendo.

### Mensajes entrantes (webhook)

Los mensajes que el paciente responde por WhatsApp llegan a `chat-webhook.php`. A diferencia del resto de `public/`, este archivo **debe ser accesible desde internet**, porque es Gestionalo quien lo llama; no tiene el filtro de IP local que sí tienen `chats.php`, `send-reminder.php`, etc.

1. Define `CHATBOT_WEBHOOK_TOKEN` (un token largo y aleatorio) en `config/local.php` o como variable de entorno.
2. En el panel de Gestionalo, configura como webhook de la línea:
   ```text
   https://tu-dominio-publico/chat-webhook.php?token=TU_TOKEN
   ```
3. El servidor debe quedar expuesto a internet con ese dominio (HTTPS), apuntado al proxy restringido (`docker compose --profile webhook up -d webhook-proxy`), nunca directo al puerto de la aplicación: ese proxy solo deja pasar `POST /chat-webhook.php` y evita exponer el resto del dashboard. Para probarlo en desarrollo sin dominio propio, usa un túnel temporal (`cloudflared`) apuntado al proxy. Detalles en `docs/operacion-local.md`.

**El formato exacto del payload que envía Gestionalo todavía no está confirmado.** `chat-webhook.php` trae un bloque claramente marcado (`AJUSTA SOLO ESTE BLOQUE`) donde se extraen `destination`, `body` y, si existe, un id de mensaje del payload recibido; los nombres de campo usados ahí son una suposición razonable. En vez de esperar documentación de Gestionalo, el archivo incluye un **modo diagnóstico** (`CHATBOT_WEBHOOK_DEBUG` en `config/local.php`, apagado por defecto) que, al recibir un mensaje real, registra solo los nombres de los campos del payload y su tipo PHP —nunca el celular ni el texto— para poder ajustar el bloque con datos reales. El resto del archivo (verificación del token, idempotencia, respuesta) no necesita cambios.

## Privacidad

Los datos de pacientes y celulares son sensibles. Este prototipo no implementa inicio de sesión ni permisos por rol: ejecútalo solo en una red confiable o detrás del mecanismo de autenticación del hospital. No publiques `database/`, `config/` ni copias del archivo de datos en el directorio web. La aplicación lee citas de SIGES y solo escribe en su base PostgreSQL local: el registro de intentos de recordatorio (`reminder_messages`), los contactos temporales (`manual_contacts`) y los mensajes de chat (`chat_messages`).
