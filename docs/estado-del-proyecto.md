# Estado del proyecto y contexto para agentes

> **Lee esto primero.** Resume qué es el sistema, dónde está desplegado, qué se ha hecho, por qué se decidió cada cosa y qué falta. Después lee `AGENTS.md` (reglas obligatorias) y `docs/arquitectura.md`. Última actualización: **2026-10-29**.

## 1. Qué es

Aplicación PHP 8.2 + PostgreSQL (sin framework ni dependencias) de la **Clínica de Traumatología Arthrosalud**. Se muestra con el nombre **"Chatbot"** (antes "SIGES"; el nombre visible cambió, el código y las rutas no).

- Copia cada 3 s las citas de **SIGES** (sistema clínico, **solo lectura**) a una base local `siges_dashboard`.
- Permite enviar **recordatorios por WhatsApp** (API de Gestionalo): uno por uno, en lote por fecha (envío masivo) o a contactos temporales.
- Tiene una bandeja de **chats** (respuestas de pacientes por webhook; el formato del webhook sigue sin confirmarse).
- Tiene **inicio de sesión** con usuarios en base de datos y una pantalla **Estado del sistema** solo para administradores.

Es un **demo en producción**: ya está corriendo y otras PCs de la red lo usan para verificarlo.

## 2. Dónde está desplegado (entorno real)

| Dato | Valor |
|---|---|
| Equipo | PC Windows 11 `DESKTOP-1C086N3`, IP LAN `192.168.1.71`, Docker Desktop |
| URL | `http://192.168.1.71:8091/` (HTTP, sin HTTPS todavía) |
| Carpeta | `C:\proyectos\Chatbot-V.0.1\sistema_de_mensaje` (repositorio Git **anidado**; el repositorio exterior `Chatbot-V.0.1` no tiene commits) |
| Arranque | `docker compose -f docker-compose.yml -f docker-compose.lan.yml up -d --build` (no se usa `docker-compose.prod.yml`: es para Linux/SELinux) |
| Contenedores | `siges-dashboard-db` (PostgreSQL 16, publicado solo en `127.0.0.1:15433`), `siges-dashboard-web` (Apache + PHP, `127.0.0.1:8091` y `192.168.1.71:8091`), `siges-dashboard-sync` (worker) |
| SIGES | PostgreSQL en `192.168.1.253:5432`, base `isis3_arths`, rol de solo lectura `siges_reader` |
| Secretos | `.env` y `config/local.php` (ignorados por Git, **no los leas ni los imprimas**) |
| Firewall | Regla `SIGES Dashboard 8091` (TCP 8091, remoto `192.168.1.0/24`) |

Puertos ocupados por otras apps de la misma PC (**no las toques**): 8080 `asistencia`, 8081 `historias-clinicas`, 8082 `calendario-cumpleanos`, 5432 un PostgreSQL nativo. Por eso este sistema usa 8091 y 15433.

El historial de envíos real se restauró desde un dump (paso 8b de `docs/despliegue-produccion.md`) antes de abrir el sistema al personal; el dump se borró.

## 3. Usuarios

Tabla `users`; se gestionan solo por consola (`docs/despliegue-produccion.md`, sección 17):

```powershell
docker exec -it siges-dashboard-web php scripts/manage-users.php list|create|passwd|disable|enable ...
```

Existen `admin` (rol admin; el dueño cambió la contraseña inicial), `operador1` y `operador2` (rol operador) y usuarios de prueba **desactivados** (`prueba`, `tmpcheck`, `tmpverif`). Las contraseñas **no se guardan en el repositorio**: no las repitas ni las inventes. Para pruebas con sesión crea o reactiva un usuario temporal con contraseña conocida (`NEW_PASSWORD=... manage-users.php passwd`) y desactívalo al terminar.

El rol `operador` solo se diferencia de `admin` en que no ve ni accede a **Estado del sistema** (`requireAdmin()`).

## 4. Reglas de datos que ya se decidieron (y por qué)

