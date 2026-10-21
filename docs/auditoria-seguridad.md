# Auditoría de seguridad — 2026-10-16

Alcance: la aplicación desplegada en `http://192.168.1.71:8091/` (PC Windows con Docker Desktop), su código, su configuración y el equipo que la aloja. No se probó SIGES, Gestionalo ni se envió ningún mensaje.

Las pruebas dinámicas están en `scripts/security-test.sh` (repetible):

```bash
SEC_USER=operador1 SEC_PASSWORD='...' bash scripts/security-test.sh
```

Última ejecución: **64 correctas, 7 avisos, 0 fallas**.

## Resumen

| Nivel | Hallazgo |
|---|---|
| **Alto** | 1. Sin HTTPS: usuario, contraseña y sesión viajan en claro por la red. |
| **Alto** | 2. El filtro "solo equipo local" no protege en Docker Desktop: la única barrera real es el login y el firewall. |
| Medio | 3. Faltan cabeceras de seguridad (clickjacking, nosniff, CSP, Referrer-Policy). |
| Medio | 4. El bloqueo de cuenta permite dejar sin acceso al administrador; no hay límite por IP. |
| Medio | 5. Los roles no limitan nada salvo "Estado del sistema". |
| Medio | 6. Este equipo expone SSH (22), RDP (3389), SMB (445) y un PostgreSQL (5432) a toda la red. |
| Medio | 7. Sin respaldos automáticos de la base (historial de envíos y usuarios). |
| Bajo | 8. Token del webhook en la URL y en los logs de acceso (hoy el webhook no está publicado). |
| Bajo | 9. Contraseñas iniciales compartidas por chat. |
| Bajo | 10. Endurecimiento de PHP y contenedores. |
| Bajo | 11. `DashboardController` registra la excepción completa con traza. |

## Qué está bien

- **Control de acceso:** las 10 páginas y endpoints exigen sesión (redirigen o dan 401); solo `login.php`, `logout.php` y el webhook (con token) quedan abiertos. Un operador recibe 403 en Estado del sistema.
- **Inyección SQL:** todas las consultas usan parámetros; las únicas interpolaciones (`{$where}`) arman fragmentos fijos con nombres de columna del código, nunca datos del usuario.
- **XSS:** todo se escapa con `htmlspecialchars` y las pruebas de `<script>` reflejado en 4 páginas salieron limpias. El JS usa `escapeHtml` antes de `innerHTML`.
- **CSRF:** los 5 formularios de envío o borrado y el logout rechazan peticiones sin token; el token se comparó con `hash_equals` y se regenera al iniciar sesión.
- **Sesión:** cookie `HttpOnly` y `SameSite=Lax`, ID regenerado al entrar (sin fijación de sesión), caducidad de 8 h, usuario desactivado cae en menos de 1 min, cookie con nombre propio.
- **Contraseñas:** `password_hash`, mínimo 10 caracteres, bloqueo tras 5 fallos y mensaje genérico ante usuario inexistente.
- **Redirección abierta:** `next` solo admite rutas internas; probado con `https://evil.com`, `//evil.com` y `javascript:`.
- **Archivos:** `.env`, `config/`, `.git`, `database/`, `docs/` y scripts devuelven 404; sin listado de directorios ni TRACE; recorrido de rutas bloqueado.
- **Subida de imágenes:** valida el tipo real con `finfo`, límite de 5 MB y no guarda nada en disco.
- **Base de datos:** roles sin superusuario; la web no puede modificar `appointments`; SIGES solo lo lee el worker, en sesión de solo lectura; contraseñas de 48 caracteres con `scram-sha-256`; la base solo escucha en `127.0.0.1`.
- **Secretos:** `.env` y `config/local.php` ignorados por Git y sin secretos ni dumps en el historial; los logs no contienen contraseñas ni claves.
- **Contenedores:** ninguno es privilegiado, el código se monta en solo lectura y Apache atiende como `www-data`.

## Hallazgos y cómo corregirlos

### 1. Sin HTTPS (alto)
Cualquier equipo en la misma red puede capturar el usuario, la contraseña y la cookie de sesión. Es lo más importante dado que la aplicación maneja datos de salud.
**Corrección:** poner un proxy con HTTPS delante (Caddy o nginx) con certificado de una CA interna o autofirmado, publicar solo el 443 y dejar el 8091 en `127.0.0.1`. Después activar la cookie `Secure` y la cabecera HSTS.

### 2. El filtro "solo local" no protege con Docker Desktop (alto)
Todo el tráfico de la LAN llega a la aplicación con la IP de la puerta de enlace de Docker (`172.28.0.1`), que `LOCAL_ACCESS_EXTRA_IPS` permite. Por eso `isLocalRequest()` deja pasar a cualquiera que llegue al puerto. Hoy lo único que limita es el login y la regla de firewall `SIGES Dashboard 8091` (permite `192.168.1.0/24`, perfil Any).
**Corrección:** limitar la regla de firewall a las IPs de las PCs que realmente lo usan y no confiar en `isLocalRequest()` como defensa. En un servidor Linux sin NAT de Docker Desktop sí funcionaría.

