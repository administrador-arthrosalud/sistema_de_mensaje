# Despliegue en el servidor oficial

Guía paso a paso para instalar el sistema en el servidor oficial (Linux con Docker, previsto Rocky Linux 9). Está escrita para que una persona o un agente la siga **sin otro contexto**: cada paso indica qué ejecutar y cómo comprobar que salió bien antes de pasar al siguiente.

> **Agentes:** lee primero `AGENTS.md` y `docs/arquitectura.md`. Reglas que aplican durante todo el despliegue:
> - SIGES es **solo lectura**. Nunca ejecutes `INSERT`, `UPDATE`, `DELETE` ni DDL en la base `isis3_arths`, salvo los `GRANT`/`ALTER ROLE` del paso 5, y solo si una persona lo pide.
> - **Nunca envíes un mensaje real** (botones *Recordar*, *Enviar recordatorio* o *Enviar* del chat) para probar.
> - **Nunca imprimas** contraseñas, la serie de Gestionalo ni el token del webhook: no uses `cat .env`, `cat config/local.php` ni `echo $PASSWORD`. Los comandos de esta guía están pensados para no mostrarlos.
> - No muestres nombres, celulares ni citas de pacientes. Para verificar usa solo totales (`scripts/verify-sync.php`).

---

## 1. Qué se instala

```text
                         servidor oficial
┌───────────────────────────────────────────────────────────────────┐
│  Docker (red siges-dashboard, 172.28.0.0/24)                      │
│  ┌──────────────────┐  ┌──────────────────┐  ┌─────────────────┐  │
│  │ web              │  │ sync             │  │ postgres        │  │
│  │ PHP 8.2 + Apache │  │ worker cada 3 s  │  │ PostgreSQL 16   │  │
│  │ 127.0.0.1:8082   │  │                  │  │ siges_dashboard │  │
│  └────────┬─────────┘  └───┬──────────┬───┘  └────────▲────────┘  │
│           └────────────────┼──────────┼───────────────┘           │
│                            │ solo lectura                         │
│                            ▼                                      │
│            PostgreSQL de SIGES (isis3_arths, rol siges_reader)    │
└───────────────────────────────────────────────────────────────────┘
   Personal: túnel SSH → 127.0.0.1:8082      Gestionalo → proxy HTTPS → solo /chat-webhook.php
```

| Servicio | Contenedor | Función |
|---|---|---|
| `postgres` | `siges-dashboard-db` | Base local de la aplicación (`siges_dashboard`). **No es SIGES.** Datos en el volumen `pgdata`. |
| `web` | `siges-dashboard-web` | Agenda, historial, recordatorios temporales y chats. Document root `public/`. |
| `sync` | `siges-dashboard-sync` | Copia las citas de SIGES desde hoy cada 3 s (`scripts/sync-siges.php`). |

Archivos que intervienen:

| Archivo | Versionado | Contenido |
|---|---|---|
| `docker-compose.yml` | sí | Definición base de los tres servicios. |
| `docker-compose.prod.yml` | sí | Ajustes del servidor: etiquetas SELinux (`:z`) y rotación de logs. |
| `.env` | **no** | Contraseñas de la base local y ajustes de puertos/red para Docker Compose. |
| `config/local.php` | **no** | Credenciales de la aplicación: base local, SIGES y Gestionalo. Plantilla: `config/local.example.php`. |
| `scripts/verify-sync.php` | sí | Compara SIGES con la copia local (solo totales y huella md5). |
| `scripts/verify-destinations.php` | sí | Compara los celulares guardados contra SIGES y valida su formato. |
| `scripts/verify-appointment-type.php` | sí | Confirma que solo citas tipo `NORMAL` quedan visibles/enviables. |

---

## 2. Antes de empezar: datos que necesitas

Reúne esto **antes** del paso 1. Si falta alguno, detente y pídeselo a la persona responsable.

