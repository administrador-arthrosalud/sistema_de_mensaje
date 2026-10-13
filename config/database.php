<?php
declare(strict_types=1);

function databaseConnection(): PDO
{
    $localConfigPath = __DIR__ . '/local.php';
    $local = is_file($localConfigPath) ? require $localConfigPath : [];
    $host = getenv('DB_HOST') ?: ($local['DB_HOST'] ?? '127.0.0.1');
    $port = getenv('DB_PORT') ?: ($local['DB_PORT'] ?? '3306');
    $database = getenv('DB_NAME') ?: ($local['DB_NAME'] ?? 'siges_dashboard');
    $username = getenv('DB_USER') ?: ($local['DB_USER'] ?? '');
    $password = getenv('DB_PASSWORD') ?: ($local['DB_PASSWORD'] ?? '');

    if ($username === '') {
        throw new RuntimeException('Configura DB_USER y DB_PASSWORD antes de iniciar el sistema.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
