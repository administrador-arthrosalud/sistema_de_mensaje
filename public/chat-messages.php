<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/access.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin(true);
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $repository = new ChatRepository(databaseConnection());
    $destination = preg_replace('/\D+/', '', (string) ($_GET['destination'] ?? '')) ?? '';

    if ($destination !== '') {
        $afterId = max(0, (int) ($_GET['after_id'] ?? 0));
        echo json_encode([
            'version' => $repository->conversationsVersion(),
            'messages' => $repository->messagesAfter($destination, $afterId),
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    echo json_encode([
        'version' => $repository->conversationsVersion(),
        'unread_total' => $repository->unreadTotal(),
        'conversations' => $repository->findConversations(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('No se pudo consultar los chats: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['error' => 'No se pudo consultar la base de datos.']);
}