| # | Dato | Ejemplo o valor conocido | Para qué |
|---|---|---|---|
| 1 | Acceso SSH como `root` (o con `sudo`) al servidor oficial | — | Instalar y operar. |
| 2 | ¿El servidor oficial es el **mismo** que aloja SIGES? | Servidor SIGES actual: `192.168.1.253`, PostgreSQL 18 en el puerto 5432 | Define puertos y host de SIGES (paso 3). |
| 3 | Si SIGES está en **otro** servidor: su IP y puerto | `192.168.1.253:5432` | `SIGES_PG_HOST` y `SIGES_PG_PORT`. |
| 4 | Contraseña del rol de PostgreSQL `siges_reader` en SIGES | La persona la escribe; no se comparte | `SIGES_PG_PASSWORD`. |
| 5 | Serie (clave de línea) de Gestionalo | La persona la escribe; no se comparte | `CHATBOT_LINE_KEY`. |
| 6 | Acceso a internet desde el servidor | Docker Hub y `https://chatbot.gestionalo.pe` | Descargar imágenes y enviar mensajes. |
| 7 | URL del repositorio Git y la rama a desplegar | `git remote -v` en el equipo de desarrollo | Obtener el código (paso 2). |
| 8 | (Opcional) Dominio público con HTTPS para el webhook | `mensajes.ejemplo.pe` | Recibir respuestas de pacientes (paso 9). |

La base de SIGES confirmada es **`isis3_arths`** (tabla `public.cita`). Las otras bases del servidor (`bd_isis_arths`, `bd_sipress`, `postgres`) **no** se usan.

---

## 3. Paso 1: instalar Docker

Comprueba primero si ya está instalado:

```bash
docker version --format '{{.Server.Version}}' && docker compose version
```

Si ambos responden con una versión (Compose **v2.20 o superior**), pasa al paso 2. Si no, instálalo en Rocky Linux 9:

```bash
dnf -y install dnf-plugins-core
dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo
dnf -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker
```

Si `dnf` informa de conflictos con `podman`/`buildah`/`runc`, quítalos primero (`dnf -y remove podman buildah`) y repite la instalación.

**Comprobación:** `systemctl is-enabled docker` responde `enabled` y `docker run --rm hello-world` termina sin error.

---

## 4. Paso 2: obtener el código

```bash
mkdir -p /opt && cd /opt
git clone <URL_DEL_REPOSITORIO> siges-dashboard
cd /opt/siges-dashboard
git checkout <RAMA_O_TAG>
```

El resto de la guía asume la carpeta **`/opt/siges-dashboard`**. Si usas otra, sustitúyela en todos los comandos.

**Comprobación:** existen `docker-compose.yml`, `docker-compose.prod.yml`, `config/local.example.php` y `scripts/verify-sync.php`. No deben existir `.env` ni `config/local.php`: se crean en los pasos 3 y 4 y nunca se copian desde el equipo de desarrollo, porque allí tienen otras contraseñas.

```bash
ls docker-compose.yml docker-compose.prod.yml config/local.example.php scripts/verify-sync.php
test ! -e .env && test ! -e config/local.php && echo "OK: sin secretos copiados"
```

---

## 5. Paso 3: crear `.env` (Docker Compose)

Genera contraseñas aleatorias sin mostrarlas en pantalla:

```bash
cd /opt/siges-dashboard
umask 077
cat > .env <<EOF
POSTGRES_PASSWORD=$(openssl rand -hex 24)
SIGES_APP_PASSWORD=$(openssl rand -hex 24)
SIGES_SYNC_PASSWORD=$(openssl rand -hex 24)
EOF
chmod 600 .env
```

Añade después los ajustes que correspondan a tu caso:

