#!/usr/bin/env bash
# Prueba de seguridad del despliegue (solo lectura: no envía mensajes ni modifica datos).
# Uso (desde la carpeta del proyecto):
#   SEC_USER=operador1 SEC_PASSWORD='...' bash scripts/security-test.sh [IP] [PUERTO]
# Sin SEC_USER/SEC_PASSWORD solo se ejecutan las pruebas sin sesión.
# Usa un usuario con rol operador: así también comprueba que no accede a lo de administradores.
cd "$(dirname "$0")/.." || exit 1

IP="${1:-192.168.1.71}"
PORT="${2:-8091}"
B="http://$IP:$PORT"
PASS=0; FAIL=0; WARN=0
ok()   { echo "  [OK]    $1"; PASS=$((PASS+1)); }
fail() { echo "  [FALLA] $1"; FAIL=$((FAIL+1)); }
warn() { echo "  [AVISO] $1"; WARN=$((WARN+1)); }
code() { curl -s -m 10 -o /dev/null -w '%{http_code}' "$@"; }
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
jar="$tmp/jar"

echo "== 1. Control de acceso sin sesión =="
for p in index.php messages.php chats.php manual-reminders.php bulk-reminders.php health.php; do
  [ "$(code "$B/$p")" = "303" ] && ok "$p exige iniciar sesión" || fail "$p accesible sin sesión"
done
for p in chat-messages.php appointments-version.php; do
  [ "$(code "$B/$p")" = "401" ] && ok "$p responde 401 sin sesión" || fail "$p accesible sin sesión"
done
for ep in "send-reminder.php" "manual-reminders.php?action=delete" "chats.php?action=send" "bulk-reminders.php?action=send"; do
  [ "$(code -X POST -d 'x=1' "$B/$ep")" = "303" ] && ok "POST $ep sin sesión no hace nada" || fail "POST $ep sin sesión"
done
[ "$(code -X POST "$B/chat-webhook.php?token=incorrecto")" = "403" ] && ok "Webhook rechaza token inválido" || fail "Webhook acepta token inválido"
[ "$(code -X POST "$B/chat-webhook.php")" = "403" ] && ok "Webhook rechaza petición sin token" || fail "Webhook acepta petición sin token"

echo "== 2. Archivos y rutas sensibles =="
for p in .git/HEAD .git/config .env config/local.php config/auth.php config/database.php database/schema.sql docs/despliegue-produccion.md views/login.php app/Services/ChatbotApiClient.php scripts/sync-siges.php docker-compose.yml phpinfo.php info.php; do
  c=$(code --path-as-is "$B/$p"); { [ "$c" = "404" ] || [ "$c" = "403" ]; } && ok "No expone $p ($c)" || fail "EXPONE $p (HTTP $c)"
done
c=$(code --path-as-is "$B/assets/..%2f..%2fconfig/local.php"); [ "$c" = "404" ] || [ "$c" = "400" ] || [ "$c" = "403" ] && ok "Resiste recorrido de directorios ($c)" || fail "Recorrido de directorios (HTTP $c)"
[ "$(code "$B/assets/")" != "200" ] && ok "Sin listado de directorios en /assets/" || fail "Listado de directorios habilitado"
[ "$(code -X TRACE "$B/")" != "200" ] && ok "TRACE deshabilitado" || fail "TRACE habilitado"

