<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/access.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/AppointmentRepository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $repository = new AppointmentRepository(databaseConnection());
    echo json_encode(['version' => $repository->pendingVersion()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('No se pudo consultar la versión local de citas.');
    http_response_code(503);
    echo json_encode(['error' => 'No se pudo consultar la base de datos.']);
}
