# Instrucciones para agentes

Estas reglas aplican a cualquier agente que trabaje en este proyecto. Lee también `docs/arquitectura.md` antes de cambiar sincronización, estados o recordatorios.

## Reglas obligatorias

- Mantén SIGES en solo lectura. La aplicación y los workers pueden consultar PostgreSQL, pero no deben insertar, actualizar ni borrar datos allí.
- La agenda muestra únicamente citas enlazadas a SIGES (`siges_cita_id IS NOT NULL`), con estado exacto `RESERVADO` y fecha de hoy o posterior. No cambies esta regla sin una petición explícita del usuario.
- El sincronizador compara citas de hoy en adelante cada 3 segundos. Debe actualizar estado, fecha y hora de citas existentes, no limitarse a incorporar IDs nuevos.
- El navegador consulta la versión de MySQL local; nunca debe conectarse directamente a PostgreSQL ni llamar a SIGES desde JavaScript.
- Los contactos manuales viven en `manual_contacts` y no se sincronizan con SIGES. Los envíos manuales y de SIGES deben distinguirse con `reminder_messages.origin`.
- Nunca envíes un mensaje real como parte de pruebas o validaciones. El envío requiere una acción explícita de una persona en el formulario y su confirmación.
- Trata nombres, celulares, citas y el contenido de los chats (`chat_messages`) como datos de salud sensibles. No los incluyas en logs, ejemplos, commits, capturas ni respuestas salvo que sea imprescindible para resolver una solicitud concreta. `chat-webhook.php` nunca debe registrar el payload completo ni el texto del mensaje en `error_log`.
- `public/chat-webhook.php` es la única excepción a "acceso solo local": debe ser alcanzable desde internet porque Gestionalo lo llama para entregar respuestas de pacientes. Se protege únicamente con el token `CHATBOT_WEBHOOK_TOKEN`; no le quites esa verificación ni le agregues el filtro de IP local que tienen los demás archivos de `public/`.
- No leas, imprimas, copies ni incluyas valores de `config/local.php`. No incluyas contraseñas, claves de Gestionalo ni datos de acceso en documentación o código versionable. `config/local.php` está ignorado por Git.
- No expongas al navegador `config/`, `database/`, `scripts/` ni archivos de datos. El document root debe seguir siendo `public/`.

## Forma de trabajo

- Conserva la arquitectura PHP existente: controladores HTTP en `app/Controllers`, acceso PDO en `app/Repositories`, lógica externa en `app/Services`, páginas en `views/` y puntos de entrada en `public/`.
- Usa consultas parametrizadas. Escapa el contenido dinámico al renderizar HTML.
- Mantén el acceso local limitado a `127.0.0.1` y `::1` salvo petición explícita de autenticación/red.
- No añadas dependencias ni cambies versiones de PHP/MySQL sin necesidad concreta.
- Si modificas el esquema, agrega una migración numerada y actualiza `database/schema.sql` y la documentación.
- Si se solicita validación, no uses pacientes reales ni envíes mensajes. Comprueba solo estructura, sintaxis y consultas agregadas cuando sea suficiente.

## Referencias

- Arquitectura y reglas de datos: `docs/arquitectura.md`.
- Arranque local y túnel SIGES: `docs/operacion-local.md`.
- Consulta de extracción: `database/extract_pending_appointments.sql`.
- Sincronización: `app/Services/SigesAppointmentSyncService.php` y `scripts/sync-siges.php`.
