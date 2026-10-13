<?php
declare(strict_types=1);

final class ManualContactRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function create(array $contact): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO manual_contacts
                (patient_name, destination, appointment_date, appointment_time, doctor_name)
             VALUES (:patient_name, :destination, :appointment_date, :appointment_time, :doctor_name)'
        );
        $statement->execute([
            ':patient_name' => $contact['patient_name'],
            ':destination' => $contact['destination'],
            ':appointment_date' => $contact['appointment_date'],
            ':appointment_time' => $contact['appointment_time'],
            ':doctor_name' => $contact['doctor_name'],
        ]);

        return (int) $this->database->lastInsertId();
    }

    public function findAll(): array
    {
        return $this->database->query(
            "SELECT c.id, c.patient_name, c.destination, c.appointment_date,
                    c.appointment_time, c.doctor_name, c.created_at,
                    (SELECT rm.status FROM reminder_messages rm
                     WHERE rm.origin = 'manual' AND rm.manual_contact_id = c.id
                     ORDER BY rm.id DESC LIMIT 1) AS reminder_status,
                    (SELECT rm.sent_at FROM reminder_messages rm
                     WHERE rm.origin = 'manual' AND rm.manual_contact_id = c.id AND rm.status = 'sent'
                     ORDER BY rm.id DESC LIMIT 1) AS reminder_sent_at
             FROM manual_contacts c
             ORDER BY c.appointment_date ASC, c.appointment_time ASC, c.patient_name ASC"
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, patient_name, destination, appointment_date, appointment_time, doctor_name
             FROM manual_contacts WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $contact = $statement->fetch();

        return $contact === false ? null : $contact;
    }

    public function createReminderAttempt(array $contact, string $destination): int
    {
        $statement = $this->database->prepare(
            "INSERT INTO reminder_messages
                (appointment_id, origin, manual_contact_id, destination, patient_name,
                 appointment_date, appointment_time, doctor_name, status)
             VALUES (0, 'manual', :manual_contact_id, :destination, :patient_name,
                     :appointment_date, :appointment_time, :doctor_name, 'pending')"
        );
        $statement->execute([
            ':manual_contact_id' => $contact['id'],
            ':destination' => $destination,
            ':patient_name' => $contact['patient_name'],
            ':appointment_date' => $contact['appointment_date'],
            ':appointment_time' => $contact['appointment_time'],
            ':doctor_name' => $contact['doctor_name'],
        ]);

        return (int) $this->database->lastInsertId();
    }

    public function finishReminderAttempt(int $id, string $status, ?int $httpStatus = null): void
    {
        if (!in_array($status, ['sent', 'failed'], true)) {
            throw new InvalidArgumentException('El resultado del envío no es válido.');
        }
        $statement = $this->database->prepare(
            "UPDATE reminder_messages
             SET status = :status, http_status = :http_status,
                 sent_at = CASE WHEN :status_for_date = 'sent' THEN CURRENT_TIMESTAMP ELSE NULL END
             WHERE id = :id AND origin = 'manual' AND status = 'pending'"
        );
        $statement->execute([
            ':status' => $status,
            ':http_status' => $httpStatus,
            ':status_for_date' => $status,
            ':id' => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $statement = $this->database->prepare('DELETE FROM manual_contacts WHERE id = :id');
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }
}
