# SIGES · Dashboard de citas pendientes

Aplicación PHP con PDO y MySQL para consultar citas programadas. La pantalla muestra solo registros `RESERVADO` (pendientes), con paciente, fecha, hora, médico, especialidad, observación y motivo clínico cuando existe.

## Guías del proyecto

- `AGENTS.md`: reglas obligatorias para agentes que trabajen en el código.
- `docs/arquitectura.md`: flujo SIGES → worker → MySQL → dashboard y reglas de estados.
- `docs/operacion-local.md`: XAMPP, túnel SSH, worker y diagnóstico.

## Estructura

```text
app/
  Controllers/DashboardController.php      # Control de la vista y errores de conexión
  Controllers/ManualReminderController.php # Alta/baja de contactos temporales
  Controllers/ChatController.php           # Bandeja de chats y envío de respuestas
  Repositories/AppointmentRepository.php   # Consultas PDO parametrizadas
  Repositories/ManualContactRepository.php # CRUD de contactos temporales
  Repositories/ChatRepository.php          # Conversaciones y mensajes del chat
  Services/DashboardService.php           # Filtros, fechas y paginación
  Services/ChatbotApiClient.php            # Cliente Gestionalo para texto y archivos
  Services/SigesAppointmentSyncService.php # Importación incremental desde SIGES
config/database.php                       # Conexión por variables de entorno
database/schema.sql                       # Base y tabla MySQL
database/migrations/                      # Cambios incrementales de esquema
database/seed.sql                         # Datos locales de SIGES (ignorado por Git)
database/extract_pending_appointments.sql # Consulta de lectura de SIGES
public/                                    # Único directorio que debe exponerse al navegador
  index.php                                # Agenda de citas RESERVADO
  send-reminder.php                        # Envío manual de recordatorio (POST)
  manual-reminders.php                     # Alta/baja de contactos temporales (POST)
  messages.php                             # Historial de contactos enviados
  chats.php                                # Bandeja de chats (ver y responder)
  chat-messages.php                        # Sondeo JSON de conversaciones y mensajes
  chat-webhook.php                         # Receptor de mensajes entrantes de Gestionalo
  appointments-version.php                 # Versión local para la actualización del dashboard
  assets/                                   # CSS y JS del dashboard
scripts/sync-siges.php                     # Worker CLI que consulta cada 3 segundos
views/dashboard.php                       # Interfaz
views/manual-reminders.php                # Interfaz de Recordatorios temporales
views/sent-messages.php                   # Historial de contactos y recordatorios aceptados
views/chats.php                           # Interfaz de la bandeja de chats
```

## Requisitos

- PHP 8.1 o superior con extensiones `pdo_mysql`, `pdo_pgsql`, `mbstring`, `curl` y `fileinfo`.
- MySQL 5.7+ o compatible.

## Preparar la base local

El archivo `database/seed.sql` contiene nombres y datos de citas. Se genera localmente a partir del resultado SIGES compartido en esta sesión y está excluido de Git.

```sh
mysql -u root -p < database/schema.sql
mysql -u root -p siges_dashboard < database/seed.sql
```

Para actualizar una instalación que aún no tiene las columnas de ubicación:

```sh
mysql -u root -p siges_dashboard < database/migrations/001_add_appointment_location.sql
```

Para habilitar el historial y el semáforo de envíos:

```sh
mysql -u root -p siges_dashboard < database/migrations/002_create_reminder_messages.sql
```

Para preparar la sincronización incremental desde SIGES:

```sh
mysql -u root -p siges_dashboard < database/migrations/003_add_siges_incremental_sync.sql
```

Para habilitar el módulo local de recordatorios temporales:

```sh
mysql -u root -p siges_dashboard < database/migrations/004_create_manual_reminders.sql
```

Para habilitar la bandeja de chats:

```sh
mysql -u root -p siges_dashboard < database/migrations/005_create_chat_messages.sql
```

El módulo **Recordatorios temporales** guarda contactos ingresados manualmente solo en MySQL local; no crea ni modifica personas o citas de SIGES. Permite quitar un contacto de la lista y conserva el registro de los mensajes aceptados en **Contactos enviados**.

La sede se deriva del código IPRESS del consultorio en SIGES: `00027276` corresponde a LIMA y `00033237` a LINCE en la lectura cargada. El consultorio y el módulo también se conservan como detalle de ubicación. Revisa `database/extract_pending_appointments.sql` para la consulta de lectura de SIGES.

Crea un usuario con acceso solo de lectura a las citas de esta aplicación (no uses `root` para ejecutar PHP):

