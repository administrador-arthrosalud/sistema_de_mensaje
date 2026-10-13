# Arquitectura y flujo de datos

## Componentes

```text
SIGES (PostgreSQL en Rocky, rol de solo lectura)
                  │ túnel SSH local :15432
                  ▼
scripts/sync-siges.php ── cada 3 s ──► MySQL local: siges_dashboard
                                              │
                                              ├── public/index.php → agenda RESERVADO
                                              ├── public/messages.php → historial aceptado
                                              ├── public/manual-reminders.php → contactos temporales
                                              └── public/chats.php → bandeja de chats

Gestionalo (WhatsApp) ── respuesta del paciente ──► public/chat-webhook.php ──► MySQL local: chat_messages
                                                                                      │
                                                                                      └── public/chats.php (lee y responde)
```

El worker es la única pieza que lee PostgreSQL de SIGES. La aplicación PHP del navegador lee MySQL; SIGES no se consulta desde JavaScript. `chat-webhook.php` es la única excepción al acceso solo local: Gestionalo debe poder llamarlo desde internet para entregar las respuestas de los pacientes; se protege con un token secreto en vez del filtro de IP que usa el resto de `public/`.

## Regla de la agenda

La agenda operativa solo incluye filas que cumplen las tres condiciones:

1. `siges_cita_id IS NOT NULL`: proceden de una cita real leída desde SIGES.
2. `status = 'RESERVADO'`: es el estado fuente exacto; `ATENDIDO`, `ANULADO`, `BLOQUEADO` y cualquier otro estado se excluyen.
3. `appointment_date >= CURRENT_DATE`: la agenda operativa muestra hoy y fechas futuras.

Los filtros de fecha pueden acotar ese rango futuro, pero no deben volver a incluir fechas pasadas. El envío del recordatorio valida de nuevo que la cita siga reservada.

## Sincronización de estados

`SigesAppointmentSyncService` obtiene de PostgreSQL el ID, estado, fecha y hora de las citas desde hoy. Compara ese resumen con los registros locales y vuelve a leer el detalle únicamente de citas nuevas o modificadas. Si una cita desaparece del rango vigente, la marca localmente `NO_VIGENTE`. La actualización nunca escribe en SIGES.

El worker conserva el último ID en `siges_sync_state`, pero también compara las citas existentes para detectar cambios de estado, reprogramaciones o anulaciones. El dashboard sondea la versión local cada 3 segundos y actualiza su vista al detectar cambios.

## Recordatorios y contactos

- Las citas SIGES se muestran en la agenda. El destinatario y el texto se revisan antes de enviar.
- `manual_contacts` guarda registros creados desde **Recordatorios temporales**. Son locales y nunca se exportan a SIGES.
- `reminder_messages` conserva el resultado aceptado o fallido. `origin` distingue `siges` de `manual`; `manual_contact_id` enlaza envíos temporales.
- La API se llama únicamente desde PHP mediante `ChatbotApiClient`, usando la clave de línea en el parámetro `number`. Nunca pongas secretos en JavaScript.
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

Las migraciones viven en `database/migrations/`. Mantén `database/schema.sql` alineado con las migraciones.