| Situación | Línea que hay que añadir a `.env` | Motivo |
|---|---|---|
| El servidor oficial **es el mismo** que aloja SIGES | `DB_PUBLISH_PORT=15433` | El 5432 ya lo usa el PostgreSQL de SIGES. |
| SIGES está en **otro** servidor | `SIGES_PG_HOST=<IP_DE_SIGES>` | Por defecto se usa el propio servidor (`host.docker.internal`). |
| El puerto 8082 ya está ocupado | `WEB_PUBLISH_PORT=<otro puerto>` | Puerto local de la aplicación web. |
| La subred `172.28.0.0/24` ya existe | `DOCKER_SUBNET=172.29.0.0/24` y `DOCKER_GATEWAY_IP=172.29.0.1` | La IP de la puerta de enlace también se usa en el filtro de acceso. |

Comprueba los puertos y la subred:

```bash
ss -ltn | grep -E ':(5432|8082)\s' || echo "puertos 5432/8082 libres"
ip route | grep -F '172.28.' || echo "subred 172.28.0.0/24 libre"
```

Ejemplo para un servidor que también aloja SIGES:

```bash
echo 'DB_PUBLISH_PORT=15433' >> .env
```

**Comprobación** (muestra solo los nombres de las variables, nunca sus valores):

```bash
cut -d= -f1 .env
```

---

## 6. Paso 4: crear `config/local.php` (aplicación)

```bash
cd /opt/siges-dashboard
cp config/local.example.php config/local.php
# Copia las contraseñas de la base local desde .env sin mostrarlas.
set -a; . ./.env; set +a
sed -i \
  -e "s|'DB_PASSWORD' => 'CAMBIAR'|'DB_PASSWORD' => '${SIGES_APP_PASSWORD}'|" \
  -e "s|'SIGES_SYNC_DB_PASSWORD' => 'CAMBIAR'|'SIGES_SYNC_DB_PASSWORD' => '${SIGES_SYNC_PASSWORD}'|" \
  -e "s|'CHATBOT_WEBHOOK_TOKEN' => 'CAMBIAR'|'CHATBOT_WEBHOOK_TOKEN' => '$(openssl rand -hex 32)'|" \
  config/local.php
unset POSTGRES_PASSWORD SIGES_APP_PASSWORD SIGES_SYNC_PASSWORD
```

Quedan **dos valores** que debe escribir una persona con un editor (`vi config/local.php` o `nano config/local.php`), sin pegarlos en chats ni en registros:

- `'SIGES_PG_PASSWORD' => 'CAMBIAR'`: contraseña de `siges_reader`.
- `'CHATBOT_LINE_KEY' => 'CAMBIAR'`: serie de Gestionalo.

Ajusta además `'SIGES_PG_PORT'` si el PostgreSQL de SIGES no escucha en 5432.

Permisos: Apache lee el archivo dentro del contenedor como `www-data` (UID/GID 33), así que debe pertenecer a ese grupo, sin acceso para otros usuarios.

```bash
chown root:33 config/local.php
chmod 640 config/local.php
```

**Comprobación** (no muestra valores):

```bash
grep -c "CAMBIAR" config/local.php   # debe responder 0
php -l config/local.php 2>/dev/null || docker run --rm -v "$PWD/config:/c:ro,z" php:8.2-cli php -l /c/local.php
stat -c '%U:%g %a' config/local.php  # debe responder root:33 640
```

---

## 7. Paso 5: rol de solo lectura en SIGES

Ejecútalo en el **servidor de SIGES**. Si el rol ya se configuró antes (como en el entorno de desarrollo), estos comandos solo lo confirman; puedes repetirlos sin riesgo y no tocan datos.