1. **Agenda:** solo citas enlazadas a SIGES, estado `RESERVADO`, tipo `NORMAL` y fecha/hora no pasadas (ver `AGENTS.md`).
2. **Celular sin el 51 (`appointments.destination`, migración 012).** En SIGES algunos celulares se digitan con el código de país y otros sin él. `SigesAppointmentSyncService::extractDestination()` guarda siempre **9 dígitos sin el 51**, descarta los de relleno (todos los dígitos iguales, p. ej. `999999999`) y conserva completo un `51` + número que no empiece con 9. **Al enviar**, `ChatbotApiClient::normalizeDestination()` vuelve a anteponer el 51: Gestionalo lo necesita (235 envíos exitosos se hicieron así) y `reminder_messages.destination` guarda el número exacto enviado. Se comprobó cruzando con SIGES que los fallos **no** se deben al 51.
3. **Números sin WhatsApp (migración 014).** Si Gestionalo responde HTTP 500 con `"No LID for user"`, el número no existe en WhatsApp (o está mal digitado en SIGES). Se guarda `reminder_messages.failure_reason = 'no_whatsapp'`; esos números se **excluyen del envío masivo**, se marcan en la agenda ("Sin WhatsApp: verifica el celular") y el masivo avisa cuántos hay. Si el celular cambia en SIGES vuelven a ofrecerse solos. El histórico (7 números, 37 intentos) se rellenó en la migración.
4. **Detección de cambios (migración 013).** El worker relee una cita si cambian fecha, hora, estado, **celular o programación** (médico/consultorio, `siges_programacion_id`).
5. **Sin envíos duplicados.** `AppointmentReminderRepository::createReminderAttempt()` toma un bloqueo por cita (`pg_advisory_xact_lock`) y lanza `DuplicateSendException` si hay otro intento `pending` de menos de 2 min. El masivo libera la sesión (`session_write_close()`) mientras envía y la reabre al final.
5b. **Auditoría:** cada envío guarda `sent_by_user_id` (`currentUserId()`).
6. **Motivo de error visible.** `ChatbotApiException` guarda un extracto sanitizado de la respuesta de Gestionalo (`detail`, celulares tapados con `***`); el aviso del masivo nombra a los pacientes que fallaron y por qué; el log registra `Gestionalo respondió HTTP N: ...`.

## 5. Migraciones aplicadas

`006` unaccent · `007` sede en contactos temporales · `008` `appointments.destination` · `009` tercera sede · `010` usuarios + `sent_by_user_id` · `011` latido del worker (`siges_sync_state.last_run_at`) · `012` destino sin el 51 · `013` `siges_programacion_id` · `014` `failure_reason`. `015` interruptor y marca del envío automático (`app_settings`, `reminder_messages.auto_sent`). `016` cola de alertas técnicas agregadas para WhatsApp (`system_error_alerts`). **La siguiente es la `017`.** Cada una se aplica con `docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard < database/migrations/0NN_*.sql` y debe reflejarse en `database/schema.sql` y `database/grants.sql`.

## 6. Cómo verificar que todo funciona (hay 4 scripts)

| Script | Para qué | Notas |
|---|---|---|
| `scripts/smoke-test-produccion.sh` | Estado general: contenedores, sincronización, web por la red, seguridad básica, base, configuración, escrituras | `SMOKE_USER`/`SMOKE_PASSWORD` añaden el flujo con sesión. Última: 48 correctas, 0 fallas |
| `scripts/security-test.sh` | Seguridad dinámica: acceso, rutas, cabeceras, CSRF, XSS, sesión, contenedores | `SEC_USER`/`SEC_PASSWORD` de un operador. Última: 64 correctas, 7 avisos, 0 fallas |
| `scripts/verify-writes.php` | Las escrituras de envío/contactos/chats con los permisos reales (siempre con rollback) | `docker exec siges-dashboard-web php scripts/verify-writes.php` |
| `scripts/verify-sync.php`, `verify-destinations.php`, `verify-appointment-type.php` | SIGES ↔ copia local | `docker compose run --rm --no-deps -T sync php scripts/<script>` |

