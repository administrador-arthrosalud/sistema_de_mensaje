<?php
declare(strict_types=1);

require_once __DIR__ . '/../Contracts/AppointmentReminderRepositoryInterface.php';
require_once __DIR__ . '/../Exceptions/DuplicateSendException.php';

/** Persistencia de intentos y consultas del historial de recordatorios de citas SIGES. */
final class AppointmentReminderRepository implements AppointmentReminderRepositoryInterface
{
    public function __construct(private PDO $database)
    {
    }

    /** $auto: el worker de víspera no tiene usuario (sent_by_user_id queda NULL). */
    public function createReminderAttempt(array $appointment, string $destination, bool $auto = false): int
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }

        try {
            $lock = $this->database->prepare('SELECT pg_advisory_xact_lock(:id)');
            $lock->execute([':id' => $appointment['id']]);

            $inFlight = $this->database->prepare(
                "SELECT 1 FROM reminder_messages
                 WHERE appointment_id = :id AND origin = 'siges' AND status = 'pending'
                   AND attempted_at > LOCALTIMESTAMP(0) - INTERVAL '2 minutes' LIMIT 1"
            );
            $inFlight->execute([':id' => $appointment['id']]);
            if ($inFlight->fetchColumn() !== false) {
                throw new DuplicateSendException('Ya hay un envío en curso para esta cita.');
            }

            $statement = $this->database->prepare(
                "INSERT INTO reminder_messages
                    (appointment_id, destination, patient_name, appointment_date, appointment_time, doctor_name, status, sent_by_user_id, auto_sent)
                 VALUES (:appointment_id, :destination, :patient_name, :appointment_date, :appointment_time, :doctor_name, 'pending', :sent_by, :auto_sent)
                 RETURNING id"
            );
            $statement->execute([
                ':appointment_id' => $appointment['id'],
                ':destination' => $destination,
                ':patient_name' => $appointment['patient_name'],
                ':appointment_date' => $appointment['appointment_date'],
                ':appointment_time' => $appointment['appointment_time'],
                ':doctor_name' => $appointment['doctor_name'],
                ':sent_by' => !$auto && function_exists('currentUserId') ? currentUserId() : null,
                ':auto_sent' => $auto ? 'true' : 'false',
            ]);

            $id = (int) $statement->fetchColumn();
            if ($ownsTransaction) {
                $this->database->commit();
            }

            return $id;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function finishReminderAttempt(
        int $id,
        string $status,
        ?int $httpStatus = null,
        ?string $failureReason = null,
    ): void {
        if (!in_array($status, ['sent', 'failed'], true)) {
            throw new InvalidArgumentException('El resultado del envío no es válido.');
        }

        $statement = $this->database->prepare(
            "UPDATE reminder_messages
             SET status = :status, http_status = :http_status, failure_reason = :failure_reason,
                 sent_at = CASE WHEN CAST(:status_for_date AS text) = 'sent' THEN LOCALTIMESTAMP(0) ELSE NULL END
             WHERE id = :id AND status = 'pending'"
        );
        $statement->execute([
            ':status' => $status,
            ':http_status' => $httpStatus,
            ':failure_reason' => $status === 'failed' ? $failureReason : null,
            ':status_for_date' => $status,
            ':id' => $id,
        ]);
    }

    public function countSentReminders(array $filters): int
    {
        [$where, $parameters] = $this->buildSentWhere($filters);
        $statement = $this->database->prepare("SELECT COUNT(*) FROM reminder_messages WHERE {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function findSentReminders(array $filters, int $limit, int $offset): array
    {
        [$where, $parameters] = $this->buildSentWhere($filters);
        $statement = $this->database->prepare(
            "SELECT id, origin, auto_sent, patient_name, destination, appointment_date, appointment_time, doctor_name, sent_at, http_status
             FROM reminder_messages
             WHERE {$where}
             ORDER BY sent_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function buildSentWhere(array $filters): array
    {
        $clauses = ["status = 'sent'"];
        $parameters = [];
        if (($filters['search'] ?? '') !== '') {
            $clauses[] = '(patient_name ILIKE :search_patient OR destination ILIKE :search_destination OR doctor_name ILIKE :search_doctor)';
            $term = '%' . $filters['search'] . '%';
            $parameters[':search_patient'] = $term;
            $parameters[':search_destination'] = $term;
            $parameters[':search_doctor'] = $term;
        }
        if (($filters['date_from'] ?? '') !== '') {
            $clauses[] = 'sent_at >= :date_from';
            $parameters[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (($filters['date_to'] ?? '') !== '') {
            $clauses[] = 'sent_at < CAST(:date_to AS date) + 1';
            $parameters[':date_to'] = $filters['date_to'];
        }

        return [implode(' AND ', $clauses), $parameters];
    }
}
