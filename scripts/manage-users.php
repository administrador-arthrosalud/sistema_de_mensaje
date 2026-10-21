<?php
declare(strict_types=1);

/**
 * Gestión de usuarios del inicio de sesión (solo CLI, dentro del contenedor web):
 *   docker exec -it siges-dashboard-web php scripts/manage-users.php list
 *   docker exec -it siges-dashboard-web php scripts/manage-users.php create <usuario> "<Nombre completo>" [admin|operador]
 *   docker exec -it siges-dashboard-web php scripts/manage-users.php passwd <usuario>
 *   docker exec -it siges-dashboard-web php scripts/manage-users.php disable <usuario>
 *   docker exec -it siges-dashboard-web php scripts/manage-users.php enable <usuario>
 * La contraseña se pide por teclado (sin mostrarla) o, sin terminal, se lee de la
 * variable NEW_PASSWORD. Nunca se pasa como argumento ni se imprime.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

function readPassword(): string
{
    $fromEnv = getenv('NEW_PASSWORD');
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    fwrite(STDERR, 'Contraseña: ');
    if (!$isWindows) {
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (!$isWindows) {
        shell_exec('stty echo');
    }
    fwrite(STDERR, PHP_EOL);

    return $password;
}

function validatePassword(string $password): void
{
    if (mb_strlen($password, 'UTF-8') < 10) {
        fwrite(STDERR, "La contraseña debe tener al menos 10 caracteres.\n");
        exit(1);
    }
}

$command = $argv[1] ?? '';
$username = $argv[2] ?? '';
$database = databaseConnection();

switch ($command) {
    case 'list':
        $rows = $database->query(
            "SELECT username, display_name, role, active, last_login_at FROM users ORDER BY username"
        )->fetchAll();
        foreach ($rows as $row) {
            printf(
                "%-20s %-30s %-9s %-10s último acceso: %s\n",
                $row['username'],
                $row['display_name'],
                $row['role'],
                filter_var($row['active'], FILTER_VALIDATE_BOOLEAN) ? 'activo' : 'desactivado',
                $row['last_login_at'] ?? 'nunca'
            );
        }
        if ($rows === []) {
            echo "No hay usuarios. Crea el primero con: create <usuario> \"<Nombre>\" admin\n";
        }
        break;

    case 'create':
        $displayName = trim($argv[3] ?? '');
        $role = $argv[4] ?? 'operador';
        if (!preg_match('/^[a-z0-9._-]{3,40}$/i', $username) || $displayName === '' || !in_array($role, ['admin', 'operador'], true)) {
            fwrite(STDERR, "Uso: create <usuario (3-40: letras, números . _ -)> \"<Nombre completo>\" [admin|operador]\n");
            exit(1);
        }
        $password = readPassword();
        validatePassword($password);
        try {
            $database->prepare(
                'INSERT INTO users (username, display_name, password_hash, role) VALUES (:u, :d, :h, :r)'
            )->execute([':u' => $username, ':d' => $displayName, ':h' => password_hash($password, PASSWORD_DEFAULT), ':r' => $role]);
        } catch (PDOException $error) {
            fwrite(STDERR, $error->getCode() === '23505' ? "Ya existe ese usuario.\n" : "No se pudo crear el usuario.\n");
            exit(1);
        }
        echo "Usuario creado: $username ($role)\n";
        break;

    case 'passwd':
        $password = readPassword();
        validatePassword($password);
        $update = $database->prepare(
            'UPDATE users SET password_hash = :h, failed_attempts = 0, locked_until = NULL WHERE LOWER(username) = LOWER(:u)'
        );
        $update->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':u' => $username]);
        echo $update->rowCount() > 0 ? "Contraseña actualizada.\n" : "No existe ese usuario.\n";
        break;

    case 'disable':
    case 'enable':
        $update = $database->prepare(
            'UPDATE users SET active = :a, failed_attempts = 0, locked_until = NULL WHERE LOWER(username) = LOWER(:u)'
        );
        $update->bindValue(':a', $command === 'enable', PDO::PARAM_BOOL);
        $update->bindValue(':u', $username);
        $update->execute();
        echo $update->rowCount() > 0 ? ($command === 'enable' ? "Usuario activado.\n" : "Usuario desactivado (su sesión se cierra en menos de 1 minuto).\n") : "No existe ese usuario.\n";
        break;

    default:
        fwrite(STDERR, "Comandos: list | create | passwd | disable | enable (ver el encabezado del archivo).\n");
        exit(1);
}
