# Instrucciones para agentes

Estas reglas aplican a cualquier agente que trabaje en este proyecto. **Lee primero `docs/estado-del-proyecto.md`** (qué está desplegado, qué se decidió y por qué, qué falta, trampas del entorno) y después `docs/arquitectura.md` antes de cambiar sincronización, estados o recordatorios. Al terminar un trabajo relevante, **actualiza `docs/estado-del-proyecto.md`** para que el siguiente agente no pierda el contexto.

## Reglas obligatorias

- Mantén SIGES en solo lectura. La aplicación y los workers pueden consultar PostgreSQL, pero no deben insertar, actualizar ni borrar datos allí.
- La agenda muestra únicamente citas enlazadas a SIGES (`siges_cita_id IS NOT NULL`), con estado exacto `RESERVADO`, tipo exacto `NORMAL` (`cita.tipo` en SIGES; excluye siempre `ADICIONAL`, `PROCEDIMIENTO` y cualquier otro tipo — es lista blanca, no solo excluir `ADICIONAL`) y cuya fecha y hora aún no han pasado (hoy a partir de la hora actual, o fechas posteriores). No cambies esta regla sin una petición explícita del usuario.
- El sincronizador compara citas de hoy en adelante cada 3 segundos. Debe actualizar estado, fecha y hora de citas existentes, no limitarse a incorporar IDs nuevos.
- La base local de la aplicación es PostgreSQL (`siges_dashboard`), separada de la base de SIGES; nunca reutilices la base ni las credenciales de SIGES para escribir.
- El navegador consulta la versión de la base local a través de PHP; nunca debe conectarse directamente a una base de datos ni llamar a SIGES desde JavaScript.
- Los contactos manuales viven en `manual_contacts` y no se sincronizan con SIGES. Los envíos manuales y de SIGES deben distinguirse con `reminder_messages.origin`.
- Nunca envíes un mensaje real como parte de pruebas o validaciones. El envío requiere una acción explícita de una persona en el formulario y su confirmación.
- Trata nombres, celulares, citas y el contenido de los chats (`chat_messages`) como datos de salud sensibles. No los incluyas en logs, ejemplos, commits, capturas ni respuestas salvo que sea imprescindible para resolver una solicitud concreta. `chat-webhook.php` nunca debe registrar el payload completo ni el texto del mensaje en `error_log`. Su modo diagnóstico (`CHATBOT_WEBHOOK_DEBUG`) es la única excepción permitida: registra solo nombres de campo y tipo PHP (`payloadShape()`), nunca valores; no le agregues el registro de ningún valor del payload, y está apagado por defecto.
- Un túnel o proxy público apuntado a `chat-webhook.php` debe restringirse a esa sola ruta (ver `docker/nginx/webhook-only.conf`, perfil `webhook`). Nunca expongas directamente el puerto de la aplicación completa (`web`/8082): eso publicaría también la agenda, los chats y el envío de mensajes.
- `public/chat-webhook.php` es la única excepción a "acceso solo local": debe ser alcanzable desde internet porque Gestionalo lo llama para entregar respuestas de pacientes. Se protege únicamente con el token `CHATBOT_WEBHOOK_TOKEN`; no le quites esa verificación ni le agregues el filtro de IP local que tienen los demás archivos de `public/`.
- No leas, imprimas, copies ni incluyas valores de `config/local.php`. No incluyas contraseñas, claves de Gestionalo ni datos de acceso en documentación o código versionable. `config/local.php` está ignorado por Git.
- No expongas al navegador `config/`, `database/`, `scripts/` ni archivos de datos. El document root debe seguir siendo `public/`.

- El celular se guarda en `appointments.destination` de **9 dígitos, sin el 51** (`SigesAppointmentSyncService::extractDestination()` descarta además los de relleno como `999999999`); `ChatbotApiClient::normalizeDestination()` antepone el 51 al enviar. No guardes el 51 en `appointments.destination` ni envíes sin él.
- Si Gestionalo responde `No LID for user`, el número no tiene WhatsApp: se guarda `reminder_messages.failure_reason = 'no_whatsapp'` y ese número se excluye del envío masivo hasta que cambie en SIGES. No lo reintentes en bucle.
- Los intentos de envío de citas SIGES pasan por `AppointmentRepository::createReminderAttempt()` (bloqueo por cita y `DuplicateSendException`); no insertes en `reminder_messages` por otro camino.
- Tras tocar repositorios o controladores de envío ejecuta `docker exec siges-dashboard-web php scripts/verify-writes.php`; tras cambios relevantes, `scripts/smoke-test-produccion.sh` y `scripts/security-test.sh`.

## Forma de trabajo

- Conserva la arquitectura PHP existente: controladores HTTP en `app/Controllers`, acceso PDO en `app/Repositories`, lógica externa en `app/Services`, páginas en `views/` y puntos de entrada en `public/`.
- Usa consultas parametrizadas. Escapa el contenido dinámico al renderizar HTML.
- Todas las páginas y endpoints de `public/` (salvo `login.php`, `logout.php` y `chat-webhook.php`) deben llamar a `requireLogin()` de `config/auth.php` justo después del filtro `isLocalRequest()` (`requireLogin(true)` en los endpoints JSON). No guardes ni imprimas contraseñas de usuarios; se gestionan con `scripts/manage-users.php`. Los envíos registran `sent_by_user_id` con `currentUserId()`.
- Mantén el acceso local limitado a `127.0.0.1` y `::1` salvo petición explícita de autenticación/red. Usa `isLocalRequest()` de `config/access.php`; la única IP adicional permitida es la puerta de enlace de la red Docker (`LOCAL_ACCESS_EXTRA_IPS`), y los puertos de `docker-compose.yml` deben publicarse solo en `127.0.0.1`.
- No añadas dependencias ni cambies versiones de PHP/PostgreSQL sin necesidad concreta.
- Si modificas el esquema, agrega una migración numerada para PostgreSQL en `database/migrations/` (la última es `014_`; la siguiente va en `015_`) y actualiza `database/schema.sql`, `database/grants.sql` si aplica y la documentación. `database/mysql-legacy/` es solo histórico.
- Si cambias la plantilla del recordatorio (`buildReminderMessage()` en `public/assets/js/app.js`), replica el cambio en `app/Services/ReminderMessageBuilder.php` (usado por el envío masivo); deben producir el mismo texto.
- Si se solicita validación, no uses pacientes reales ni envíes mensajes. Comprueba solo estructura, sintaxis y consultas agregadas cuando sea suficiente.

## Referencias

- Estado, decisiones, entorno real y trampas técnicas: `docs/estado-del-proyecto.md`.
- Seguridad: `docs/auditoria-seguridad.md` y la prueba `scripts/security-test.sh`.
- Pendientes priorizados: `docs/mejoras-pendientes.md`.

- Arquitectura y reglas de datos: `docs/arquitectura.md`.
- Arranque local y túnel SIGES: `docs/operacion-local.md`.
- Instalación y operación en el servidor oficial: `docs/despliegue-produccion.md` (síguela paso a paso; en el servidor usa siempre `docker-compose.yml` + `docker-compose.prod.yml`).
- Verificación SIGES ↔ copia local sin datos de pacientes: `scripts/verify-sync.php` (totales y huella), `scripts/verify-destinations.php` (celulares) y `scripts/verify-appointment-type.php` (solo tipo NORMAL visible).
- Consulta de extracción: `database/extract_pending_appointments.sql`.
- Sincronización: `app/Services/SigesAppointmentSyncService.php` y `scripts/sync-siges.php`.