```bash
su - postgres -c "psql -d isis3_arths -v ON_ERROR_STOP=1" <<'SQL'
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'siges_reader') THEN
    CREATE ROLE siges_reader LOGIN;
  END IF;
END $$;
GRANT CONNECT ON DATABASE isis3_arths TO siges_reader;
GRANT USAGE ON SCHEMA public TO siges_reader;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON ALL TABLES IN SCHEMA public FROM siges_reader;
GRANT SELECT ON public.cita, public.historia, public.persona, public.vw_programacion_medica,
                public.programacion, public.consultorio, public.sede,
                public.admision, public.actomedico TO siges_reader;
ALTER ROLE siges_reader SET default_transaction_read_only = on;
SELECT t AS tabla,
       has_table_privilege('siges_reader', t, 'SELECT') AS lee,
       has_table_privilege('siges_reader', t, 'INSERT,UPDATE,DELETE,TRUNCATE') AS escribe
FROM unnest(ARRAY['public.cita','public.historia','public.persona','public.vw_programacion_medica',
                  'public.programacion','public.consultorio','public.sede',
                  'public.admision','public.actomedico']) AS t;
SQL
```

**Comprobación:** las 9 filas muestran `lee = t` y `escribe = f`.

Si el rol se acaba de crear, una persona le asigna la contraseña. Entra con `su - postgres -c "psql -d isis3_arths"`, escribe `\password siges_reader` y sal con `\q`. Esa misma contraseña va en `SIGES_PG_PASSWORD` (paso 4).

**Conexión desde Docker.** El contenedor `sync` se conecta a SIGES desde la red `172.28.0.0/24`, o la subred que hayas configurado. `pg_hba.conf` de SIGES debe aceptar `scram-sha-256` desde esa red. Hoy existe la regla `host all all 0.0.0.0/0 scram-sha-256`, que ya lo permite. Si alguien la restringe (recomendable), hay que añadir la red de Docker o la IP del servidor oficial:

```text
host  isis3_arths  siges_reader  172.28.0.0/24  scram-sha-256
```

Después recarga la configuración con `su - postgres -c "psql -c 'SELECT pg_reload_conf()'"`.

---

## 8. Paso 6: arrancar

Para no repetir los dos archivos en cada comando, define un alias. Úsalo en todo el servidor; **nunca** arranques solo con `docker-compose.yml`, porque sin `docker-compose.prod.yml` SELinux bloquea la lectura del código.

```bash
cd /opt/siges-dashboard
echo "alias dc='docker compose -f /opt/siges-dashboard/docker-compose.yml -f /opt/siges-dashboard/docker-compose.prod.yml --project-directory /opt/siges-dashboard'" >> ~/.bashrc
. ~/.bashrc
dc up -d --build
```

La primera vez se descargan las imágenes y se crea la base: se aplican solos `database/schema.sql` (incluida la extensión `unaccent`), los roles y `database/grants.sql`. Queda vacía: sin citas, sin historial de envíos.

### 8b. Si ya existen datos reales en otro entorno (por ejemplo, el de desarrollo)

> ⚠️ **Aplica a este despliegue.** Si antes de instalar el servidor oficial ya se usó la aplicación contra la base real de SIGES (desarrollo con Docker Desktop, o cualquier instalación anterior) y se enviaron recordatorios de verdad, **no actives el servidor oficial con la base vacía del paso anterior**: el worker volvería a sincronizar las mismas citas desde SIGES, pero como no quedó registro de qué recordatorios ya se mandaron, el personal podría reenviarlos a los mismos pacientes sin saberlo. Antes de que el personal use el servidor oficial, trae el historial real.

**En el entorno de origen** (donde ya hay datos reales — p. ej. la PC de desarrollo), detén `web` y `sync` para que nadie escriba durante la copia, y genera el respaldo:

```powershell
# PowerShell, en la carpeta del proyecto del entorno de origen
docker compose stop web sync
docker exec siges-dashboard-db pg_dump -U postgres -d siges_dashboard -Fc -f /tmp/produccion.dump
docker cp siges-dashboard-db:/tmp/produccion.dump .\produccion.dump
docker exec siges-dashboard-db rm -f /tmp/produccion.dump
```

Copia `produccion.dump` al servidor por un canal cifrado (`scp`), nunca por chat ni almacenamiento compartido sin cifrar: contiene datos de pacientes.

