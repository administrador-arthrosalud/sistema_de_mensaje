<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/access.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit('El estado del sistema está limitado a la red local.');
}

authStartSession();
requireLogin();
requireAdmin();

require_once __DIR__ . '/../app/Services/ChatbotApiClient.php';
require_once __DIR__ . '/../app/Services/HealthCheckService.php';
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$results = [];
$errorMessage = '';
try {
    $results = (new HealthCheckService(databaseConnection()))->run();
} catch (Throwable $error) {
    error_log('Falló la verificación del sistema: ' . get_class($error));
    $errorMessage = 'No se pudo completar la verificación: la base de datos local no responde.';
}

$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
$groups = [];
foreach ($results as $row) {
    $counts[$row['status']]++;
    $groups[$row['group']][] = $row;
}
$overall = $errorMessage !== '' || $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'ok');
$checkedAt = (new DateTimeImmutable('now'))->format('d/m/Y H:i:s');

try {
    $chatUnread = (new ChatRepository(databaseConnection()))->unreadTotal();
} catch (Throwable) {
    $chatUnread = 0;
}

header('Cache-Control: no-store');
require __DIR__ . '/../views/health.php';
