<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
// El lote puede tardar varios segundos (una pausa entre cada envío); el valor
// por defecto de PHP cortaría a mitad de un envío masivo grande.
set_time_limit(300);
session_start();

require_once __DIR__ . '/../config/access.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit('El envío masivo está limitado al equipo local.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/AppointmentRepository.php';
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';
require_once __DIR__ . '/../app/Services/ChatbotApiClient.php';
require_once __DIR__ . '/../app/Services/ReminderMessageBuilder.php';
require_once __DIR__ . '/../app/Controllers/BulkReminderController.php';

try {
    $controller = new BulkReminderController(new AppointmentRepository(databaseConnection()));
    $action = (string) ($_GET['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        match ($action) {
            'send' => $controller->send($_POST),
            default => (static function (): never {
                http_response_code(404);
                exit('Acción no encontrada.');
            })(),
        };
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $action !== '') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit('Método no permitido.');
    }

    $controller->index($_GET);
} catch (Throwable $error) {
    error_log('No se pudo cargar el envío masivo: ' . get_class($error));
    http_response_code(503);
    echo 'No se pudo cargar el módulo. Revisa la conexión local con PostgreSQL.';
}