```bash
scp produccion.dump usuario@IP_SERVIDOR:/opt/siges-dashboard/
```

**En el servidor oficial**, con los contenedores ya arrancados (`dc up -d --build` de arriba) pero el personal todavía sin usarlo:

```bash
cd /opt/siges-dashboard
dc stop web sync
docker exec -i siges-dashboard-db pg_restore -U postgres -d siges_dashboard --clean --if-exists < produccion.dump
shred -u produccion.dump 2>/dev/null || rm -f produccion.dump
dc start web sync
```

`--clean --if-exists` reemplaza las tablas vacías del esquema recién creado por las de la copia, con sus datos y su numeración de id continuando donde quedó (no choca con citas nuevas). Los roles y contraseñas del servidor (generados en los pasos 3-4) no se tocan: el volcado no incluye roles, así que la restauración no sobrescribe ninguna contraseña.

**Comprobación** — compara contra lo que anotaste en el entorno de origen antes de detenerlo (solo totales, nunca nombres ni celulares):

```bash
echo "SELECT (SELECT COUNT(*) FROM appointments) AS appointments, (SELECT COUNT(*) FROM reminder_messages) AS reminder_messages, (SELECT COUNT(*) FROM reminder_messages WHERE status='sent') AS enviados, (SELECT COUNT(*) FROM manual_contacts) AS manual_contacts;" | docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard
```

`appointments` puede diferir en unas pocas filas si el worker del origen siguió corriendo citas nuevas entre el respaldo y la restauración; `reminder_messages` y `enviados` deben coincidir exacto, porque ahí vive el historial que no se puede volver a generar.

Si de verdad se trata de una instalación nueva sin historial previo que conservar, omite este paso 8b y sigue con el 9 tal cual.

---

## 9. Paso 7: verificar (obligatorio)

Ejecuta las comprobaciones en orden. Todas deben dar el resultado esperado.

| # | Comando | Resultado esperado |
|---|---|---|
| 1 | `dc ps` | `siges-dashboard-db` en *healthy*; `web` y `sync` en *Up* (no *Restarting*). |
| 2 | `dc logs postgres \| grep -E 'docker-entrypoint-initdb.d\|ERROR'` | Tres líneas `running .../10-schema.sql`, `20-roles.sh` y `30-grants.sql`, y ningún `ERROR`. |
| 3 | `dc logs sync \| tail -n 5` | `Sincronizador inicializado; continuará desde cita SIGES <N>.` y ninguna línea `Error de sincronización`. |
| 4 | `dc run --rm --no-deps -T sync php scripts/verify-sync.php` | Las cuatro filas en `OK` y `RESULTADO: SINCRONIZADO`. |
| 5 | `curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8082/` | `200` (o el `WEB_PUBLISH_PORT` que hayas elegido). |
| 6 | `curl -s http://127.0.0.1:8082/ \| grep -o 'Datos cargados desde PostgreSQL'` | Aparece el texto. |
| 7 | `for p in config/local.php .env database/schema.sql scripts/sync-siges.php; do curl -s -o /dev/null -w "$p %{http_code}\n" http://127.0.0.1:8082/$p; done` | Todos `404`: no se exponen archivos privados. |
| 8 | Desde **otro equipo** de la red: `curl -m 5 http://<IP_SERVIDOR>:8082/` | Falla la conexión: el puerto solo escucha en `127.0.0.1`. |
| 9 | `ss -ltn \| grep -E ':(8082\|5432\|15433)\s'` | Solo direcciones `127.0.0.1:...` para los puertos de este sistema. |

Si la comprobación 3 o la 4 fallan, revisa la sección 15 (Solución de problemas). **No envíes mensajes para probar.** Para comprobar el envío, una persona puede usar **Recordatorios temporales** con su propio celular.

---

## 10. Paso 8: acceso del personal

