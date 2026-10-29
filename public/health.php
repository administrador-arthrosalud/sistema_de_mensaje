<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/access.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isLocalRequest()) {
    http_response_code(403);
    exit('El estado del sistema está limitado a la red local.');
}

authStartSession();
requireLogin();
requireAdmin();

require_once __DIR__ . '/../app/Services/ChatbotApiClient.php';
require_once __DIR__ . '/../app/Services/HealthCheckService.php';
require_once __DIR__ . '/../app/Repositories/ChatRepository.php';
require_once __DIR__ . '/../app/Repositories/SettingsRepository.php';
require_once __DIR__ . '/../app/Services/AutoReminderScheduler.php';

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$alertSettingsError = '';
$alertDestinationInput = null;

// Activar o pausar el envío automático (solo administradores; requireAdmin() ya se ejecutó arriba).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('La sesión expiró. Recarga la página e inténtalo de nuevo.');
    }
    if (($_POST['action'] ?? '') === 'save_error_alert_destination') {
        $alertDestinationInput = (string) ($_POST['error_alert_destination'] ?? '');
        $destination = preg_replace('/\D+/', '', $alertDestinationInput) ?? '';
        if ($destination !== '' && !preg_match('/^\d{8,15}$/', $destination)) {
            $alertSettingsError = 'Ingresa un número internacional válido (de 8 a 15 dígitos), o déjalo vacío para desactivar las alertas.';
        } else {
            try {
                (new SettingsRepository(databaseConnection()))->set(SettingsRepository::ERROR_ALERT_DESTINATION, $destination, currentUserId());
                header('Location: health.php', true, 303);
                exit;
            } catch (Throwable $error) {
                error_log('No se pudo guardar el destino de alertas técnicas: ' . get_class($error));
                $alertSettingsError = 'No se pudo guardar el número. Revisa la conexión con la base de datos e inténtalo de nuevo.';
            }
        }
    } else {
        $enable = ($_POST['auto_reminders'] ?? '') === 'on';
        try {
            (new SettingsRepository(databaseConnection()))->set(SettingsRepository::AUTO_ENABLED, $enable ? '1' : '0', currentUserId());
            error_log('Envío automático ' . ($enable ? 'activado' : 'pausado') . ' por el usuario ' . (int) currentUserId() . '.');
        } catch (Throwable $error) {
            error_log('No se pudo cambiar el envío automático: ' . get_class($error));
        }
        header('Location: health.php', true, 303);
        exit;
    }
}
$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
$autoEnabled = false;
$alertDestination = '';
try {
    $settings = new SettingsRepository(databaseConnection());
    $autoEnabled = $settings->autoRemindersEnabled();
    $alertDestination = $settings->get(SettingsRepository::ERROR_ALERT_DESTINATION);
} catch (Throwable) {
    // El error de base ya se muestra en la verificación.
}
if ($alertDestinationInput !== null) {
    $alertDestination = $alertDestinationInput;
}
$results = [];
$errorMessage = '';
try {
    $results = (new HealthCheckService(databaseConnection()))->run();
} catch (Throwable $error) {
    error_log('Falló la verificación del sistema: ' . get_class($error));
    $errorMessage = 'No se pudo completar la verificación: la base de datos local no responde.';
}

$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
$groups = [];
foreach ($results as $row) {
    $counts[$row['status']]++;
    $groups[$row['group']][] = $row;
}
$overall = $errorMessage !== '' || $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'ok');
$checkedAt = (new DateTimeImmutable('now'))->format('d/m/Y H:i:s');

try {
    $chatUnread = (new ChatRepository(databaseConnection()))->unreadTotal();
} catch (Throwable) {
    $chatUnread = 0;
}

header('Cache-Control: no-store');
require __DIR__ . '/../views/health.php';
