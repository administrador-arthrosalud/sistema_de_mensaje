<?php
declare(strict_types=1);

function databaseConnection(): PDO
{
    $localConfigPath = __DIR__ . '/local.php';
    $local = is_file($localConfigPath) ? require $localConfigPath : [];
    $host = getenv('DB_HOST') ?: ($local['DB_HOST'] ?? '127.0.0.1');
    $port = getenv('DB_PORT') ?: ($local['DB_PORT'] ?? '5432');
    $database = getenv('DB_NAME') ?: ($local['DB_NAME'] ?? 'siges_dashboard');
    $username = getenv('DB_USER') ?: ($local['DB_USER'] ?? '');
    $password = getenv('DB_PASSWORD') ?: ($local['DB_PASSWORD'] ?? '');
    $sslMode = getenv('DB_SSLMODE') ?: ($local['DB_SSLMODE'] ?? 'prefer');
    $timezone = getenv('APP_TIMEZONE') ?: ($local['APP_TIMEZONE'] ?? 'America/Lima');

    if ($username === '') {
        throw new RuntimeException('Configura DB_USER y DB_PASSWORD antes de iniciar el sistema.');
    }

    return postgresConnection($host, $port, $database, $username, $password, $sslMode, $timezone);
}

/**
 * Conexión PDO a PostgreSQL. Fija la zona horaria de la sesión para que
 * CURRENT_DATE y las marcas de tiempo usen la hora local de la clínica.
 * set_config() solo afecta a la sesión: no escribe datos.
 */
function postgresConnection(
    string $host,
    string $port,
    string $database,
    string $username,
    string $password,
    string $sslMode,
    string $timezone,
): PDO {
    if (!extension_loaded('pdo_pgsql')) {
        throw new RuntimeException('Activa la extensión pdo_pgsql de PHP.');
    }

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $host, $port, $database, $sslMode),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->prepare("SELECT set_config('TimeZone', :timezone, false)")->execute([':timezone' => $timezone]);

    return $pdo;
}