La aplicación **no tiene inicio de sesión** y solo acepta peticiones del propio servidor; es una regla de seguridad de `AGENTS.md`. El personal entra con un **túnel SSH** desde su PC:

```powershell
# En la PC del usuario (PowerShell o Terminal); dejar la ventana abierta
ssh -N -L 8082:127.0.0.1:8082 <usuario>@<IP_SERVIDOR>
```

Después abre `http://127.0.0.1:8082/` en el navegador de esa PC.

- Crea una cuenta SSH por persona, sin privilegios de `root`. El túnel solo necesita poder conectarse.
- **No publiques el puerto 8082 en la red** ni cambies `127.0.0.1` por `0.0.0.0` en `docker-compose.yml`. Si se necesita acceso por navegador desde la red sin túnel, antes hay que desarrollar autenticación en la aplicación. Es un cambio de código, no de configuración.

---

## 11. Paso 9 (opcional): webhook de Gestionalo

Solo hace falta si se quieren **recibir** en *Chats* las respuestas de los pacientes. El envío de recordatorios funciona sin esto.

Gestionalo debe poder llamar a `https://<dominio>/chat-webhook.php` desde internet. Usa un proxy inverso con HTTPS en el servidor que reenvíe **exclusivamente** esa ruta a `127.0.0.1:8082`.

> ⚠️ **Crítico:** las peticiones que pasan por el proxy llegan a la aplicación desde la puerta de enlace de Docker, una IP que el filtro de acceso considera local. Si el proxy reenviara cualquier otra ruta, **toda la aplicación** (agenda, chats, envío de mensajes) quedaría expuesta en internet. El proxy debe responder 404 a todo lo que no sea `/chat-webhook.php`.

Ejemplo con nginx (`/etc/nginx/conf.d/siges-webhook.conf`):

```nginx
server {
    listen 443 ssl;
    server_name <dominio>;
    ssl_certificate     /etc/letsencrypt/live/<dominio>/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/<dominio>/privkey.pem;

    location = /chat-webhook.php {
        limit_except POST { deny all; }
        access_log off;                 # la URL lleva el token
        client_max_body_size 1m;
        proxy_pass http://127.0.0.1:8082;
        proxy_set_header Host $host;
    }

    location / { return 404; }
}
```

En Rocky Linux con SELinux, permite que nginx se conecte a puertos locales con `setsebool -P httpd_can_network_connect 1`. Abre el 443 en el firewall con `firewall-cmd --permanent --add-service=https && firewall-cmd --reload`.

En el panel de Gestionalo, configura como webhook de la línea `https://<dominio>/chat-webhook.php?token=<CHATBOT_WEBHOOK_TOKEN>`. Una persona copia el token de `config/local.php`.

**Comprobación** (sin enviar mensajes reales):

| Comando | Esperado |
|---|---|
| `curl -s -o /dev/null -w '%{http_code}\n' https://<dominio>/` | `404` |
| `curl -s -o /dev/null -w '%{http_code}\n' https://<dominio>/index.php` | `404` |
| `curl -s -o /dev/null -w '%{http_code}\n' -X POST 'https://<dominio>/chat-webhook.php?token=incorrecto'` | `403` |

El formato exacto del mensaje que envía Gestionalo **no está confirmado**. Si al llegar respuestas reales `dc logs web` muestra `Webhook de chat recibido sin celular o sin texto`, hay que ajustar el bloque `AJUSTA SOLO ESTE BLOQUE` de `public/chat-webhook.php` con la documentación de Gestionalo.

---

## 12. Operación diaria

| Tarea | Comando |
|---|---|
| Estado | `dc ps` |
| Seguir el sincronizador | `dc logs -f sync` (solo escribe cuando hay citas nuevas o cambiadas, o errores) |
| Errores de la web | `dc logs --since 1h web` |
| Verificar que coincide con SIGES | `dc run --rm --no-deps -T sync php scripts/verify-sync.php` (y `verify-destinations.php`, `verify-appointment-type.php`) |
| Reiniciar el worker (tras cambiar `config/local.php`) | `dc restart sync` |
| Detener todo sin perder datos | `dc stop` |
| Arrancar | `dc up -d` |
| Consola SQL de la base local | `docker exec -it siges-dashboard-db psql -U postgres -d siges_dashboard` |

