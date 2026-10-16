<?php
declare(strict_types=1);

/**
 * Compara el celular guardado localmente (appointments.destination) contra lo
 * que SIGES tiene ahora mismo en cita.contactos, y valida el formato de envío.
 * No imprime nombres ni números completos: solo conteos y, para anomalías,
 * una versión parcialmente oculta (los dos primeros y los dos últimos
 * dígitos), suficiente para que el personal la ubique en la pantalla sin que
 * quede expuesta aquí.
 *
 * Uso: docker compose run --rm --no-deps -T sync php scripts/verify-destinations.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/SigesAppointmentSyncService.php';

$localPath = __DIR__ . '/../config/local.php';
$local = is_file($localPath) ? require $localPath : [];
$read = static function (string $name, string $default = '') use ($local): string {
    $value = getenv($name);
    return (string) (($value !== false && $value !== '') ? $value : ($local[$name] ?? $default));
};
$timezone = $read('APP_TIMEZONE', 'America/Lima');

$mask = static function (string $value): string {
    $len = strlen($value);
    return $len <= 4 ? str_repeat('•', $len) : substr($value, 0, 2) . str_repeat('•', $len - 4) . substr($value, -2) . " (largo={$len})";
};

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

// 1) ¿El celular guardado coincide con lo que SIGES tiene ahora mismo?
$sigesRows = $siges->query('SELECT id, contactos FROM public.cita WHERE fecha >= CURRENT_DATE')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$localRows = $app->query(
    "SELECT siges_cita_id, destination FROM appointments
     WHERE siges_cita_id IS NOT NULL AND appointment_date >= CURRENT_DATE AND status = 'RESERVADO'"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$staleCount = 0;
$staleSamples = [];
foreach ($localRows as $sourceId => $localDestination) {
    if (!array_key_exists($sourceId, $sigesRows)) {
        continue; // La cita ya no está vigente; se marcará NO_VIGENTE en la próxima pasada del worker.
    }
    $freshDestination = SigesAppointmentSyncService::extractDestination($sigesRows[$sourceId]);
    if ($freshDestination !== $localDestination) {
        $staleCount++;
        if (count($staleSamples) < 5) {
            $staleSamples[] = sprintf(
                'cita_siges=%d  local=%s  siges_ahora=%s',
                $sourceId,
                $localDestination === null ? '(vacío)' : $mask($localDestination),
                $freshDestination === null ? '(vacío)' : $mask($freshDestination),
            );
        }
    }
}

// 2) ¿El formato del celular guardado sirve para enviar? (mismo patrón que ChatbotApiClient::normalizeDestination)
$formatRow = $app->query(
    "SELECT
        COUNT(*) AS con_destino,
        COUNT(*) FILTER (WHERE destination ~ '^\\d{8,15}$') AS formato_valido,
        COUNT(*) FILTER (WHERE destination !~ '^\\d{8,15}$') AS formato_invalido
     FROM appointments
     WHERE siges_cita_id IS NOT NULL AND appointment_date >= CURRENT_DATE AND status = 'RESERVADO'
       AND destination IS NOT NULL"
)->fetch();
$invalidSamples = $app->query(
    "SELECT siges_cita_id, destination FROM appointments
     WHERE siges_cita_id IS NOT NULL AND appointment_date >= CURRENT_DATE AND status = 'RESERVADO'
       AND destination IS NOT NULL AND destination !~ '^\\d{8,15}$'
     LIMIT 5"
)->fetchAll();

// 3) ¿Hay números repetidos entre pacientes distintos el mismo día? (puede ser normal —familiares— o un error de dato)
$duplicates = $app->query(
    "SELECT appointment_date, destination, COUNT(DISTINCT patient_name) AS pacientes_distintos, COUNT(*) AS citas
     FROM appointments
     WHERE siges_cita_id IS NOT NULL AND appointment_date >= CURRENT_DATE AND status = 'RESERVADO' AND destination IS NOT NULL
     GROUP BY appointment_date, destination
     HAVING COUNT(DISTINCT patient_name) > 1
     ORDER BY appointment_date
     LIMIT 10"
)->fetchAll();

echo '=== 1) Coincidencia con SIGES en este momento ===', PHP_EOL;
printf("Citas locales con cita vigente en SIGES: %d\n", count($localRows));
printf("Desactualizadas (destino local distinto al de SIGES ahora): %d\n", $staleCount);
foreach ($staleSamples as $line) { echo '  - ', $line, PHP_EOL; }
if ($staleCount > 0) {
    echo '  -> Ejecuta el sincronizador o espera su próxima pasada (cada 3 s) para que se corrijan.', PHP_EOL;
}

echo PHP_EOL, '=== 2) Formato válido para enviar (ChatbotApiClient::normalizeDestination) ===', PHP_EOL;
foreach ($formatRow as $k => $v) { echo "$k=$v", PHP_EOL; }
foreach ($invalidSamples as $row) {
    echo '  - cita_siges=', $row['siges_cita_id'], '  destino=', $mask((string) $row['destination']), PHP_EOL;
}

echo PHP_EOL, '=== 3) Mismo celular en pacientes distintos el mismo día (revisar si corresponde) ===', PHP_EOL;
printf("Grupos encontrados: %d\n", count($duplicates));
foreach ($duplicates as $row) {
    echo '  - fecha=', $row['appointment_date'], '  celular=', $mask((string) $row['destination']),
        '  pacientes_distintos=', $row['pacientes_distintos'], '  citas=', $row['citas'], PHP_EOL;
}

$allOk = $staleCount === 0 && (int) $formatRow['formato_invalido'] === 0;
echo PHP_EOL, $allOk ? 'RESULTADO: todos los celulares guardados coinciden con SIGES y tienen formato válido.' : 'RESULTADO: hay anomalías; revisa arriba.', PHP_EOL;
