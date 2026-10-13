<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('El historial está limitado al equipo local.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/AppointmentRepository.php';
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formatDate = static fn (string $date): string => (new DateTimeImmutable($date))->format('d/m/Y');
$formatDateTime = static fn (string $dateTime): string => (new DateTimeImmutable($dateTime))->format('d/m/Y H:i');
$filters = [
    'search' => trim((string) ($_GET['q'] ?? '')),
    'date_from' => trim((string) ($_GET['desde'] ?? '')),
    'date_to' => trim((string) ($_GET['hasta'] ?? '')),
];
foreach (['date_from', 'date_to'] as $dateField) {
    if ($filters[$dateField] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateField])) {
        $filters[$dateField] = '';
    }
}

$pageSize = 25;
$page = max(1, filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$messages = [];
$total = 0;
$pages = 1;
$errorMessage = '';
try {
    $repository = new AppointmentRepository(databaseConnection());
    $total = $repository->countSentReminders($filters);
    $pages = max(1, (int) ceil($total / $pageSize));
    $page = min($page, $pages);
    $messages = $repository->findSentReminders($filters, $pageSize, ($page - 1) * $pageSize);
} catch (Throwable $error) {
    error_log((string) $error);
    $errorMessage = 'No se pudo cargar el historial de envíos. Revisa la base de datos.';
}

$pageQuery = array_filter([
    'q' => $filters['search'],
    'desde' => $filters['date_from'],
    'hasta' => $filters['date_to'],
], static fn (string $value): bool => $value !== '');

try {
    $chatUnread = (new ChatRepository(databaseConnection()))->unreadTotal();
} catch (Throwable) {
    $chatUnread = 0;
}

require __DIR__ . '/../views/sent-messages.php';