- Los contenedores tienen `restart: unless-stopped` y Docker está habilitado al arranque, así que tras reiniciar el servidor todo vuelve solo.
- Los logs rotan a 5 archivos de 10 MB por contenedor.
- Los cambios en archivos PHP se ven al recargar la página. El worker carga su código al arrancar: tras cambiar `app/Services/` o `scripts/`, ejecuta `dc restart sync`.
- **Nunca** ejecutes `dc down -v`: borra el volumen con el historial de envíos, los contactos temporales y los chats.

---

## 13. Respaldos

Las citas se pueden volver a copiar desde SIGES, pero el **historial de envíos, los contactos temporales y los chats solo existen en esta base**. Para hacer un respaldo diario con 14 días de retención:

```bash
cat > /etc/cron.daily/siges-dashboard-backup <<'EOF'
#!/bin/sh
set -eu
dir=/var/backups/siges-dashboard
mkdir -p "$dir" && chmod 700 "$dir"
docker exec siges-dashboard-db pg_dump -U postgres -d siges_dashboard -Fc > "$dir/siges_dashboard-$(date +%F).dump"
find "$dir" -name 'siges_dashboard-*.dump' -mtime +14 -delete
EOF
chmod 700 /etc/cron.daily/siges-dashboard-backup
/etc/cron.daily/siges-dashboard-backup && ls -lh /var/backups/siges-dashboard
```

Los respaldos contienen datos de pacientes: no los copies a carpetas compartidas ni al repositorio.

Para restaurar, primero detén la web y el worker:

```bash
dc stop web sync
docker exec -i siges-dashboard-db pg_restore -U postgres -d siges_dashboard --clean --if-exists < /var/backups/siges-dashboard/siges_dashboard-AAAA-MM-DD.dump
dc start web sync
```

---

## 14. Actualizar a una versión nueva

```bash
cd /opt/siges-dashboard
/etc/cron.daily/siges-dashboard-backup          # respaldo previo
git fetch && git log --oneline HEAD..origin/<RAMA>   # revisar qué cambia
git pull
```

1. **Migraciones:** si `git pull` trajo archivos nuevos en `database/migrations/` con número mayor que el último aplicado, aplícalos en orden:
   ```bash
   docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard -v ON_ERROR_STOP=1 < database/migrations/0NN_nombre.sql
   ```
   Una instalación nueva ya incluye las migraciones hasta la `009` en `schema.sql`; no las vuelvas a aplicar.
2. **Imagen PHP:** si cambió algo en `docker/php/`, ejecuta `dc up -d --build`. Si no, basta con `dc up -d && dc restart sync`.
3. **Verificación:** repite las comprobaciones 1, 3, 4 y 5 del paso 7.

Para volver a la versión anterior: `git checkout <commit_anterior>`, `dc restart web sync` y, si hubo migraciones, restaura el respaldo previo (sección 13).

---

