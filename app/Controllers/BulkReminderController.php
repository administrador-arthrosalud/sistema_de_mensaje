<?php
declare(strict_types=1);

/**
 * Envío masivo de recordatorios para una fecha. El celular viene de SIGES
 * (appointments.destination, extraído de cita.contactos), pero sigue
 * requiriendo que una persona revise la lista y confirme una sola vez antes
 * de disparar los envíos (AGENTS.md: "El envío requiere una acción explícita
 * de una persona en el formulario y su confirmación"). No hay ningún
 * disparador automático/programado: esta clase solo actúa dentro de una
 * petición HTTP iniciada por alguien en el navegador.
 */
final class BulkReminderController
{
    // Pausa entre envíos para no saturar la línea de Gestionalo; no hay
    // documentación pública de sus límites de ráfaga.
    private const PACE_MICROSECONDS = 400_000;

    public function __construct(private AppointmentRepository $appointments)
    {
    }

    public function index(array $query): void
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $formatDate = static fn (string $date): string => (new DateTimeImmutable($date))->format('d/m/Y');
        $today = new DateTimeImmutable('today');
        $date = $this->validDate((string) ($query['fecha'] ?? '')) ?? $today->format('Y-m-d');
        $csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
        $chatbotConfigured = ChatbotApiClient::isConfigured();
        $notification = $_SESSION['notification'] ?? null;
        unset($_SESSION['notification']);

        $errorMessage = '';
        $candidates = [];
        $missingDestinationCount = 0;
        $noWhatsappCount = 0;
        try {
            $candidates = $this->appointments->findReadyForBulkReminder($date);
            $missingDestinationCount = $this->appointments->countNeedingManualDestination($date);
            $noWhatsappCount = $this->appointments->countNoWhatsapp($date);
        } catch (Throwable $error) {
            error_log('No se pudo cargar el envío masivo: ' . get_class($error));
            $errorMessage = 'No se pudo conectar con la base de datos. Revisa la configuración y que PostgreSQL esté activo.';
        }

        $previews = [];
        foreach ($candidates as $appointment) {
            $previews[] = $appointment + [
                'preview_message' => ReminderMessageBuilder::build(
                    (string) $appointment['patient_name'],
                    (string) $appointment['appointment_date'],
                    (string) $appointment['appointment_time'],
                    (string) ($appointment['doctor_name'] ?? ''),
                    $appointment['sede_nombre'] ?? null,
                ),
            ];
        }

        try {
            $chatUnread = (new ChatRepository(databaseConnection()))->unreadTotal();
        } catch (Throwable) {
            $chatUnread = 0;
        }

        require __DIR__ . '/../../views/bulk-reminders.php';
    }

    public function send(array $input): never
    {
        $this->verifyCsrf($input);
        if (!ChatbotApiClient::isConfigured()) {
            $this->redirectWith('error', 'Configura CHATBOT_LINE_KEY antes de enviar recordatorios.', '');
        }
        if (($input['confirm_transmission'] ?? '') !== 'yes') {
            $this->redirectWith('error', 'Confirma el envío antes de continuar.', (string) ($input['fecha'] ?? ''));
        }

        $date = $this->validDate((string) ($input['fecha'] ?? ''));
        if ($date === null) {
            $this->redirectWith('error', 'La fecha indicada no es válida.', '');
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($value): int => (int) $value, (array) ($input['appointment_id'] ?? [])),
            static fn (int $value): bool => $value > 0,
        )));
        if ($ids === []) {
            $this->redirectWith('error', 'Selecciona al menos un paciente de la lista.', $date);
        }

        // El lote puede durar minutos: se libera el bloqueo de la sesión para no congelar las demás pestañas
        // del mismo usuario. Se reabre al final para guardar el aviso de resultado.
        session_write_close();
        $chatbot = ChatbotApiClient::fromEnvironment();
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $first = true;
        $failures = [];
        foreach ($ids as $id) {
            if (!$first) {
                usleep(self::PACE_MICROSECONDS);
            }
            $first = false;

            // Se vuelve a validar cada cita (RESERVADO, no pasada, sin envío previo):
            // pudo cambiar en SIGES o haberse enviado ya entre que se listó y se confirmó.
            $appointment = $this->appointments->findReadyForBulkReminderById($id);
            if ($appointment === null) {
                $skipped++;
                continue;
            }

            $attemptId = null;
            try {
                $destination = $chatbot->normalizeDestination((string) $appointment['destination']);
                $message = ReminderMessageBuilder::build(
                    (string) $appointment['patient_name'],
                    (string) $appointment['appointment_date'],
                    (string) $appointment['appointment_time'],
                    (string) ($appointment['doctor_name'] ?? ''),
                    $appointment['sede_nombre'] ?? null,
                );
                $attemptId = $this->appointments->createReminderAttempt($appointment, $destination);
                $result = $chatbot->sendText($destination, $message);
                $this->appointments->finishReminderAttempt($attemptId, 'sent', (int) $result['http_status']);
                $sent++;
            } catch (DuplicateSendException) {
                $skipped++; // otro envío de la misma cita ya está en curso
            } catch (Throwable $error) {
                if ($attemptId !== null) {
                    try {
                        $httpStatus = $error instanceof ChatbotApiException ? $error->httpStatus : null;
                        $this->appointments->finishReminderAttempt($attemptId, 'failed', $httpStatus, $error instanceof ChatbotApiException ? $error->failureReason() : null);
                    } catch (Throwable) {
                        error_log('No se pudo actualizar un intento del envío masivo.');
                    }
                }
                error_log('Falló un envío del lote masivo: ' . get_class($error));
                $failures[] = [(string) $appointment['patient_name'], $error instanceof ChatbotApiException ? ($error->failureReason() === 'no_whatsapp' ? 'número sin WhatsApp' : 'HTTP ' . $error->httpStatus . ($error->detail !== '' ? ': ' . $error->detail : '')) : get_class($error)];
                $failed++;
            }
        }

        authStartSession();
        $message = sprintf('Envío masivo terminado: %d enviados, %d fallidos, %d ya no disponibles.', $sent, $failed, $skipped);
        if ($failures !== []) {
            $items = array_map(static fn (array $f): string => $f[0] . ' (' . $f[1] . ')', array_slice($failures, 0, 8));
            $message .= ' No se pudo enviar a: ' . implode('; ', $items) . (count($failures) > 8 ? '; y ' . (count($failures) - 8) . ' más' : '') . '.';
        }
        $this->redirectWith($failed > 0 ? 'error' : 'success', $message, $date);
    }

    private function validDate(string $value): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function verifyCsrf(array $input): void
    {
        $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
        $submittedToken = (string) ($input['csrf_token'] ?? '');
        if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            $this->redirectWith('error', 'La sesión expiró. Recarga el módulo e inténtalo de nuevo.', (string) ($input['fecha'] ?? ''));
        }
    }

    private function redirectWith(string $type, string $message, string $date): never
    {
        $_SESSION['notification'] = ['type' => $type, 'message' => $message];
        $target = 'bulk-reminders.php' . ($date !== '' ? '?fecha=' . rawurlencode($date) : '');
        header('Location: ' . $target, true, 303);
        exit;
    }
}
