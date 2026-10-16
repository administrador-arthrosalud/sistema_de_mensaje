# Arquitectura y flujo de datos

## Componentes

```text
SIGES (PostgreSQL en Rocky, rol de solo lectura)
                  │ túnel SSH local :15432
                  ▼
scripts/sync-siges.php ── cada 3 s ──► PostgreSQL local: siges_dashboard
                                              │
                                              ├── public/index.php → agenda RESERVADO
                                              ├── public/messages.php → historial aceptado
                                              ├── public/manual-reminders.php → contactos temporales
                                              └── public/chats.php → bandeja de chats

Gestionalo (WhatsApp) ── respuesta del paciente ──► public/chat-webhook.php ──► PostgreSQL local: chat_messages
                                                                                      │
                                                                                      └── public/chats.php (lee y responde)
```

En la instalación con Docker, la base local, la aplicación web y el worker corren en tres contenedores (`postgres`, `web`, `sync`) definidos en `docker-compose.yml`; ver `docs/operacion-local.md`. El filtro de acceso local de `public/` está en `config/access.php` (`isLocalRequest()`): acepta `127.0.0.1`, `::1` y las IP de `LOCAL_ACCESS_EXTRA_IPS`, que en Docker es la puerta de enlace de la red porque el puerto se publica solo en `127.0.0.1` del equipo.

El worker es la única pieza que lee la base PostgreSQL de SIGES. Su sesión en SIGES se abre con `SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY`, como segunda barrera además del rol de solo lectura. La aplicación PHP usa una base PostgreSQL local propia (`siges_dashboard`), distinta de la de SIGES y con roles propios; SIGES no se consulta desde JavaScript. Todas las conexiones fijan la zona horaria de sesión (`APP_TIMEZONE`, por defecto `America/Lima`) para que `CURRENT_DATE` sea el mismo día en SIGES y en la base local. `chat-webhook.php` es la única excepción al acceso solo local: Gestionalo debe poder llamarlo desde internet para entregar las respuestas de los pacientes; se protege con un token secreto en vez del filtro de IP que usa el resto de `public/`.

## Regla de la agenda

La agenda operativa solo incluye filas que cumplen las tres condiciones:

1. `siges_cita_id IS NOT NULL`: proceden de una cita real leída desde SIGES.
2. `status = 'RESERVADO'`: es el estado fuente exacto; `ATENDIDO`, `ANULADO`, `BLOQUEADO` y cualquier otro estado se excluyen.
   - Solo se copian a la base local las citas con `cita.tipo = 'NORMAL'` en SIGES (lista blanca, no solo excluir `ADICIONAL`): `SigesAppointmentSyncService` lo filtra en el origen (`fetchAppointments()` y `fetchCurrentAppointmentSnapshots()`). SIGES también tiene cupos `ADICIONAL` (fuera del horario regular del médico) y otros tipos como `PROCEDIMIENTO`; ninguno de esos llega a `appointments` ni puede aparecer en la agenda o el envío masivo. Si una cita que ya estaba sincronizada deja de ser `NORMAL` en SIGES, desaparece de los snapshots y el worker la marca `NO_VIGENTE` igual que una cita que ya no existe.
3. La fecha y hora aún no han pasado: `appointment_date > CURRENT_DATE OR (appointment_date = CURRENT_DATE AND appointment_time >= LOCALTIME)`, con la zona horaria de `APP_TIMEZONE`. Una cita de hoy cuya hora ya pasó sale de la agenda aunque SIGES la siga marcando `RESERVADO` (por ejemplo, un paciente que no asistió y cuyo estado nadie actualizó).

Los filtros de fecha pueden acotar ese rango futuro, pero no deben volver a incluir fechas u horas pasadas. El sincronizador sigue copiando todas las citas desde hoy; el corte por hora solo se aplica al mostrar y al enviar. El envío del recordatorio valida de nuevo que la cita siga reservada.

La agenda también se puede filtrar por sede (`sede_nombre = :sede`, `AppointmentRepository::buildWhere()`), validada en `DashboardService::SEDES` contra las 3 sedes conocidas; cualquier otro valor se ignora. El filtro de sede y las fechas Desde/Hasta se autoenvían al cambiar (evento `change`); la búsqueda de texto sigue necesitando Enter, para no recargar a mitad de palabra.

## Sincronización de estados

`SigesAppointmentSyncService` obtiene de PostgreSQL el ID, estado, fecha y hora de las citas desde hoy. Compara ese resumen con los registros locales y vuelve a leer el detalle únicamente de citas nuevas o modificadas. Si una cita desaparece del rango vigente, la marca localmente `NO_VIGENTE`. La actualización nunca escribe en SIGES.

El worker conserva el último ID en `siges_sync_state`, pero también compara las citas existentes para detectar cambios de estado, reprogramaciones o anulaciones. El dashboard sondea la versión local cada 3 segundos y actualiza su vista al detectar cambios.

## Recordatorios y contactos

