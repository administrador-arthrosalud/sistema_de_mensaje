<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/access.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit('La agenda está limitada al equipo local.');
}

session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/AppointmentRepository.php';
require_once __DIR__ . '/../app/Services/DashboardService.php';
require_once __DIR__ . '/../app/Services/ChatbotApiClient.php';
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';
require_once __DIR__ . '/../app/Controllers/DashboardController.php';

$service = null;
$startupError = '';
try {
    $service = new DashboardService(new AppointmentRepository(databaseConnection()));
} catch (Throwable $error) {
    error_log((string) $error);
    $startupError = 'No se pudo conectar con la base de datos. Revisa la configuración y que PostgreSQL esté activo.';
}

$controller = new DashboardController($service, $startupError);
$controller->index($_GET);
