<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
authStartSession();

require_once __DIR__ . '/../config/access.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Repositories/AppointmentRepository.php';
require_once __DIR__ . '/../app/Services/ChatbotApiClient.php';
require_once __DIR__ . '/../app/Services/MessageAttachment.php';

function finishWithNotification(string $type, string $message): never
{
    $_SESSION['notification'] = ['type' => $type, 'message' => $message];
    header('Location: index.php', true, 303);
    exit;
}

function markAttemptFailed(?AppointmentRepository $repository, ?int $attemptId, ?int $httpStatus = null, ?string $failureReason = null): void
{
    if ($repository === null || $attemptId === null) {
        return;
    }
    try {
        $repository->finishReminderAttempt($attemptId, 'failed', $httpStatus, $failureReason);
    } catch (Throwable $error) {
        error_log('No se pudo actualizar el registro del intento de recordatorio.');
    }
}

if (!isLocalRequest()) {
    http_response_code(403);
    exit('El envío está limitado al equipo local.');
}

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

if (MessageAttachment::requestExceededPostLimit()) {
    finishWithNotification('error', 'La imagen es demasiado grande; el máximo es 5 MB.');
}

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    finishWithNotification('error', 'La sesión expiró. Recarga el dashboard e inténtalo de nuevo.');
}
if (($_POST['confirm_transmission'] ?? '') !== 'yes') {
    finishWithNotification('error', 'Confirma el envío antes de continuar.');
}
if (!ChatbotApiClient::isConfigured()) {
    finishWithNotification('error', 'Configura CHATBOT_LINE_KEY con la serie asignada en config/local.php.');
}

$repository = null;
$attemptId = null;
$apiAccepted = false;

try {
    $appointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
    $destination = trim((string) ($_POST['destination'] ?? ''));
    if ($appointmentId === false || $appointmentId === null || $appointmentId < 1) {
        throw new InvalidArgumentException('La cita indicada no es válida.');
    }

    $repository = new AppointmentRepository(databaseConnection());
    $appointment = $repository->findPendingById($appointmentId);
    if ($appointment === null) {
        finishWithNotification('error', 'La cita ya no está pendiente o no existe. Actualiza el dashboard.');
    }

    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '') {
        throw new InvalidArgumentException('Escribe el mensaje antes de enviarlo.');
    }
    if (mb_strlen($message, 'UTF-8') > 1000) {
        throw new InvalidArgumentException('El mensaje no puede superar los 1000 caracteres.');
    }
    $attachment = MessageAttachment::fromUpload($_FILES['attachment'] ?? null);

    $chatbot = ChatbotApiClient::fromEnvironment();
    $destination = $chatbot->normalizeDestination($destination);
    $attemptId = $repository->createReminderAttempt($appointment, $destination);
    $result = $chatbot->sendMessage($destination, $message, $attachment);
    $apiAccepted = true;
    $repository->finishReminderAttempt($attemptId, 'sent', (int) $result['http_status']);

    finishWithNotification('success', 'Gestionalo aceptó el recordatorio; quedó registrado en Contactos enviados.');
} catch (DuplicateSendException) {
    finishWithNotification('error', 'Ya hay un envío en curso para esta cita. Espera unos segundos y revisa Contactos enviados.');
} catch (InvalidArgumentException $error) {
    markAttemptFailed($repository, $attemptId);
    finishWithNotification('error', $error->getMessage());
} catch (ChatbotApiException $error) {
    error_log('Gestionalo rechazó un recordatorio con HTTP ' . $error->httpStatus . '.');
    markAttemptFailed($repository, $attemptId, $error->httpStatus, $error->failureReason());
    if ($error->failureReason() === 'no_whatsapp') {
        finishWithNotification('error', 'Ese número no tiene WhatsApp (Gestionalo no lo encontró). Confirma el celular con el paciente; si lo corrigen en SIGES, se actualiza solo.');
    }
    if (in_array($error->httpStatus, [401, 403], true)) {
        finishWithNotification(
            'error',
            'Gestionalo rechazó la solicitud (HTTP ' . $error->httpStatus . '). Verifica que CHATBOT_LINE_KEY tenga la serie autorizada para esta línea.'
        );
    }
    finishWithNotification(
        'error',
        'Gestionalo respondió con HTTP ' . $error->httpStatus . '. Revisa la configuración de la API.'
    );
} catch (Throwable $error) {
    error_log('Falló el envío de un recordatorio: ' . get_class($error));
    if ($apiAccepted) {
        finishWithNotification('error', 'Gestionalo aceptó el recordatorio, pero no se pudo actualizar el historial. Revisa Contactos enviados antes de reenviarlo.');
    }
    markAttemptFailed($repository, $attemptId);
    finishWithNotification('error', 'No se pudo enviar. Revisa la conexión y la configuración de la API.');
}
