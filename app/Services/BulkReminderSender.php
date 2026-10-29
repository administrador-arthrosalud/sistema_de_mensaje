<?php
declare(strict_types=1);

require_once __DIR__ . '/../Contracts/AppointmentRepositoryInterface.php';
require_once __DIR__ . '/../Contracts/AppointmentReminderRepositoryInterface.php';
require_once __DIR__ . '/../Contracts/ChatbotMessengerInterface.php';
require_once __DIR__ . '/../Exceptions/DuplicateSendException.php';

/**
 * Envía el recordatorio estándar a una lista de citas SIGES. Lo usan el envío masivo manual
 * (BulkReminderController, tras la confirmación de una persona) y el worker automático de la
 * víspera (scripts/auto-reminders.php). Cada cita se vuelve a validar justo antes de enviar y el
 * intento pasa por AppointmentReminderRepositoryInterface (bloqueo por cita).
 */
final class BulkReminderSender
{
    // Pausa entre envíos para no saturar la línea de Gestionalo; no hay
    // documentación pública de sus límites de ráfaga.
    public const PACE_MICROSECONDS = 400_000;

    /** @var callable(string, string): array{http_status:int} */
    private $transport;

    /**
     * $auto: envío del worker automático (marca auto_sent y aplica sus condiciones extra).
     * $transport: reemplaza el envío real a Gestionalo (solo para pruebas).
     */
    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private AppointmentReminderRepositoryInterface $reminders,
        private ChatbotMessengerInterface $chatbot,
        private bool $auto = false,
        ?callable $transport = null,
        private int $paceMicroseconds = self::PACE_MICROSECONDS,
    ) {
        $this->transport = $transport ?? fn (string $destination, string $message): array => $this->chatbot->sendText($destination, $message);
    }

    /**
     * @param list<int> $ids
     * @return array{sent:int,failed:int,skipped:int,failures:list<array{0:string,1:string}>}
     */
    public function send(array $ids): array
    {
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $first = true;
        $failures = [];
        foreach ($ids as $id) {
            if (!$first && $this->paceMicroseconds > 0) {
                usleep($this->paceMicroseconds);
            }
            $first = false;

            // Se vuelve a validar cada cita (RESERVADO, no pasada, sin envío previo):
            // pudo cambiar en SIGES o haberse enviado ya entre que se listó y se confirmó.
            $appointment = $this->appointments->findReadyForBulkReminderById((int) $id, $this->auto);
            if ($appointment === null) {
                $skipped++;
                continue;
            }

            $attemptId = null;
            try {
                $destination = $this->chatbot->normalizeDestination((string) $appointment['destination']);
                $message = ReminderMessageBuilder::build(
                    (string) $appointment['patient_name'],
                    (string) $appointment['appointment_date'],
                    (string) $appointment['appointment_time'],
                    (string) ($appointment['doctor_name'] ?? ''),
                    $appointment['sede_nombre'] ?? null,
                );
                $attemptId = $this->reminders->createReminderAttempt($appointment, $destination, $this->auto);
                $result = ($this->transport)($destination, $message);
                $this->reminders->finishReminderAttempt($attemptId, 'sent', (int) $result['http_status']);
                $sent++;
            } catch (DuplicateSendException) {
                $skipped++; // otro envío de la misma cita ya está en curso
            } catch (Throwable $error) {
                if ($attemptId !== null) {
                    try {
                        $httpStatus = $error instanceof ChatbotApiException ? $error->httpStatus : null;
                        $this->reminders->finishReminderAttempt($attemptId, 'failed', $httpStatus, $error instanceof ChatbotApiException ? $error->failureReason() : null);
                    } catch (Throwable) {
                        error_log('No se pudo actualizar un intento del envío masivo.');
                    }
                }
                error_log('Falló un envío del lote masivo: ' . get_class($error));
                $failures[] = [(string) $appointment['patient_name'], $error instanceof ChatbotApiException ? ($error->failureReason() === 'no_whatsapp' ? 'número sin WhatsApp' : 'HTTP ' . $error->httpStatus . ($error->detail !== '' ? ': ' . $error->detail : '')) : get_class($error)];
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'failures' => $failures];
    }
}