Además, la pantalla **Estado del sistema** (`health.php`, admin) muestra latido del worker, citas, celulares, envíos de 24 h y configuración.

## 7. Seguridad: estado

Auditoría completa en `docs/auditoria-seguridad.md` (0 fallas, hallazgos abiertos). Lo más importante que **sigue pendiente**: HTTPS (las contraseñas viajan en claro por la LAN), cabeceras de seguridad (clickjacking, nosniff, CSP), `isLocalRequest()` no protege con Docker Desktop (todo llega como la puerta de enlace `172.28.0.1`; solo el login y el firewall limitan), límite de intentos por IP, roles sin efecto real, **respaldos automáticos inexistentes** y servicios abiertos en la PC (SSH 22, RDP 3389, SMB 445, PostgreSQL 5432). Detalle y orden en `docs/mejoras-pendientes.md`.

## 8. Pendiente (resumen)

- **Webhook de Gestionalo:** el formato del payload no está confirmado; el webhook no está publicado en internet. Pasos en `docs/operacion-local.md`.
- HTTPS, cabeceras, respaldos, roles reales, pantalla de usuarios, mostrar quién envió en el historial (ver `docs/mejoras-pendientes.md` y `docs/auditoria-seguridad.md`).
- Hay cambios **sin commit** (todo el trabajo de login, scripts, migraciones 010–014, marca y correcciones). No hagas commit salvo que el usuario lo pida.

## 8b. Envío automático de la víspera (2026-10-24)

Pedido y autorizado por el dueño: a las 17:00 se envían solos los recordatorios del día siguiente (los del lunes, el sábado y el domingo). Detalle en `docs/arquitectura.md`. Contenedor nuevo `siges-dashboard-auto-reminders` (`docker compose -f docker-compose.yml -f docker-compose.lan.yml up -d auto-reminders`; tras cambiar `app/` o `scripts/auto-reminders.php` ejecuta `restart auto-reminders`). **Queda apagado**: un administrador debe pulsar "Activar envío automático" en Estado del sistema. Verificado solo con simulación (`--dry-run`, `$transport` simulado y rollback); no se envió ningún mensaje real. Antes del primer encendido revisa `--dry-run` a las 17:00 y compáralo con `bulk-reminders.php`. Limitación: pausar el interruptor no corta un lote ya en curso.

## 8c. Alertas de errores por WhatsApp (2026-10-28)

La migración `016_add_system_error_alerts.sql` crea una cola local que agrupa fallos por intervalos de cinco minutos y código técnico. `ChatbotApiClient` registra errores de transporte y HTTP sin guardar destinos, cuerpos ni contenido de mensajes; `siges-dashboard-auto-reminders` intenta entregar los resúmenes cada minuto. Si Gestionalo no responde, se reintentan cada cinco minutos. El administrador configura el destino en Estado del sistema; dejarlo vacío desactiva las alertas. No se envían avisos históricos ni mensajes de prueba. Si PostgreSQL o todo el worker están caídos, no puede llegar un aviso durante esa caída; los logs de Docker quedan como respaldo local.

## 8d. Vigilancia local del host

La tarea de Windows `Chatbot-Incident-Watchdog` ejecuta `scripts/watchdog.ps1` al iniciar sesión del usuario y revisa Docker y los latidos cada minuto. Encola eventos técnicos para la misma entrega por WhatsApp y conserva eventos pendientes en `%LOCALAPPDATA%\ChatbotIncidentWatchdog` si la base o Docker no están disponibles. Puede iniciar contenedores detenidos por un error y reiniciar `sync` si su latido supera 60 segundos. Solo reinicia `auto-reminders` si lleva 30 minutos sin latido y los recordatorios automáticos están pausados; si están activos, informa sin interrumpir un posible lote. No cambia código ni vuelve a vincular una sesión de WhatsApp. Los logs locales están en `watchdog.log`; la tarea requiere que Windows esté iniciado y el usuario haya iniciado sesión.

