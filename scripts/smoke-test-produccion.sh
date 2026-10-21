#!/usr/bin/env bash
# Prueba de humo del despliegue en producción. Solo lectura: no envía mensajes,
# no escribe en SIGES y no imprime datos de pacientes ni secretos.
# Uso (desde la carpeta del proyecto):  bash scripts/smoke-test-produccion.sh [IP] [PUERTO]
cd "$(dirname "$0")/.." || exit 1

IP="${1:-192.168.1.71}"
PORT="${2:-8091}"
BASE="http://$IP:$PORT"
DC="docker compose -f docker-compose.yml -f docker-compose.lan.yml"
PASS=0; FAIL=0

ok()   { echo "  [OK]    $1"; PASS=$((PASS+1)); }
fail() { echo "  [FALLA] $1"; FAIL=$((FAIL+1)); }
check() { if eval "$2" >/dev/null 2>&1; then ok "$1"; else fail "$1"; fi; }
http() { curl -s -m 10 -o /dev/null -w '%{http_code}' "$@"; }
psql_local() { docker exec -i siges-dashboard-db psql -U postgres -d siges_dashboard -tA "$@"; }

echo "== 1. Contenedores =="
for c in siges-dashboard-db siges-dashboard-web siges-dashboard-sync; do
  st=$(docker inspect -f '{{.State.Status}}' "$c" 2>/dev/null)
  rs=$(docker inspect -f '{{.RestartCount}}' "$c" 2>/dev/null)
  [ "$st" = "running" ] && ok "$c en ejecución (reinicios: $rs)" || fail "$c no está en ejecución ($st)"
done
check "Base local saludable" '[ "$(docker inspect -f "{{.State.Health.Status}}" siges-dashboard-db)" = healthy ]'

echo "== 2. Sincronización con SIGES =="
errs=$($DC logs --since 10m sync 2>&1 | grep -c "Error de sincronización")
[ "$errs" = "0" ] && ok "Sin errores del worker en los últimos 10 min" || fail "$errs errores del worker en 10 min"
out=$($DC run --rm --no-deps -T sync php scripts/verify-sync.php 2>&1)
echo "$out" | grep -q "RESULTADO: SINCRONIZADO" && ok "verify-sync: SINCRONIZADO" || { fail "verify-sync no sincronizado"; echo "$out" | tail -6; }
out=$($DC run --rm --no-deps -T sync php scripts/verify-appointment-type.php 2>&1)
echo "$out" | grep -q "RESULTADO: CORRECTO" && ok "verify-appointment-type: solo tipo NORMAL" || fail "verify-appointment-type"
out=$($DC run --rm --no-deps -T sync php scripts/verify-destinations.php 2>&1)
echo "$out" | grep -q "RESULTADO: todos los celulares guardados coinciden" && ok "verify-destinations: celulares coinciden con SIGES" || fail "verify-destinations (revisa la salida del script)"

echo "== 3. Web por la red ($BASE) e inicio de sesión =="
[ "$(http "$BASE/login.php")" = "200" ] && ok "login.php responde 200" || fail "login.php no responde 200"
for p in index.php messages.php chats.php manual-reminders.php bulk-reminders.php; do
  c=$(http "$BASE/$p"); [ "$c" = "303" ] && ok "$p sin sesión redirige al login (303)" || fail "$p sin sesión respondió $c (debe ser 303)"
done
for p in chat-messages.php appointments-version.php; do
  c=$(http "$BASE/$p"); [ "$c" = "401" ] && ok "$p sin sesión responde 401" || fail "$p sin sesión respondió $c (debe ser 401)"
