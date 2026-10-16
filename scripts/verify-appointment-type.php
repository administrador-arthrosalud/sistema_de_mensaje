<?php
declare(strict_types=1);

/**
 * Comprueba en las dos direcciones que solo las citas tipo NORMAL de SIGES
 * quedan activas en la base local, y que ninguna ADICIONAL/PROCEDIMIENTO/otro
 * tipo se cuela. No imprime nombres ni celulares, solo conteos e ids de SIGES.
 *
 * Uso: docker compose run --rm --no-deps -T sync php scripts/verify-appointment-type.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$localPath = __DIR__ . '/../config/local.php';
$local = is_file($localPath) ? require $localPath : [];
$read = static function (string $name, string $default = '') use ($local): string {
    $value = getenv($name);
    return (string) (($value !== false && $value !== '') ? $value : ($local[$name] ?? $default));
};
$timezone = $read('APP_TIMEZONE', 'America/Lima');

try {
    $siges = postgresConnection($read('SIGES_PG_HOST'), $read('SIGES_PG_PORT', '5432'), $read('SIGES_PG_DATABASE', 'isis3_arths'),
        $read('SIGES_PG_USER'), $read('SIGES_PG_PASSWORD'), $read('SIGES_PG_SSLMODE', 'prefer'), $timezone);
    $siges->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
    $app = databaseConnection(); // rol siges_app: tiene permiso de lectura sobre appointments
} catch (Throwable $error) {
    fwrite(STDERR, 'No se pudo conectar (' . get_class($error) . '): ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

$allOk = true;

echo "=== 1) Tipos que existen en SIGES desde hoy ===\n";
foreach ($siges->query("SELECT tipo::text AS tipo, COUNT(*) FROM public.cita WHERE fecha >= CURRENT_DATE GROUP BY 1 ORDER BY 2 DESC") as $row) {
    printf("%-15s %d\n", $row['tipo'], $row['count']);
}

echo "\n=== 2) ¿Alguna cita RESERVADO en nuestra base (lo único visible en agenda/envío) corresponde a un tipo distinto de NORMAL en SIGES? ===\n";
// RESERVADO + fecha/hora no pasada es exactamente lo que AppointmentRepository
// (agenda y envío masivo) considera visible/enviable. Una cita de una fecha ya
// pasada deja de rastrearse (el worker solo compara "hoy en adelante"), así que
// su status puede quedar desactualizado sin que afecte a nada visible: por eso
// se aplica aquí el mismo filtro de fecha/hora que usa la aplicación real.
$reservedIds = $app->query(
    "SELECT siges_cita_id FROM appointments
     WHERE siges_cita_id IS NOT NULL AND status = 'RESERVADO'
       AND (appointment_date > CURRENT_DATE OR (appointment_date = CURRENT_DATE AND appointment_time >= LOCALTIME))"
)->fetchAll(PDO::FETCH_COLUMN);
$leaked = [];
if ($reservedIds !== []) {
    $placeholders = implode(',', array_map('intval', $reservedIds));
    $stmt = $siges->query("SELECT id, tipo::text AS tipo FROM public.cita WHERE id IN ($placeholders) AND tipo::text <> 'NORMAL'");
    foreach ($stmt as $row) { $leaked[] = $row['id'] . ' (tipo=' . $row['tipo'] . ')'; }
}
printf("Citas RESERVADO en local: %d\n", count($reservedIds));
if ($leaked === []) {
    echo "OK: ninguna cita RESERVADO (visible/enviable) tiene un tipo distinto de NORMAL.\n";
} else {
    $allOk = false;
    echo 'FALLO: se colaron ', count($leaked), " citas RESERVADO que no son NORMAL:\n";
    foreach (array_slice($leaked, 0, 10) as $line) { echo '  - cita_siges=', $line, "\n"; }
}

echo "\n=== 3) ¿Toda cita tipo NORMAL de SIGES (desde hoy) está presente en la base local? ===\n";
$sigesNormalIds = $siges->query("SELECT id FROM public.cita WHERE fecha >= CURRENT_DATE AND tipo::text = 'NORMAL'")->fetchAll(PDO::FETCH_COLUMN);
$localAllIds = $app->query("SELECT siges_cita_id FROM appointments WHERE siges_cita_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$localAllIds = array_map('intval', $localAllIds);
$missing = array_diff(array_map('intval', $sigesNormalIds), $localAllIds);
printf("Citas NORMAL en SIGES: %d | presentes en local (cualquier estado): %d\n", count($sigesNormalIds), count($sigesNormalIds) - count($missing));
if ($missing === []) {
    echo "OK: todas las citas NORMAL de SIGES están en la base local.\n";
} else {
    $allOk = false;
    echo 'FALLO: faltan ', count($missing), " citas NORMAL (no se importaron): ", implode(', ', array_slice($missing, 0, 10)), "\n";
}

echo "\n=== 4) ¿Las citas ADICIONAL/otro tipo quedan como RESERVADO en la base local (visibles/enviables)? ===\n";
$sigesNonNormal = $siges->query("SELECT id, tipo::text AS tipo FROM public.cita WHERE fecha >= CURRENT_DATE AND tipo::text <> 'NORMAL'")->fetchAll();
$badState = [];
foreach ($sigesNonNormal as $row) {
    $localStatus = $app->prepare('SELECT status FROM appointments WHERE siges_cita_id = :id');
    $localStatus->execute([':id' => (int) $row['id']]);
    $status = $localStatus->fetchColumn();
    if ($status === 'RESERVADO') {
        $badState[] = $row['id'] . ' (tipo=' . $row['tipo'] . ')';
    }
}
printf("Citas no-NORMAL en SIGES: %d\n", count($sigesNonNormal));
if ($badState === []) {
    echo "OK: ninguna quedó como RESERVADO en la base local (o no están ahí, o están en otro estado).\n";
} else {
    $allOk = false;
    echo 'FALLO: ', count($badState), " quedaron como RESERVADO indebidamente:\n";
    foreach ($badState as $line) { echo '  - cita_siges=', $line, "\n"; }
}

echo "\n", $allOk ? "RESULTADO: CORRECTO — solo tipo NORMAL queda activo en el sistema.\n" : "RESULTADO: HAY FALLOS — revisa el detalle arriba.\n";
exit($allOk ? 0 : 1);
