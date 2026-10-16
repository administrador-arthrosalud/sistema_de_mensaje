<?php
declare(strict_types=1);

/**
 * Compara SIGES con la copia local: totales y una huella md5 de
 * id|fecha|hora|estado de cada cita desde hoy. La sesión de SIGES es de solo
 * lectura. No imprime datos de pacientes. Sale con código 0 si todo coincide.
 *
 * Uso (Docker): docker compose run --rm --no-deps -T sync php scripts/verify-sync.php
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
    $app = postgresConnection($read('SIGES_SYNC_DB_HOST', $read('DB_HOST', '127.0.0.1')), $read('SIGES_SYNC_DB_PORT', $read('DB_PORT', '5432')),
        $read('SIGES_SYNC_DB_NAME', $read('DB_NAME', 'siges_dashboard')), $read('SIGES_SYNC_DB_USER'), $read('SIGES_SYNC_DB_PASSWORD'),
        $read('DB_SSLMODE', 'prefer'), $timezone);
} catch (Throwable $error) {
    fwrite(STDERR, 'No se pudo conectar (' . get_class($error) . '): ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

$sigesRow = $siges->query(
    "SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE estado::text = 'RESERVADO') AS reservado,
            COUNT(*) FILTER (WHERE estado::text = 'RESERVADO'
                AND (fecha > CURRENT_DATE OR (fecha = CURRENT_DATE AND hora::time >= LOCALTIME))) AS agenda,
            COALESCE(md5(string_agg(id || '|' || fecha || '|' || to_char(hora::time, 'HH24:MI:SS') || '|' || estado::text,
                ',' ORDER BY id)), '') AS huella
     FROM public.cita
     -- Solo tipo NORMAL se sincroniza a propósito (ver SigesAppointmentSyncService).
     WHERE fecha >= CURRENT_DATE AND tipo::text = 'NORMAL'"
)->fetch();
$localRow = $app->query(
    "SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'RESERVADO') AS reservado,
            COUNT(*) FILTER (WHERE status = 'RESERVADO'
                AND (appointment_date > CURRENT_DATE OR (appointment_date = CURRENT_DATE AND appointment_time >= LOCALTIME))) AS agenda,
            COALESCE(md5(string_agg(siges_cita_id || '|' || appointment_date || '|' || to_char(appointment_time, 'HH24:MI:SS') || '|' || status,
                ',' ORDER BY siges_cita_id)), '') AS huella
     FROM appointments
     WHERE siges_cita_id IS NOT NULL AND appointment_date >= CURRENT_DATE AND status <> 'NO_VIGENTE'"
)->fetch();

$allMatch = true;
printf("%-24s %-10s %-10s\n", '', 'SIGES', 'Local');
foreach (['total' => 'Citas desde hoy', 'reservado' => 'RESERVADO desde hoy', 'agenda' => 'Visibles en agenda', 'huella' => 'Huella md5'] as $key => $label) {
    $sigesValue = (string) $sigesRow[$key];
    $localValue = (string) $localRow[$key];
    $match = $sigesValue === $localValue;
    $allMatch = $allMatch && $match;
    if ($key === 'huella') {
        [$sigesValue, $localValue] = [substr($sigesValue, 0, 8), substr($localValue, 0, 8)];
    }
    printf("%-24s %-10s %-10s %s\n", $label, $sigesValue, $localValue, $match ? 'OK' : 'DIFERENTE');
}
echo 'Hora local: ', $app->query('SELECT LOCALTIMESTAMP(0)')->fetchColumn(), PHP_EOL;
echo $allMatch ? "RESULTADO: SINCRONIZADO\n" : "RESULTADO: DIFERENCIAS (si el worker acaba de arrancar, espera 10 s y repite)\n";
exit($allMatch ? 0 : 1);