```sql
CREATE USER 'siges_app'@'localhost' IDENTIFIED BY 'usa-una-clave-segura';
GRANT SELECT ON siges_dashboard.appointments TO 'siges_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON siges_dashboard.reminder_messages TO 'siges_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON siges_dashboard.manual_contacts TO 'siges_app'@'localhost';
FLUSH PRIVILEGES;
```

El worker (`scripts/sync-siges.php`) usa credenciales propias (`SIGES_SYNC_DB_USER` / `SIGES_SYNC_DB_PASSWORD`) con permisos de escritura, porque es el único proceso que inserta y actualiza `appointments` y `siges_sync_state`:

```sql
CREATE USER 'siges_sync'@'localhost' IDENTIFIED BY 'otra-clave-segura';
GRANT SELECT, INSERT, UPDATE ON siges_dashboard.appointments TO 'siges_sync'@'localhost';
GRANT SELECT, INSERT, UPDATE ON siges_dashboard.siges_sync_state TO 'siges_sync'@'localhost';
FLUSH PRIVILEGES;
```

Configura las variables de entorno del proceso PHP según `.env.example`, o crea `config/local.php` (ya ignorado por Git) con un arreglo PHP que contenga `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD`:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=siges_dashboard
DB_USER=siges_app
DB_PASSWORD=usa-una-clave-segura
```

PHP no carga `.env` automáticamente; configura las variables en Apache/PHP-FPM, el contenedor o el servicio que inicie PHP.

## Iniciar en desarrollo

En PowerShell, después de configurar las variables:

```powershell
php -S 127.0.0.1:8080 -t public
```

Abre `http://127.0.0.1:8080` (puerto de ejemplo para desarrollo; `docs/operacion-local.md` describe el puerto `8082` usado en la instalación habitual). La búsqueda cubre paciente, médico, especialidad, observación y motivo; las fechas filtran por rango. La lista se pagina de 25 en 25 y comprueba cambios locales cada 3 segundos.

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

Para enviar un archivo local, usa `$chatbot->sendFile($destino, $caption, $rutaDelArchivo)`. También está disponible `sendMedia()` cuando el contenido ya está codificado en base64.

El dashboard permite abrir **Enviar recordatorio** en una cita pendiente, escribir el celular y revisar el mensaje antes del envío. El texto incluye únicamente nombre, fecha y hora; no incluye motivo clínico. Cada intento se registra en `reminder_messages`; el módulo **Contactos enviados** muestra los aceptados por Gestionalo. Los endpoints de envío e historial aceptan solicitudes solo desde el mismo equipo (`127.0.0.1`/`::1`). La aceptación de la API no confirma entrega o lectura en WhatsApp.

### Sincronización de nuevas citas desde SIGES

Configura `SIGES_PG_HOST`, `SIGES_PG_PORT`, `SIGES_PG_DATABASE`, `SIGES_PG_USER`, `SIGES_PG_PASSWORD` y `SIGES_PG_SSLMODE` en `config/local.php`. Se recomienda un túnel SSH local hacia PostgreSQL (por ejemplo `127.0.0.1:15432`) y un rol PostgreSQL con permisos de solo lectura en las tablas/vista consultadas. `SIGES_SYNC_DB_USER` y `SIGES_SYNC_DB_PASSWORD` son credenciales locales de MySQL para el worker; el instalador local las guarda aparte del usuario web.

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
3. El servidor debe quedar expuesto a internet con ese dominio (HTTPS). Para probarlo en desarrollo sin publicar nada, puedes usar un túnel temporal (por ejemplo `cloudflared` o `ngrok`) que apunte a tu `127.0.0.1:8082`.

**El formato exacto del payload que envía Gestionalo todavía no está confirmado.** `chat-webhook.php` trae un bloque claramente marcado (`AJUSTA SOLO ESTE BLOQUE`) donde se extraen `destination`, `body` y, si existe, un id de mensaje del payload recibido; los nombres de campo usados ahí son una suposición razonable mientras se confirma la documentación real de Gestionalo. El resto del archivo (verificación del token, idempotencia, respuesta) no necesita cambios.

## Privacidad

Los datos de pacientes y celulares son sensibles. Este prototipo no implementa inicio de sesión ni permisos por rol: ejecútalo solo en una red confiable o detrás del mecanismo de autenticación del hospital. No publiques `database/`, `config/` ni copias del archivo de datos en el directorio web. La aplicación lee citas de SIGES y solo escribe en MySQL local: el registro de intentos de recordatorio (`reminder_messages`), los contactos temporales (`manual_contacts`) y los mensajes de chat (`chat_messages`).