done
r=$(psql_local -c "SELECT COUNT(*) FROM users WHERE active AND role='admin'")
[ "${r:-0}" -ge 1 ] 2>/dev/null && ok "Hay al menos un administrador activo ($r)" || fail "No hay ningún administrador activo"
r=$(psql_local -c "SELECT COUNT(*) FROM information_schema.columns WHERE column_name='sent_by_user_id' AND table_name IN ('reminder_messages','chat_messages')")
[ "$r" = "2" ] && ok "Auditoría de envíos (sent_by_user_id) presente" || fail "Falta sent_by_user_id (migración 010)"
# Con credenciales opcionales (SMOKE_USER / SMOKE_PASSWORD) también se prueba el flujo completo.
if [ -n "${SMOKE_USER:-}" ] && [ -n "${SMOKE_PASSWORD:-}" ]; then
  jar=$(mktemp)
  tok=$(curl -s -m 10 -c "$jar" -b "$jar" "$BASE/login.php" | grep -o 'csrf_token" value="[a-f0-9]*' | cut -c20-)
  c=$(curl -s -m 10 -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' --data-urlencode "csrf_token=$tok" --data-urlencode "username=$SMOKE_USER" --data-urlencode "password=$SMOKE_PASSWORD" "$BASE/login.php")
  [ "$c" = "303" ] && ok "Inicio de sesión con $SMOKE_USER correcto" || fail "Inicio de sesión con $SMOKE_USER falló (HTTP $c)"
  [ "$(http -b "$jar" "$BASE/")" = "200" ] && ok "Agenda responde 200 con sesión" || fail "Agenda no responde 200 con sesión"
  curl -s -m 10 -b "$jar" "$BASE/" | grep -q "Datos cargados desde PostgreSQL" && ok "Agenda carga datos de PostgreSQL" || fail "Agenda no muestra datos de PostgreSQL"
  for p in messages.php chats.php manual-reminders.php bulk-reminders.php appointments-version.php chat-messages.php; do
    [ "$(http -b "$jar" "$BASE/$p")" = "200" ] && ok "$p responde 200 con sesión" || fail "$p no responde 200 con sesión"
  done
  rm -f "$jar"
else
  echo "  (omitido) Flujo con sesión: define SMOKE_USER y SMOKE_PASSWORD para probarlo"
fi

echo "== 4. Seguridad =="
for f in config/local.php .env database/schema.sql scripts/sync-siges.php docker-compose.yml app/Services/ChatbotApiClient.php .git/config; do
  c=$(http "$BASE/$f"); [ "$c" = "404" ] || [ "$c" = "403" ] && ok "No expone $f ($c)" || fail "EXPONE $f (HTTP $c)"
done
[ "$(http -X POST "$BASE/send-reminder.php")" = "303" ] && ok "send-reminder.php sin sesión no envía nada (303 al login)" || fail "send-reminder.php respondió 200 a un POST vacío"
[ "$(http -X POST "$BASE/chat-webhook.php?token=incorrecto")" = "403" ] && ok "Webhook rechaza token inválido (403)" || fail "Webhook no rechaza token inválido"
[ "$(http "$BASE/chat-webhook.php")" = "403" ] && ok "Webhook sin token rechazado (403)" || fail "Webhook sin token"
ports=$(docker port siges-dashboard-db 2>/dev/null)
echo "$ports" | grep -q "0.0.0.0" && fail "La base local está publicada en 0.0.0.0" || ok "La base local solo escucha en 127.0.0.1"

echo "== 5. Base local =="
r=$(psql_local -c "SELECT COUNT(*) FROM reminder_messages")
[ "${r:-0}" -ge 98 ] 2>/dev/null && ok "Historial de envíos presente (reminder_messages >= 98: $r)" || fail "reminder_messages = $r (se esperaban >= 98)"
r=$(psql_local -c "SELECT COUNT(*) FROM reminder_messages WHERE status='sent'")
[ "${r:-0}" -ge 92 ] 2>/dev/null && ok "Enviados conservados (>= 92: $r)" || fail "enviados = $r (se esperaban >= 92)"
r=$(psql_local -c "SELECT COUNT(*) FROM manual_contacts")
[ "${r:-0}" -ge 2 ] 2>/dev/null && ok "Contactos temporales presentes ($r)" || fail "manual_contacts = $r"
r=$(psql_local -c "SELECT COUNT(*) FROM appointments WHERE siges_cita_id IS NOT NULL AND status='RESERVADO' AND appointment_date >= CURRENT_DATE")
[ "${r:-0}" -gt 0 ] 2>/dev/null && ok "Hay citas RESERVADO vigentes ($r)" || fail "Sin citas RESERVADO vigentes"
r=$(psql_local -c "SELECT current_setting('TimeZone')")
ok "Zona horaria de la base: $r"
# El rol de la web no debe poder modificar appointments (solo el worker puede).
r=$(psql_local -c "BEGIN; SET ROLE siges_app; UPDATE appointments SET status=status WHERE false; ROLLBACK;" 2>&1)
echo "$r" | grep -qi "permission denied\|permiso denegado" && ok "siges_app no puede escribir en appointments" || fail "siges_app PUEDE escribir en appointments"
$DC exec -T web php -r 'exit(0);' >/dev/null 2>&1

echo "== 5b. Escrituras de la aplicación (se deshacen) =="
out=$(docker exec siges-dashboard-web php scripts/verify-writes.php 2>&1)
echo "$out" | grep -q "RESULTADO: escrituras correctas" && ok "Registro de envíos, contactos y chats funciona con los permisos reales" || { fail "Fallan escrituras de la aplicación"; echo "$out" | grep FALLA; }

echo "== 6. Configuración de la aplicación =="
r=$($DC exec -T web php -r 'require "/var/www/app/app/Services/ChatbotApiClient.php"; echo ChatbotApiClient::isConfigured() ? "si" : "no";' 2>/dev/null)
[ "$r" = "si" ] && ok "Gestionalo configurado (CHATBOT_LINE_KEY presente)" || fail "Gestionalo NO configurado"
check "config/local.php sin valores CAMBIAR" '! grep -q "'"'"'CAMBIAR'"'"'" config/local.php'
r=$($DC exec -T web php -r '$c=require "/var/www/app/config/local.php"; echo !empty($c["CHATBOT_WEBHOOK_TOKEN"]) && $c["CHATBOT_WEBHOOK_TOKEN"]!=="CAMBIAR" ? "si":"no"; echo ($c["CHATBOT_WEBHOOK_DEBUG"]??false) ? "-debug":"";' 2>/dev/null)
[ "$r" = "si" ] && ok "Token de webhook definido y modo diagnóstico apagado" || fail "Token de webhook ausente o modo diagnóstico activo ($r)"

echo
echo "RESULTADO: $PASS correctas, $FAIL con falla"
[ "$FAIL" = "0" ] && echo "PRODUCCIÓN FUNCIONANDO CORRECTAMENTE" || echo "HAY PROBLEMAS: revisa las líneas [FALLA]"
exit "$FAIL"