## 8e. Enlaces de ubicación en recordatorios

Los recordatorios muestran el enlace de Google Maps de la sede: Pinillos (LIMA), Av. P.º de la República (LINCE) o Jr. Joaquín Bernal (LINCE - CONSULTORIO 3). Mantén los mismos enlaces en `SEDE_MAP_LINKS` de `public/assets/js/app.js` y `app/Services/ReminderMessageBuilder.php` para que los mensajes individuales y masivos coincidan.

## 8f. Separación de repositorios y dependencias (2026-10-29)

`AppointmentRepository` se ocupa de consultar y filtrar citas SIGES. `AppointmentReminderRepository` registra intentos y consulta el historial de recordatorios. `BulkReminderSender` recibe contratos para citas, historial y mensajería; `BulkReminderController` usa los contratos de citas e historial, y los controladores de chat y recordatorios reciben el contrato de mensajería al enviar. `ChatbotApiClient` implementa ese contrato. Esta separación no cambia filtros, textos, registros ni reglas de envío.

## 9. Preferencias del usuario y reglas de trabajo

- Habla **español**; prefiere respuestas directas y verificadas (prueba antes de afirmar).
- **Nunca envíes mensajes reales** ni uses pacientes reales para probar; el envío lo dispara siempre una persona. No imprimas contraseñas, claves, celulares completos ni nombres de pacientes en logs/respuestas salvo imprescindible (enmascara: `92•••••29`).
- No leas ni imprimas `.env` ni `config/local.php`.
- Marca visible: barra lateral "CHATBOT / Agenda médica" (sin logo ni pie "Datos de SIGES"); login con logo `assets/img/logo-chat.png` y texto de Arthrosalud sobre fondo naranja; `assets/img/logo.webp` (naranja, transparente) existe pero la barra lateral ya no lo usa.

## 10. Trampas técnicas del entorno (ahorran tiempo)

- **Git Bash en Windows.** Para `docker exec ... php /var/www/...` usa `MSYS_NO_PATHCONV=1` (si no, convierte la ruta). Pero **no** lo exportes cuando uses `curl -c/-b archivo` con rutas `/c/...`: curl no escribe la cookie y las pruebas fallan engañosamente.
- **No hay Python.** Para ediciones masivas usa `perl` o la herramienta Edit. Muchos archivos son **CRLF** (o mixtos): los reemplazos multilínea fallan si no tratas `\r\n`; prefiere cambios de una línea o `perl` tolerante.
- Dentro del contenedor, `docker cp "$(cygpath -w /tmp/x.php)" contenedor:/tmp/x.php` sirve para pruebas puntuales (bórralos después).
- Los archivos PHP se montan en solo lectura; el worker carga su código al arrancar: tras cambiar `app/Services/` o `scripts/` ejecuta `docker compose -f docker-compose.yml -f docker-compose.lan.yml restart sync`.
- Heredocs con `'EOF'` en bash pueden perder una barra invertida: revisa con `php -l` (`docker exec siges-dashboard-web php -l /var/www/app/<archivo>`).
- Errores de PHP/Apache: `docker logs siges-dashboard-web` (los `error_log` de la app salen ahí).

## 11. Lecciones de incidentes (no repetir)

- Una edición automática puso `:sent_by` en la consulta equivocada y rompió los envíos temporales con "No se pudo enviar. Revisa la conexión y la configuración de la API" (era un `PDOException`, no la API). Por eso existe `scripts/verify-writes.php`: córrelo tras tocar repositorios.
- "No se pudo enviar" genérico puede ser un error de base de datos: mira `docker logs siges-dashboard-web` (`... : PDOException`).
- Los 4 "fallidos" del masivo eran números sin WhatsApp (`No LID for user`), no un fallo del sistema.