echo "== 3. Cabeceras y cookies =="
h=$(curl -s -m 10 -D - -o /dev/null "$B/login.php")
echo "$h" | grep -qi '^X-Powered-By' && fail "Expone X-Powered-By" || ok "No expone X-Powered-By"
echo "$h" | grep -qi '^Server: Apache/' && warn "Server revela la versión de Apache" || ok "Server no revela versión"
echo "$h" | grep -qi 'Set-Cookie:.*HttpOnly' && ok "Cookie de sesión HttpOnly" || fail "Cookie de sesión sin HttpOnly"
echo "$h" | grep -qi 'Set-Cookie:.*SameSite' && ok "Cookie de sesión con SameSite" || fail "Cookie de sesión sin SameSite"
echo "$h" | grep -qi 'Set-Cookie:.*Secure' && ok "Cookie de sesión Secure" || warn "Cookie de sesión sin Secure (el sitio va por HTTP, sin HTTPS)"
echo "$h" | grep -qi '^X-Frame-Options\|frame-ancestors' && ok "Protección contra clickjacking" || warn "Sin X-Frame-Options / frame-ancestors (clickjacking)"
echo "$h" | grep -qi '^X-Content-Type-Options: *nosniff' && ok "X-Content-Type-Options: nosniff" || warn "Falta X-Content-Type-Options: nosniff"
echo "$h" | grep -qi '^Content-Security-Policy' && ok "Content-Security-Policy presente" || warn "Falta Content-Security-Policy"
echo "$h" | grep -qi '^Referrer-Policy' && ok "Referrer-Policy presente" || warn "Falta Referrer-Policy"
echo "$h" | grep -qi '^Cache-Control:.*no-store' && ok "login.php sin caché" || warn "login.php cacheable"
[ "${B#https}" != "$B" ] && echo "$h" | grep -qi '^Strict-Transport-Security' && ok "HSTS presente" || warn "Sin HTTPS/HSTS: las contraseñas viajan en claro por la red"

echo "== 4. Contenedores y secretos =="
for c in siges-dashboard-web siges-dashboard-sync siges-dashboard-db; do
  [ "$(docker inspect -f '{{.HostConfig.Privileged}}' $c 2>/dev/null)" = "false" ] && ok "$c no es privilegiado" || fail "$c privilegiado o ausente"
done
docker port siges-dashboard-db 2>/dev/null | grep -q '0.0.0.0' && fail "Base local publicada en 0.0.0.0" || ok "Base local solo en 127.0.0.1"
git check-ignore -q .env && git check-ignore -q config/local.php && ok ".env y config/local.php ignorados por Git" || fail ".env o config/local.php NO están ignorados"
[ -z "$(git ls-files | grep -E '(^|/)\.env$|local\.php$|\.dump$')" ] && ok "Ningún secreto ni dump versionado" || fail "Hay secretos o dumps versionados"
n=$(docker logs siges-dashboard-web 2>&1 | grep -ciE 'password|CHATBOT_LINE_KEY'); [ "$n" = "0" ] && ok "Sin contraseñas ni claves en los logs del web" || fail "$n líneas con posibles secretos en los logs del web"
n=$(docker logs siges-dashboard-web 2>&1 | grep -c 'token='); [ "$n" = "0" ] && ok "Token del webhook ausente de los logs de acceso" || warn "El token del webhook aparece $n veces en los logs de acceso (va en la URL)"
r=$(docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard -tA -c "SELECT count(*) FROM users WHERE password_hash NOT LIKE '\$2y\$%' AND password_hash NOT LIKE '\$argon2%'" 2>/dev/null)
[ "$r" = "0" ] && ok "Todas las contraseñas de usuarios están con hash" || fail "Hay contraseñas sin hash ($r)"
r=$(docker exec -i siges-dashboard-db psql -U postgres -tA -c "SELECT count(*) FROM pg_roles WHERE rolname IN ('siges_app','siges_sync') AND (rolsuper OR rolcreaterole OR rolcreatedb)" 2>/dev/null)
[ "$r" = "0" ] && ok "Roles de la aplicación sin privilegios de administración" || fail "Roles con privilegios excesivos"
r=$(docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard -tA -c "SELECT count(*) FROM information_schema.role_table_grants WHERE grantee='siges_app' AND table_name='appointments' AND privilege_type IN ('INSERT','UPDATE','DELETE')" 2>/dev/null)
[ "$r" = "0" ] && ok "La web no puede modificar appointments" || fail "La web puede modificar appointments"

