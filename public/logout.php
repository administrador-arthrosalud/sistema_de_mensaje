<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/access.php';
require_once __DIR__ . '/../config/auth.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit;
}

authStartSession();
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    logoutUser();
}
header('Location: login.php', true, 303);
exit;
