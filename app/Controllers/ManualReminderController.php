<?php
declare(strict_types=1);

require_once __DIR__ . '/../Contracts/ChatbotMessengerInterface.php';

final class ManualReminderController
{
    public function __construct(private ManualContactRepository $contacts, private bool $chatbotConfigured)
    {
    }

    public function index(): void
    {
        $contacts = $this->contacts->findAll();
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $formatDate = static fn (string $date): string => (new DateTimeImmutable($date))->format('d/m/Y');
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
        $chatbotConfigured = $this->chatbotConfigured;
        $notification = $_SESSION['notification'] ?? null;
        unset($_SESSION['notification']);
        try {
            $chatUnread = (new ChatRepository(databaseConnection()))->unreadTotal();
        } catch (Throwable) {
            $chatUnread = 0;
        }

        require __DIR__ . '/../../views/manual-reminders.php';
    }

    public function create(array $input): never
    {
        $this->verifyCsrf($input);

        $name = trim((string) ($input['patient_name'] ?? ''));
        $destination = preg_replace('/\D+/', '', (string) ($input['destination'] ?? '')) ?? '';
        $dateValue = trim((string) ($input['appointment_date'] ?? ''));
        $timeValue = trim((string) ($input['appointment_time'] ?? ''));
        $doctor = trim((string) ($input['doctor_name'] ?? ''));
        $sede = trim((string) ($input['sede'] ?? ''));

        if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
            $this->redirectWith('error', 'Ingresa un nombre válido (máximo 255 caracteres).');
        }
        if (!preg_match('/^\d{8,15}$/', $destination)) {
            $this->redirectWith('error', 'Ingresa un celular válido, con código de país si corresponde.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        if ($date === false || $date->format('Y-m-d') !== $dateValue || $date < new DateTimeImmutable('today')) {
            $this->redirectWith('error', 'La fecha de la cita debe ser hoy o una fecha futura válida.');
        }
        $time = DateTimeImmutable::createFromFormat('!H:i', $timeValue);
        if ($time === false || $time->format('H:i') !== $timeValue) {
            $this->redirectWith('error', 'Ingresa una hora válida.');
        }
        if (mb_strlen($doctor, 'UTF-8') > 255) {
            $this->redirectWith('error', 'El nombre del profesional no puede superar 255 caracteres.');
        }
        if ($sede !== '' && !in_array($sede, ['LIMA', 'LINCE', 'LINCE - CONSULTORIO 3'], true)) {
            $this->redirectWith('error', 'La sede indicada no es válida.');
        }

        try {
            $this->contacts->create([
                'patient_name' => $name,
                'destination' => $destination,
                'appointment_date' => $dateValue,
                'appointment_time' => $time->format('H:i:s'),
                'doctor_name' => $doctor,
                'sede' => $sede,
            ]);
        } catch (Throwable $error) {
            error_log('No se pudo guardar un contacto temporal.');
            $this->redirectWith('error', 'No se pudo guardar el contacto. Revisa la base de datos.');
        }

        $this->redirectWith('success', 'Contacto temporal agregado. Revisa sus datos antes de enviar el recordatorio.');
    }

    public function send(array $input, array $files = [], ?ChatbotMessengerInterface $chatbot = null): never
    {
        if (MessageAttachment::requestExceededPostLimit()) {
            $this->redirectWith('error', 'La imagen es demasiado grande; el máximo es 5 MB.');
        }
        $this->verifyCsrf($input);
        if (!$this->chatbotConfigured) {
            $this->redirectWith('error', 'Configura CHATBOT_LINE_KEY antes de enviar recordatorios.');
        }
        if ($chatbot === null) {
            $this->redirectWith('error', 'No se pudo preparar el cliente de WhatsApp.');
        }

        $id = filter_var($input['contact_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            $this->redirectWith('error', 'El contacto indicado no es válido.');
        }
        $contact = $this->contacts->findById($id);
        if ($contact === null) {
            $this->redirectWith('error', 'El contacto temporal ya no existe. Actualiza la lista.');
        }

        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '' || mb_strlen($message, 'UTF-8') > 1000) {
            $this->redirectWith('error', 'Escribe un mensaje de hasta 1000 caracteres.');
        }

        $attemptId = null;
        $apiAccepted = false;
        try {
            $attachment = MessageAttachment::fromUpload($files['attachment'] ?? null);
            $destination = $chatbot->normalizeDestination((string) $contact['destination']);
            $attemptId = $this->contacts->createReminderAttempt($contact, $destination);
            $result = $chatbot->sendMessage($destination, $message, $attachment);
            $apiAccepted = true;
            $this->contacts->finishReminderAttempt($attemptId, 'sent', (int) $result['http_status']);
            $this->redirectWith('success', 'Gestionalo aceptó el recordatorio. Quedó registrado en Contactos enviados.');
        } catch (InvalidArgumentException $error) {
            $this->markFailed($attemptId);
            $this->redirectWith('error', $error->getMessage());
        } catch (ChatbotApiException $error) {
            $this->markFailed($attemptId, $error->httpStatus, $error->failureReason());
            if ($error->failureReason() === 'no_whatsapp') {
                $this->redirectWith('error', 'Ese número no tiene WhatsApp (Gestionalo no lo encontró). Confirma el celular con la persona.');
            }
            if (in_array($error->httpStatus, [401, 403], true)) {
                $this->redirectWith('error', 'Gestionalo rechazó la solicitud. Verifica la clave autorizada de la línea.');
            }
            $this->redirectWith('error', 'Gestionalo respondió con HTTP ' . $error->httpStatus . '.');
        } catch (Throwable $error) {
            error_log('Falló el envío de un recordatorio temporal: ' . get_class($error));
            if ($apiAccepted) {
                $this->redirectWith('error', 'Gestionalo aceptó el mensaje, pero no se pudo guardar el resultado. Revisa Contactos enviados antes de reenviar.');
            }
            $this->markFailed($attemptId);
            $this->redirectWith('error', 'No se pudo enviar. Revisa la conexión y la configuración de la API.');
        }
    }

    public function delete(array $input): never
    {
        $this->verifyCsrf($input);
        $id = filter_var($input['contact_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            $this->redirectWith('error', 'El contacto indicado no es válido.');
        }

        if (!$this->contacts->delete($id)) {
            $this->redirectWith('error', 'El contacto ya no existe.');
        }
        $this->redirectWith('success', 'Contacto quitado de la lista temporal. El historial de mensajes se conserva.');
    }

    private function verifyCsrf(array $input): void
    {
        $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
        $submittedToken = (string) ($input['csrf_token'] ?? '');
        if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            $this->redirectWith('error', 'La sesión expiró. Recarga el módulo e inténtalo de nuevo.');
        }
    }

    private function markFailed(?int $attemptId, ?int $httpStatus = null, ?string $failureReason = null): void
    {
        if ($attemptId === null) {
            return;
        }
        try {
            $this->contacts->finishReminderAttempt($attemptId, 'failed', $httpStatus, $failureReason);
        } catch (Throwable) {
            error_log('No se pudo actualizar el intento de recordatorio temporal.');
        }
    }

    private function redirectWith(string $type, string $message): never
    {
        $_SESSION['notification'] = ['type' => $type, 'message' => $message];
        header('Location: manual-reminders.php', true, 303);
        exit;
    }
}