### 3. Cabeceras de seguridad ausentes (medio)
Faltan `X-Frame-Options`/`frame-ancestors` (otro sitio podría incrustar el panel y engañar a un usuario para hacer clic), `X-Content-Type-Options: nosniff`, `Content-Security-Policy` y `Referrer-Policy`.
**Corrección:** añadirlas en Apache (`docker/php/`), por ejemplo `Header always set X-Frame-Options "DENY"`, `X-Content-Type-Options "nosniff"`, `Referrer-Policy "same-origin"` y una CSP que permita solo el propio origen y Google Fonts.

### 4. Bloqueo de cuenta y fuerza bruta (medio)
Cinco intentos fallidos bloquean la cuenta 10 minutos, así que alguien en la red puede dejar al administrador fuera repitiéndolo. Tampoco hay límite por IP: se puede probar una contraseña común contra todos los usuarios.
**Corrección:** limitar también por IP y por ventana de tiempo, y no bloquear al administrador de forma indefinida (por ejemplo, avisar y exigir espera creciente). El mensaje de cuenta bloqueada revela que el usuario existe; es menor.

### 5. Los roles no limitan nada (medio)
Un `operador` puede hacer envío masivo a todos los pacientes, borrar contactos temporales y responder chats. El rol `admin` solo se diferencia en Estado del sistema.
**Corrección:** decidir qué acciones son solo de administrador (por ejemplo, el envío masivo) y aplicarlas con `requireAdmin()`. Mostrar quién envió cada mensaje en el historial, ya que `sent_by_user_id` se guarda.

### 6. Servicios abiertos en el equipo (medio)
Fuera de este proyecto, la PC escucha en toda la red en SSH (22), RDP (3389), SMB (445) y PostgreSQL (5432, proceso 6652). Si esta PC aloja la demo, cualquiera de ellos es una puerta de entrada.
**Corrección:** cerrar o restringir por firewall los que no se usen, y revisar que el PostgreSQL de 5432 no tenga contraseña débil ni `pg_hba` abierto.

### 7. Sin respaldos (medio)
El historial de envíos, los contactos temporales y los usuarios solo existen en el volumen `sistema_de_mensaje_pgdata`. Un `docker compose down -v` o un fallo de disco los pierde. Tampoco hay copias del `.env` ni de `config/local.php`, de modo que perder el equipo implica regenerar las claves.
**Corrección:** programar un `pg_dump` diario (la guía de despliegue, sección 13, lo describe para Linux; en Windows va con una tarea programada) y guardar los respaldos cifrados fuera de la PC.

### 8. Token del webhook en la URL (bajo, sube a medio al publicarlo)
`?token=` queda en los logs de acceso de Apache (la prueba lo encontró 5 veces). Hoy el webhook no está publicado.
**Corrección:** si Gestionalo permite cabeceras, usar `X-Webhook-Token`; de lo contrario, no registrar esa ruta en el log del proxy.

### 9. Contraseñas iniciales compartidas por chat (bajo)
Las de `admin`, `operador1` y `operador2` se mostraron en esta conversación.
**Corrección:** cambiarlas con `manage-users.php passwd <usuario>` y no reutilizarlas.

### 10. Endurecimiento de PHP y contenedores (bajo)
`allow_url_fopen` está activo y `disable_functions` vacío; los contenedores no usan `read_only`, `cap_drop` ni `no-new-privileges`; el módulo `status` de Apache está cargado (responde 403, correcto); PostgreSQL sin SSL (solo se usa dentro de la red de Docker).
**Corrección:** `allow_url_fopen=Off`, `disable_functions` con `exec,shell_exec,system,passthru,proc_open,popen` (el script `manage-users.php` usa `shell_exec` solo para ocultar la contraseña en terminal), y `security_opt: no-new-privileges` en `docker-compose.yml`.

### 11. Registro de excepción completa (bajo)
`DashboardController` hace `error_log((string) $error)`, que incluye traza y el mensaje de PDO (host y usuario de la base). Los demás archivos registran solo la clase.
**Corrección:** registrar `get_class($error)` como el resto, según la regla de AGENTS.md.

## Orden recomendado
1. Cambiar las contraseñas compartidas (9) y acotar el firewall a las PCs necesarias (2).
2. Poner HTTPS (1) y las cabeceras (3).
3. Programar respaldos (7).
4. Límite por IP y bloqueo del administrador (4) y roles (5).
5. El resto (6, 8, 10, 11).
