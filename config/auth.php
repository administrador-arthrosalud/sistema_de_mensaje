<?php
declare(strict_types=1);

/**
 * Inicio de sesión de la aplicación. Cada página de public/ (salvo login.php y
 * chat-webhook.php, que se protege con su propio token) llama a requireLogin()
 * justo después del filtro isLocalRequest().
 */

const AUTH_MAX_FAILED_ATTEMPTS = 5;
const AUTH_LOCK_MINUTES = 10;
const AUTH_SESSION_LIFETIME = 28800;   // 8 h de inactividad
const AUTH_RECHECK_SECONDS = 60;       // cada cuánto se confirma que el usuario sigue activo

function authStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Nombre propio: las cookies no se aíslan por puerto y otras apps del mismo equipo usan PHPSESSID.
    session_name('SIGESSESSID');
    ini_set('session.gc_maxlifetime', (string) AUTH_SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function currentUser(): ?array
{
    authStartSession();
    $user = $_SESSION['user'] ?? null;

    return is_array($user) ? $user : null;
}

function currentUserId(): ?int
{
    $user = $_SESSION['user'] ?? null;

    return is_array($user) ? (int) $user['id'] : null;
}

/** Solo rutas internas de la propia aplicación (evita redirecciones abiertas). */
function safeNextUrl(string $next): string
{
    return preg_match('/^[a-z][a-z0-9\-]*\.php(\?[A-Za-z0-9_\-=&%.]*)?$/', $next) === 1 && !str_starts_with($next, 'login.php')
        ? $next
        : 'index.php';
}

/**
 * Exige un usuario con sesión iniciada. Para los endpoints JSON ($json = true)
 * responde 401 en vez de redirigir y libera el bloqueo de la sesión, porque se
 * consultan cada pocos segundos.
 */
function requireLogin(bool $json = false): void
{
    $user = currentUser();
    $now = time();

    if ($user !== null && ($now - (int) ($_SESSION['last_seen'] ?? 0)) > AUTH_SESSION_LIFETIME) {
        $user = null;
    }
    if ($user !== null && ($now - (int) ($_SESSION['checked_at'] ?? 0)) >= AUTH_RECHECK_SECONDS) {
        try {
            $statement = databaseConnection()->prepare('SELECT active FROM users WHERE id = :id');
            $statement->execute([':id' => $user['id']]);
            $active = $statement->fetchColumn();
            if ($active === false || !filter_var($active, FILTER_VALIDATE_BOOLEAN)) {
                $user = null;
            } else {
                $_SESSION['checked_at'] = $now;
            }
        } catch (Throwable) {
            // Sin base no se puede confirmar: se conserva la sesión y las páginas mostrarán su propio error.
        }
    }

    if ($user === null) {
        unset($_SESSION['user']);
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Sesión no iniciada.']);
            exit;
        }
        $target = 'index.php';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
            $target = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php')) . ($query !== '' ? '?' . $query : '');
        }
        header('Location: login.php?next=' . rawurlencode(safeNextUrl($target)), true, 303);
        exit;
    }

    $_SESSION['last_seen'] = $now;
    if ($json) {
        session_write_close();
    }
}

/**
 * Valida usuario y contraseña. Bloquea la cuenta tras varios intentos fallidos.
 * Devuelve el mensaje de error, o null si el inicio de sesión fue correcto.
 */
function attemptLogin(PDO $database, string $username, string $password): ?string
{
    $invalid = 'Usuario o contraseña incorrectos.';
    $statement = $database->prepare(
        'SELECT id, username, display_name, password_hash, role, active, failed_attempts,
                (locked_until IS NOT NULL AND locked_until > LOCALTIMESTAMP(0)) AS is_locked
         FROM users WHERE LOWER(username) = LOWER(:username)'
    );
    $statement->execute([':username' => $username]);
    $user = $statement->fetch();

    if ($user === false) {
        // Mismo costo que un usuario real, para no revelar qué usuarios existen.
        password_hash($password, PASSWORD_DEFAULT);
        return $invalid;
    }
    if (filter_var($user['is_locked'], FILTER_VALIDATE_BOOLEAN)) {
        return 'Cuenta bloqueada temporalmente por intentos fallidos. Inténtalo en ' . AUTH_LOCK_MINUTES . ' minutos.';
    }
    if (!filter_var($user['active'], FILTER_VALIDATE_BOOLEAN) || !password_verify($password, (string) $user['password_hash'])) {
        $failed = (int) $user['failed_attempts'] + 1;
        $lock = $failed >= AUTH_MAX_FAILED_ATTEMPTS;
        $update = $database->prepare(
            "UPDATE users SET failed_attempts = :failed,
                    locked_until = CASE WHEN :lock THEN LOCALTIMESTAMP(0) + (:minutes || ' minutes')::interval ELSE locked_until END
             WHERE id = :id"
        );
        $update->bindValue(':failed', $lock ? 0 : $failed, PDO::PARAM_INT);
        $update->bindValue(':lock', $lock, PDO::PARAM_BOOL);
        $update->bindValue(':minutes', (string) AUTH_LOCK_MINUTES);
        $update->bindValue(':id', (int) $user['id'], PDO::PARAM_INT);
        $update->execute();

        return $invalid;
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $database->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
    }
    $database->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = LOCALTIMESTAMP(0) WHERE id = :id')
        ->execute([':id' => $user['id']]);

    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'display_name' => (string) $user['display_name'],
        'role' => (string) $user['role'],
    ];
    $_SESSION['last_seen'] = $_SESSION['checked_at'] = time();

    return null;
}

function logoutUser(): void
{
    authStartSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/** Bloque de la barra lateral: usuario actual y botón para cerrar sesión. */
function authUserBox(): string
{
    $user = currentUser();
    if ($user === null) {
        return '';
    }
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<form class="sidebar-user" method="post" action="logout.php">'
        . '<input type="hidden" name="csrf_token" value="' . $escape((string) ($_SESSION['csrf_token'] ?? '')) . '">'
        . '<span class="sidebar-user-name" title="' . $escape($user['username']) . '">' . $escape($user['display_name']) . '</span>'
        . '<button type="submit" class="sidebar-user-logout">Salir</button>'
        . '</form>';
}

/** Solo administradores: el resto recibe 403. Llamar después de requireLogin(). */
function requireAdmin(): void
{
    $user = currentUser();
    if ($user === null || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Esta sección es solo para administradores.');
    }
}

/** Enlace de la barra lateral visible únicamente para administradores. */
function authAdminNav(string $active = ''): string
{
    $user = currentUser();
    if ($user === null || ($user['role'] ?? '') !== 'admin') {
        return '';
    }
    $isActive = $active === 'health';

    return '<div class="sidebar-section-label">ADMINISTRACIÓN</div>'
        . '<a class="nav-item' . ($isActive ? ' is-active' : '') . '" href="health.php"' . ($isActive ? ' aria-current="page"' : '') . '>'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l2.5-6 4 12 2.5-6H21"/></svg>'
        . '<span>Estado del sistema</span></a>';
}
