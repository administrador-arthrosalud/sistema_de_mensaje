# Operación local

## Aplicación

Requisitos: XAMPP, PHP con `pdo_mysql`, `pdo_pgsql`, `curl`, `mbstring` y `fileinfo`, y MySQL activo. La configuración privada está en `config/local.php`, ignorado por Git. No pegues su contenido en chats ni capturas.

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

## Migraciones

Aplica en orden las migraciones necesarias para una instalación existente:

```text
001_add_appointment_location.sql
002_create_reminder_messages.sql
003_add_siges_incremental_sync.sql
004_create_manual_reminders.sql
005_create_chat_messages.sql
```

La migración 004 añade el módulo temporal y la columna de origen del historial. La migración 005 añade la bandeja de chats (`chat_messages`). Haz una copia de seguridad local antes de aplicar migraciones manualmente en otra instalación.

## Chats con pacientes

La bandeja (`chats.php`) se usa igual que el resto del dashboard, solo desde el equipo local. Lo distinto es cómo llegan los mensajes entrantes:

`chat-webhook.php` necesita que Gestionalo pueda llamarlo por internet, así que no puede quedar solo en `127.0.0.1` como el resto de `public/`. Opciones para esta PC:

- **Producción real**: publica la aplicación detrás de un dominio propio con HTTPS (reverse proxy, hosting, etc.) y usa esa URL pública solo para `chat-webhook.php`; el resto del dashboard puede seguir restringido a la red local si lo prefieres.
- **Pruebas**: abre un túnel temporal hacia `127.0.0.1:8082` (por ejemplo con `cloudflared tunnel` o `ngrok`) y usa la URL que te entregue como webhook en Gestionalo mientras pruebas. Cierra el túnel cuando termines.

En cualquier caso, protege el endpoint con `CHATBOT_WEBHOOK_TOKEN` (un token largo y aleatorio en `config/local.php`) y nunca publiques ese token ni lo compartas en capturas.

Si los mensajes no llegan a la bandeja:

- Revisa que la URL configurada en Gestionalo incluya `?token=` con el valor exacto de `CHATBOT_WEBHOOK_TOKEN`.
- Comprueba que el túnel o el dominio público sigue activo; un túnel cerrado no avisa, simplemente deja de recibir.
- HTTP 403 en `chat-webhook.php`: el token no coincide.
- HTTP 422: Gestionalo entregó el mensaje, pero `chat-webhook.php` no pudo identificar el celular o el texto en el payload. Es señal de que su formato real difiere del bloque de ejemplo en el código; hay que ajustarlo con la documentación o un ejemplo real de Gestionalo.

## Diagnóstico rápido

- `ERR_CONNECTION_REFUSED` en `127.0.0.1:8082`: el servidor PHP no está escuchando en ese puerto.
- Error MySQL: comprueba que MySQL está activo en XAMPP y revisa la configuración privada sin copiarla al chat.
- Error PostgreSQL `connection refused` a `127.0.0.1:15432`: el túnel SSH no está abierto en esta PC o PostgreSQL no escucha en el destino del túnel.
- `ATENDIDO` o `ANULADO` visible en la agenda: comprueba que el worker puede llegar a SIGES y que compara estados existentes; la consulta de agenda siempre debe filtrar `RESERVADO`.
- HTTP 401/403 en Gestionalo: valida la clave asignada a la línea con el proveedor. No intercambies la clave de línea con el celular del destinatario.

Nunca compartas contraseñas, claves de línea, tokens o capturas de `config/local.php`. Si una credencial aparece en una captura compartida, rótala y actualiza la configuración local.