- Las citas SIGES se muestran en la agenda. El destinatario y el texto se revisan antes de enviar.
- `appointments.destination` guarda un celular extraído de `cita.contactos` en SIGES (JSON de texto, p. ej. `["987654321"]`; `SigesAppointmentSyncService::extractDestination()` se queda con el primer número que parezca un celular peruano válido). Es un punto de partida editable, no un dato de confianza ciega: sigue mostrándose para que una persona lo revise antes de enviar. Puede ser `NULL` si SIGES no trae un número reconocible.
- **Envío masivo** (`bulk-reminders.php`, `BulkReminderController`): lista, para una fecha elegida, las citas `RESERVADO` con celular detectado que todavía no tienen un recordatorio `sent`. El texto de cada mensaje lo genera `ReminderMessageBuilder` (puerto en PHP de `buildReminderMessage()` de `app.js`; si cambias la plantilla, cámbiala en los dos lados). Sigue exigiendo una acción humana: la persona revisa la lista completa, puede excluir filas, y confirma una sola vez con una casilla antes de que se dispare el lote. No existe ningún disparador automático/programado — violaría la regla de AGENTS.md de que todo envío requiere confirmación explícita de una persona. Entre cada envío hay una pausa (`BulkReminderController::PACE_MICROSECONDS`) porque no hay documentación pública de los límites de ráfaga de Gestionalo.
- `manual_contacts` guarda registros creados desde **Recordatorios temporales**. Son locales y nunca se exportan a SIGES.
- `reminder_messages` conserva el resultado aceptado o fallido. `origin` distingue `siges` de `manual`; `manual_contact_id` enlaza envíos temporales.
- La API se llama únicamente desde PHP mediante `ChatbotApiClient`, usando la clave de línea en el parámetro `number`. Nunca pongas secretos en JavaScript.
- La aplicación distingue 3 sedes, no 2: LIMA, LINCE y "LINCE - CONSULTORIO 3" (Jr. Joaquín Bernal 344, una dirección propia aunque el consultorio comparta el código IPRESS de LINCE). Las direcciones del mensaje viven en `SEDE_ADDRESSES` en `public/assets/js/app.js` y en `ReminderMessageBuilder.php`; los dos deben mantenerse iguales.
- Los tres formularios de envío (agenda, recordatorios temporales y chats) aceptan una imagen opcional PNG/JPG de hasta 5 MB. `MessageAttachment` valida el tipo real y el tamaño; `ChatbotApiClient::sendMessage()` usa `sendText()` sin imagen o `sendMedia()` (caption, type, title, media) con imagen. La imagen no se guarda en disco ni en la base de datos; en `chat_messages` solo queda `[Imagen: título]` antes del texto.
- “Aceptado por Gestionalo” confirma que la API aceptó la solicitud, no que WhatsApp entregó o leyó el mensaje.

## Chats con pacientes

- `chat_messages` guarda cada mensaje entrante (`direction = 'inbound'`) y saliente (`direction = 'outbound'`) por celular (`destination`).
- Los mensajes entrantes llegan por el webhook de Gestionalo a `chat-webhook.php`, que los inserta con `ChatRepository::insertInbound()`. Si Gestionalo entrega un identificador de mensaje (`provider_message_id`), un reintento del mismo webhook no duplica la fila (clave única que permite múltiples `NULL`).
- El nombre del paciente que se muestra en la bandeja es una suposición: se toma el `patient_name` más reciente de `reminder_messages` para ese celular. Si nunca se le envió un recordatorio, se muestra solo el número.
- `chats.php` responde usando el mismo `ChatbotApiClient` que los recordatorios; cada intento se registra en `chat_messages` con `status` `pending`/`sent`/`failed`, igual que en `reminder_messages`.
- La bandeja y el hilo abierto se actualizan por sondeo (`chat-messages.php`, JSON) cada 3-4 segundos, sin recargar la página.

## Esquema local

- `appointments`: copia local de citas y ubicación; clave SIGES única en `siges_cita_id`.
- `siges_sync_state`: cursor y estado del worker.
- `reminder_messages`: historial de solicitudes de envío.
- `manual_contacts`: personas y citas ingresadas temporalmente por el usuario.
- `chat_messages`: mensajes entrantes y salientes del chat, por celular.

`database/schema.sql` es el esquema base de PostgreSQL y `database/grants.sql` los permisos de los roles `siges_app` y `siges_sync`. Las migraciones van en `database/migrations/`; la última aplicada es `009_` (006 `unaccent`, 007 sede de prueba en `manual_contacts`, 008 `appointments.destination`, 009 tercera sede en `manual_contacts`). Mantén `schema.sql` alineado con ellas. `database/mysql-legacy/` conserva el esquema y las migraciones 001-005 de MySQL solo como referencia, y `scripts/migrate-mysql-to-postgresql.php` copia una vez los datos de la base MySQL anterior.

Diferencias con MySQL a tener en cuenta: la búsqueda de la agenda usa `unaccent(...) ILIKE unaccent(...)` (extensión `unaccent`, migración 006), así que no distingue mayúsculas ni tildes; cada palabra buscada debe aparecer en alguna columna. El historial de envíos usa `ILIKE` (sí distingue tildes). `updated_at` se mantiene con un trigger y los id nuevos se obtienen con `RETURNING id`.
