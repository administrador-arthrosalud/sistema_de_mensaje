<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
authStartSession();

require_once __DIR__ . '/../config/access.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit('Los recordatorios temporales están limitados al equipo local.');
}

requireLogin();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/ManualContactRepository.php';
require_once __DIR__ . '/../app/Services/ChatbotApiClient.php';
require_once __DIR__ . '/../app/Services/MessageAttachment.php';
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';
require_once __DIR__ . '/../app/Controllers/ManualReminderController.php';

try {
    $controller = new ManualReminderController(new ManualContactRepository(databaseConnection()));
    $action = (string) ($_GET['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        match ($action) {
            'create' => $controller->create($_POST),
            'send' => $controller->send($_POST, $_FILES),
            'delete' => $controller->delete($_POST),
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

    $controller->index();
} catch (Throwable $error) {
    error_log('No se pudo cargar el módulo de recordatorios temporales: ' . get_class($error));
    http_response_code(503);
    echo 'No se pudo cargar el módulo. Revisa la conexión local con PostgreSQL.';
}
