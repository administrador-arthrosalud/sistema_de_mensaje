# Mejoras pendientes

> Contexto general y estado actual: `docs/estado-del-proyecto.md`. Hechas el 2026-10-17/19: puntos 1, 2, 3 y 8 (ver abajo), login (5, parcial), números sin WhatsApp, motivo de error visible y `scripts/verify-writes.php`. Seguridad: `docs/auditoria-seguridad.md`.

Hallazgos del análisis del proyecto (2026-10-16). Se abordarán **después** del primer despliegue de la demo en producción. Ordenadas por prioridad.

## Alta prioridad

### 1. El sincronizador no detecta cambios de celular, médico ni consultorio — CORREGIDO (2026-10-17)
- **Dónde:** `app/Services/SigesAppointmentSyncService.php` (`runOnce()`).
- **Problema:** solo compara fecha, hora y estado para decidir qué cita releer. Si en SIGES cambia `cita.contactos`, el médico o el consultorio, la copia local queda desactualizada. El envío masivo usa `appointments.destination`, así que puede enviar a un número viejo.
- **Propuesta:** incluir en el snapshot una huella (hash) de `contactos`, programación y consultorio, y comparar también la huella.

### 2. Posible doble envío en el envío masivo — CORREGIDO (2026-10-17)
- **Dónde:** `app/Controllers/BulkReminderController.php` (`send()`), `AppointmentRepository::findReadyForBulkReminderById()` y `AppointmentReminderRepository::createReminderAttempt()`.
- **Problema:** se revisa "sin recordatorio `sent`" y recién después se crea el intento. Dos peticiones simultáneas (doble clic, dos operadores) pueden pasar la revisión y enviar dos veces. Confirmar en el repositorio si hay restricción única.
- **Propuesta:** reservar la cita de forma atómica (`SELECT … FOR UPDATE` o índice único parcial sobre intentos `pending`/`sent` por cita) y deshabilitar el botón tras el primer clic.

### 3. Lote largo y bloqueo de sesión PHP — CORREGIDO (2026-10-17)
- **Dónde:** `BulkReminderController::send()`, `session_start()` en `public/bulk-reminders.php`.
- **Problema:** 0,4 s de pausa por mensaje; un lote de 200 pacientes tarda ~80 s o más y puede chocar con `max_execution_time` o el timeout de Apache. Además la sesión queda bloqueada para las demás pestañas mientras dura el envío.
- **Propuesta:** `session_write_close()` antes del bucle, `set_time_limit()` acorde al lote y, a futuro, procesar el lote en segundo plano con progreso.

### 4. Formato del webhook de Gestionalo sin confirmar
- **Dónde:** `public/chat-webhook.php` (bloque `AJUSTA SOLO ESTE BLOQUE`).
- **Problema:** los nombres de campo (`celular`, `from`, `mensaje`…) son suposiciones; hasta confirmarlos pueden descartarse mensajes entrantes (HTTP 422). No hay límite de tasa.
- **Propuesta:** activar `CHATBOT_WEBHOOK_DEBUG` con un mensaje real, ajustar el bloque, apagar el modo diagnóstico y añadir un límite de tasa en el proxy.

### 5. Autenticación y roles — LOGIN HECHO (2026-10-16)
- **Problema:** la protección es solo "acceso local" (`config/access.php`). Cualquiera con acceso a la máquina o a la red (si se abre) ve datos de salud y puede enviar mensajes.
- **Propuesta:** inicio de sesión por usuario con roles (ver / enviar / administrar), registro de quién envió cada mensaje. **Prerrequisito** para exponer la aplicación a toda la red sin restricciones.
- **Hecho:** login con usuarios en base de datos, bloqueo por intentos, sesión de 8 h, auditoría `sent_by_user_id` y gestión por CLI. **Pendiente:** aplicar permisos por rol, pantalla de administración de usuarios, HTTPS y mostrar quién envió en el historial.

## Media prioridad

### 6. Token del webhook en la URL
- **Problema:** `?token=` queda en los logs de acceso de Apache/nginx.
- **Propuesta:** usar el header `X-Webhook-Token` si Gestionalo lo permite; mientras tanto, `access_log off` en el proxy para esa ruta.

### 7. Sin pruebas automatizadas
- **Problema:** solo existen scripts `verify-*` contra datos reales. La plantilla del mensaje (`app.js` y `ReminderMessageBuilder.php`) y las direcciones de sedes se duplican a mano.
- **Propuesta:** pruebas unitarias para `ReminderMessageBuilder`, `extractDestination()` y la normalización de celulares; una prueba que compare la salida de ambas plantillas; fuente única para las direcciones de sedes (p. ej. endpoint JSON que consuma el JS).

### 8. `error_log` con mensaje de excepción — CORREGIDO (2026-10-17)
- **Dónde:** `public/send-reminder.php` (`$error->getMessage()`).
- **Problema:** el mensaje de una excepción PDO podría arrastrar datos; AGENTS.md pide no registrar datos de pacientes.
- **Propuesta:** registrar solo `get_class($error)`, como hacen los demás archivos.

## Baja prioridad

- `last_cita_id` del cursor de sincronización casi no influye en la lógica real; simplificar o documentar su uso.
- `SEDE NO MAPEADA` no genera ninguna alerta; añadir aviso en el dashboard o el log del worker.
- El README cita una ruta antigua (`C:\proyectos\Sistema-de-mensajes\...`).
- Git: `c:\proyectos\Chatbot-V.0.1` no tiene commits y `sistema_de_mensaje/` es un repositorio anidado; decidir cuál es el repositorio real.