if [ -n "${SEC_USER:-}" ] && [ -n "${SEC_PASSWORD:-}" ]; then
  echo "== 5. Con sesión de $SEC_USER =="
  tok=$(curl -s -m 10 -c "$jar" -b "$jar" "$B/login.php" | grep -o 'csrf_token" value="[a-f0-9]*' | cut -c20-)
  before=$(awk '/SIGESSESSID/{print $7}' "$jar")
  c=$(curl -s -m 10 -c "$jar" -b "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "csrf_token=$tok" --data-urlencode "username=$SEC_USER" --data-urlencode "password=$SEC_PASSWORD" --data-urlencode "next=https://evil.example" "$B/login.php")
  after=$(awk '/SIGESSESSID/{print $7}' "$jar")
  case "$c" in "303 $B/index.php") ok "El inicio de sesión ignora una redirección externa (next)";; "303 "*) fail "Redirección inesperada tras login: $c";; *) fail "No se pudo iniciar sesión (HTTP $c)";; esac
  [ -n "$after" ] && [ "$before" != "$after" ] && ok "ID de sesión regenerado al iniciar sesión" || fail "ID de sesión NO regenerado (fijación de sesión)"
  for p in index.php messages.php chats.php manual-reminders.php bulk-reminders.php; do
    [ "$(code -b "$jar" "$B/$p")" = "200" ] && ok "$p accesible con sesión" || fail "$p no accesible con sesión"
  done
  r=$(code -b "$jar" "$B/health.php")
  if docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard -tA -c "SELECT role FROM users WHERE LOWER(username)=LOWER('$SEC_USER')" 2>/dev/null | grep -q operador; then
    [ "$r" = "403" ] && ok "Un operador no accede al Estado del sistema (403)" || fail "Un operador accede al Estado del sistema (HTTP $r)"
  fi
  for q in 'index.php?q=<script>alert(1)</script>' 'messages.php?q="><script>alert(1)</script>' 'bulk-reminders.php?fecha=<script>alert(1)</script>' 'chats.php?telefono=<script>alert(1)</script>'; do
    n=$(curl -s -m 10 -b "$jar" -G --data-urlencode "x=1" "$B/${q%%\?*}" --data-urlencode "${q#*\?}" | grep -c '<script>alert(1)</script>')
    [ "$n" = "0" ] && ok "Sin XSS reflejado en ${q%%\?*}" || fail "XSS reflejado en ${q%%\?*}"
  done
  for ep in "send-reminder.php|appointment_id=1&destination=51999999999&message=x&confirm_transmission=yes" "manual-reminders.php?action=delete|contact_id=999999" "manual-reminders.php?action=send|contact_id=999999&message=x&confirm_transmission=yes" "chats.php?action=send|destination=51999999999&message=x" "bulk-reminders.php?action=send|fecha=2026-10-20&appointment_id[]=1&confirm_transmission=yes"; do
    u=${ep%%|*}; d=${ep#*|}
    curl -s -m 10 -b "$jar" -c "$jar" -L --max-redirs 1 -d "$d" "$B/$u" | grep -q 'La sesión expiró' && ok "POST $u sin token CSRF es rechazado" || fail "POST $u sin token CSRF NO fue rechazado"
  done
  tok=$(curl -s -m 10 -b "$jar" "$B/messages.php" | grep -o 'csrf_token" value="[a-f0-9]*' | head -1 | cut -c20-)
  curl -s -m 10 -b "$jar" -c "$jar" -o /dev/null -d "csrf_token=$tok" "$B/logout.php"
  [ "$(code -b "$jar" "$B/index.php")" = "303" ] && ok "Cerrar sesión invalida la sesión" || fail "La sesión sigue activa tras cerrar sesión"
else
  echo "== 5. Con sesión == (omitido: define SEC_USER y SEC_PASSWORD de un operador)"
fi

echo
echo "RESULTADO: $PASS correctas, $WARN avisos, $FAIL fallas"
[ "$FAIL" = "0" ] && echo "SIN FALLAS CRÍTICAS (revisa los avisos)" || echo "HAY FALLAS DE SEGURIDAD: revisa las líneas [FALLA]"
exit "$FAIL"
