<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/Services/SigesAppointmentSyncService.php';

$localPath = __DIR__ . '/../config/local.php';
$local = is_file($localPath) ? require $localPath : [];
$read = static function (string $name, mixed $default = '') use ($local): string {
    $value = getenv($name);
    return (string) (($value !== false && $value !== '') ? $value : ($local[$name] ?? $default));
};

$lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siges-dashboard-sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Ya hay un sincronizador SIGES en ejecución.\n");
    exit(1);
}

$pgHost = $read('SIGES_PG_HOST');
$pgDatabase = $read('SIGES_PG_DATABASE', 'isis3_arths');
$pgUser = $read('SIGES_PG_USER');
$pgPassword = $read('SIGES_PG_PASSWORD');
$pgPort = $read('SIGES_PG_PORT', '5432');
$pgSslMode = $read('SIGES_PG_SSLMODE', 'prefer');
$mysqlHost = $read('SIGES_SYNC_DB_HOST', $read('DB_HOST', '127.0.0.1'));
$mysqlPort = $read('SIGES_SYNC_DB_PORT', $read('DB_PORT', '3306'));
$mysqlDatabase = $read('SIGES_SYNC_DB_NAME', $read('DB_NAME', 'siges_dashboard'));
$mysqlUser = $read('SIGES_SYNC_DB_USER');
$mysqlPassword = $read('SIGES_SYNC_DB_PASSWORD');

if ($pgHost === '' || $pgUser === '' || $pgPassword === '' || $mysqlUser === '' || $mysqlPassword === '') {
    fwrite(STDERR, "Configura SIGES_PG_HOST, SIGES_PG_USER, SIGES_PG_PASSWORD y las credenciales SIGES_SYNC_DB_* en config/local.php.\n");
    exit(1);
}

if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "Activa la extensión pdo_pgsql de PHP para conectar con SIGES.\n");
    exit(1);
}

$createService = static function () use ($pgHost, $pgPort, $pgDatabase, $pgUser, $pgPassword, $pgSslMode, $mysqlHost, $mysqlPort, $mysqlDatabase, $mysqlUser, $mysqlPassword): SigesAppointmentSyncService {
    $siges = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $pgHost, $pgPort, $pgDatabase, $pgSslMode),
        $pgUser,
        $pgPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $local = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $mysqlHost, $mysqlPort, $mysqlDatabase),
        $mysqlUser,
        $mysqlPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );

    return new SigesAppointmentSyncService($siges, $local);
};

$once = in_array('--once', $argv, true);
$service = null;
$lastErrorAt = 0;
do {
    try {
        $service ??= $createService();
        $result = $service->runOnce();
        if ($result['initialized']) {
            printf("Sincronizador inicializado; continuará desde cita SIGES %d.\n", $result['last_id']);
        } elseif ($result['imported'] > 0) {
            printf("Citas nuevas o modificadas sincronizadas: %d; último ID SIGES: %d.\n", $result['imported'], $result['last_id']);
        }
        $lastErrorAt = 0;
    } catch (Throwable $error) {
        $service = null;
        if ($lastErrorAt === 0 || time() - $lastErrorAt >= 60) {
            fwrite(STDERR, 'Error de sincronización (' . get_class($error) . '): ' . $error->getMessage() . PHP_EOL);
            $lastErrorAt = time();
        }
    }

    if (!$once) {
        sleep(3);
    }
} while (!$once);
