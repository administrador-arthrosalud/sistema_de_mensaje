<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/access.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit('El acceso está limitado a la red local.');
}

authStartSession();
$next = safeNextUrl((string) ($_POST['next'] ?? $_GET['next'] ?? ''));

if (currentUser() !== null) {
    header('Location: ' . $next, true, 303);
    exit;
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'La sesión expiró. Inténtalo de nuevo.';
    } elseif ($username === '' || $password === '') {
        $error = 'Escribe tu usuario y contraseña.';
    } else {
        try {
            $error = attemptLogin(databaseConnection(), $username, $password) ?? '';
            if ($error === '') {
                header('Location: ' . $next, true, 303);
                exit;
            }
        } catch (Throwable $exception) {
            error_log('No se pudo iniciar sesión: ' . get_class($exception));
            $error = 'No se pudo conectar con la base de datos. Inténtalo en unos minutos.';
        }
    }
}

$csrfToken = (string) $_SESSION['csrf_token'];
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('Cache-Control: no-store');
require __DIR__ . '/../views/login.php';