## 15. Solución de problemas

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| `dc up` falla: `port is already allocated` | 5432 u 8082 ocupados | `DB_PUBLISH_PORT` / `WEB_PUBLISH_PORT` en `.env` (paso 3). |
| `dc up` falla: `Pool overlaps with other one` | La subred 172.28.0.0/24 ya existe | `DOCKER_SUBNET` y `DOCKER_GATEWAY_IP` en `.env`. |
| La web responde `403 ... limitada al equipo local` | `DOCKER_GATEWAY_IP` no coincide con la puerta de enlace real | `docker network inspect siges-dashboard -f '{{(index .IPAM.Config 0).Gateway}}'` debe ser igual a `DOCKER_GATEWAY_IP`. |
| `Permission denied` al leer archivos dentro del contenedor | Se arrancó sin `docker-compose.prod.yml` (SELinux) | Usa siempre el alias `dc` y luego `dc up -d --force-recreate`. |
| Página: `No se pudo conectar con la base de datos` | `DB_PASSWORD` distinto de `SIGES_APP_PASSWORD`, o permisos de `config/local.php` | Paso 4; `stat` debe dar `root:33 640`; `dc logs web`. |
| `sync` en *Restarting* con `Configura SIGES_PG_HOST...` | Falta un valor en `config/local.php` | `grep -c CAMBIAR config/local.php` debe ser 0; luego `dc restart sync`. |
| `Error de sincronización (PDOException): ... Connection refused` / `timeout` | El contenedor no llega al PostgreSQL de SIGES | Prueba: `dc run --rm --no-deps -T sync php -r 'var_dump((bool) @fsockopen(getenv("SIGES_PG_HOST"), 5432, $e, $m, 3));'`. Si da `false`: revisa `SIGES_PG_HOST`/`SIGES_PG_PORT` y que PostgreSQL escuche fuera de localhost (`listen_addresses`). Si SIGES está en el mismo servidor, el firewall debe permitir el tráfico desde la red de Docker: `firewall-cmd --permanent --zone=trusted --add-source=172.28.0.0/24 && firewall-cmd --reload`. |
| `... no pg_hba.conf entry for host "172.28.0.x"` | `pg_hba.conf` de SIGES no acepta la red de Docker | Paso 5, apartado *Conexión desde Docker*. |
| `... password authentication failed for user "siges_reader"` | Contraseña incorrecta | Corrige `SIGES_PG_PASSWORD` y ejecuta `dc restart sync`. |
| `verify-sync.php` muestra `DIFERENTE` | El worker acaba de arrancar, o está detenido | Espera 10 s y repite. Si persiste: `dc logs sync`. |
| Agenda vacía pero `sync` sin errores | No hay citas `RESERVADO` cuya fecha y hora estén por venir | Comprueba con `verify-sync.php` (fila *Visibles en agenda*). |
| Envío: `Gestionalo rechazó la solicitud (HTTP 401/403)` | Serie incorrecta | Revisa `CHATBOT_LINE_KEY` con el proveedor. |
| Imagen adjunta rechazada por tamaño | Límite de 5 MB | Comportamiento esperado; límites PHP en `docker/php/app.ini`. |
| Ya se usó la aplicación contra datos reales antes de instalar el servidor oficial (p. ej. en desarrollo) | El servidor nuevo arranca con la base vacía, sin el historial de envíos | Paso 8b: respalda esa base de origen y restáurala en el servidor **antes** de que el personal empiece a usarlo, o se arriesga a reenviar recordatorios ya enviados. |

---

## 16. Diferencias entre desarrollo y producción

| Aspecto | Desarrollo (Windows + Docker Desktop) | Servidor oficial (Linux) |
|---|---|---|
| Arranque | `docker compose up -d` | `dc up -d` (base + `docker-compose.prod.yml`) |
| Acceso a SIGES | Túnel SSH en Windows → `host.docker.internal:15432` | Directo: mismo servidor (`host.docker.internal:5432`) u otra IP (`SIGES_PG_HOST`) |
| `SIGES_PG_PORT` | `15432` | `5432` |
| Puerto publicado de la base local | `127.0.0.1:5432` | `127.0.0.1:15433` si SIGES está en el mismo servidor |
| SELinux | No aplica | Montajes con `:z` (`docker-compose.prod.yml`) |
| Acceso de usuarios | Navegador del propio equipo | Túnel SSH por usuario |
| Secretos | `.env` y `config/local.php` propios | Generados en el servidor (pasos 3 y 4); nunca copiados |
| Respaldos | Manuales | Diarios (`/etc/cron.daily/siges-dashboard-backup`) |
