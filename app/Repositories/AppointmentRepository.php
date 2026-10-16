<?php
declare(strict_types=1);

final class AppointmentRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function countPending(array $filters): int
    {
        [$where, $parameters] = $this->buildWhere($filters);
        $statement = $this->database->prepare("SELECT COUNT(*) FROM appointments WHERE {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** Citas cuya fecha y hora aún no han pasado (hora de la sesión: APP_TIMEZONE). */
    private const NOT_PAST = '(appointment_date > CURRENT_DATE OR (appointment_date = CURRENT_DATE AND appointment_time >= LOCALTIME))';

    public function pendingVersion(): string
    {
        // El total cambia cuando una cita queda en el pasado, así el dashboard se refresca solo.
        $statement = $this->database->query(
            "SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_id,
                    COALESCE(to_char(MAX(updated_at), 'YYYY-MM-DD HH24:MI:SS'), '') AS last_update
             FROM appointments WHERE status = 'RESERVADO' AND siges_cita_id IS NOT NULL AND " . self::NOT_PAST
        );
        $version = $statement->fetch();

        return hash('sha256', implode('|', [
            (string) ($version['total'] ?? 0),
            (string) ($version['max_id'] ?? 0),
            (string) ($version['last_update'] ?? ''),
        ]));
    }

    public function summarizePending(array $filters): array
    {
        [$where, $parameters] = $this->buildWhere($filters);
        $sql = "SELECT
                    COUNT(*) AS total,
                    COUNT(DISTINCT NULLIF(TRIM(doctor_name), '')) AS doctors,
                    COUNT(DISTINCT NULLIF(NULLIF(TRIM(sede_nombre), ''), 'SEDE NO MAPEADA')) AS sites,
                    SUM(CASE
                        WHEN NULLIF(TRIM(appointment_note), '') IS NOT NULL
                         AND TRIM(appointment_note) <> 'Sin observación registrada'
                        THEN 1 ELSE 0 END) AS with_note,
                    SUM(CASE WHEN NULLIF(TRIM(visit_reason), '') IS NOT NULL THEN 1 ELSE 0 END) AS with_reason
                FROM appointments
                WHERE {$where}";
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        $summary = $statement->fetch();

        return [
            'total' => (int) ($summary['total'] ?? 0),
            'doctors' => (int) ($summary['doctors'] ?? 0),
            'sites' => (int) ($summary['sites'] ?? 0),
            'with_note' => (int) ($summary['with_note'] ?? 0),
            'with_reason' => (int) ($summary['with_reason'] ?? 0),
        ];
    }

    public function findPending(array $filters, int $limit, int $offset): array
    {
        [$where, $parameters] = $this->buildWhere($filters);
        $sql = "SELECT a.id, a.patient_name, a.appointment_date, a.appointment_time, a.doctor_name,
                       a.specialty, a.sede_nombre, a.consultorio, a.modulo, a.codigo_ipress,
                       a.appointment_note, a.visit_reason, a.destination,
                       (SELECT rm.status FROM reminder_messages rm WHERE rm.appointment_id = a.id ORDER BY rm.id DESC LIMIT 1) AS reminder_status,
                       (SELECT rm.sent_at FROM reminder_messages rm WHERE rm.appointment_id = a.id AND rm.status = 'sent' ORDER BY rm.id DESC LIMIT 1) AS reminder_sent_at
                FROM appointments a
                WHERE {$where}
                ORDER BY a.appointment_date ASC, a.appointment_time ASC, a.patient_name ASC
                LIMIT :limit OFFSET :offset";
        $statement = $this->database->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findPendingById(int $id): ?array
    {
        $statement = $this->database->prepare(
            "SELECT id, patient_name, appointment_date, appointment_time, doctor_name, destination
             FROM appointments
             WHERE id = :id AND status = 'RESERVADO' AND siges_cita_id IS NOT NULL
               AND " . self::NOT_PAST . "
             LIMIT 1"
        );
        $statement->execute([':id' => $id]);
        $appointment = $statement->fetch();

        return $appointment === false ? null : $appointment;
    }

    /**
     * Citas RESERVADO de una fecha exacta con celular extraído de SIGES y sin
     * un envío ya aceptado. Es la lista que revisa el personal antes del envío
     * masivo; cada fila sigue siendo editable/excluible antes de confirmar.
     */
    public function findReadyForBulkReminder(string $date): array
    {
        $statement = $this->database->prepare(
            "SELECT id, patient_name, appointment_date, appointment_time, doctor_name, sede_nombre, destination
             FROM appointments a
             WHERE status = 'RESERVADO' AND siges_cita_id IS NOT NULL AND destination IS NOT NULL
               AND appointment_date = :date
               AND " . self::NOT_PAST . "
               AND NOT EXISTS (
                   SELECT 1 FROM reminder_messages rm WHERE rm.appointment_id = a.id AND rm.status = 'sent'
               )
             ORDER BY appointment_time ASC, patient_name ASC"
        );
        $statement->execute([':date' => $date]);

        return $statement->fetchAll();
    }

    /** Para que el personal sepa cuántas citas de la fecha necesitan el formulario manual. */
    public function countNeedingManualDestination(string $date): int
    {
        $statement = $this->database->prepare(
            "SELECT COUNT(*) FROM appointments a
             WHERE status = 'RESERVADO' AND siges_cita_id IS NOT NULL AND destination IS NULL
               AND appointment_date = :date
               AND " . self::NOT_PAST . "
               AND NOT EXISTS (
                   SELECT 1 FROM reminder_messages rm WHERE rm.appointment_id = a.id AND rm.status = 'sent'
               )"
        );
        $statement->execute([':date' => $date]);

        return (int) $statement->fetchColumn();
    }

    /** Vuelve a validar una cita puntual justo antes de enviarle, por si cambió entre que se listó y se confirmó. */
    public function findReadyForBulkReminderById(int $id): ?array
    {
        $statement = $this->database->prepare(
            "SELECT id, patient_name, appointment_date, appointment_time, doctor_name, sede_nombre, destination
             FROM appointments a
             WHERE id = :id AND status = 'RESERVADO' AND siges_cita_id IS NOT NULL AND destination IS NOT NULL
               AND " . self::NOT_PAST . "
               AND NOT EXISTS (
                   SELECT 1 FROM reminder_messages rm WHERE rm.appointment_id = a.id AND rm.status = 'sent'
               )
             LIMIT 1"
        );
        $statement->execute([':id' => $id]);
        $appointment = $statement->fetch();

        return $appointment === false ? null : $appointment;
    }

    public function createReminderAttempt(array $appointment, string $destination): int
    {
        $statement = $this->database->prepare(
            "INSERT INTO reminder_messages
                (appointment_id, destination, patient_name, appointment_date, appointment_time, doctor_name, status)
             VALUES (:appointment_id, :destination, :patient_name, :appointment_date, :appointment_time, :doctor_name, 'pending')
             RETURNING id"
        );
        $statement->execute([
            ':appointment_id' => $appointment['id'],
            ':destination' => $destination,
            ':patient_name' => $appointment['patient_name'],
            ':appointment_date' => $appointment['appointment_date'],
            ':appointment_time' => $appointment['appointment_time'],
            ':doctor_name' => $appointment['doctor_name'],
        ]);

        return (int) $statement->fetchColumn();
    }

    public function finishReminderAttempt(int $id, string $status, ?int $httpStatus = null): void
    {
        if (!in_array($status, ['sent', 'failed'], true)) {
            throw new InvalidArgumentException('El resultado del envío no es válido.');
        }
        $statement = $this->database->prepare(
            "UPDATE reminder_messages
             SET status = :status, http_status = :http_status,
                 sent_at = CASE WHEN CAST(:status_for_date AS text) = 'sent' THEN LOCALTIMESTAMP(0) ELSE NULL END
             WHERE id = :id AND status = 'pending'"
        );
        $statement->execute([
            ':status' => $status,
            ':http_status' => $httpStatus,
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
            "SELECT id, origin, patient_name, destination, appointment_date, appointment_time, doctor_name, sent_at, http_status
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
        if ($filters['search'] !== '') {
            $clauses[] = '(patient_name ILIKE :search_patient OR destination ILIKE :search_destination OR doctor_name ILIKE :search_doctor)';
            $term = '%' . $filters['search'] . '%';
            $parameters[':search_patient'] = $term;
            $parameters[':search_destination'] = $term;
            $parameters[':search_doctor'] = $term;
        }
        if ($filters['date_from'] !== '') {
            $clauses[] = 'sent_at >= :date_from';
            $parameters[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $clauses[] = 'sent_at < CAST(:date_to AS date) + 1';
            $parameters[':date_to'] = $filters['date_to'];
        }

        return [implode(' AND ', $clauses), $parameters];
    }

    private function buildWhere(array $filters): array
    {
        $clauses = ["status = 'RESERVADO'", 'siges_cita_id IS NOT NULL', self::NOT_PAST];
        $parameters = [];

        if ($filters['search'] !== '') {
            // Cada palabra debe aparecer en alguna de las columnas (OR entre columnas, AND entre palabras).
            $columns = ['patient_name', 'doctor_name', 'specialty', 'sede_nombre', 'consultorio', 'modulo', 'codigo_ipress', 'appointment_note', 'visit_reason'];
            $words = array_slice(preg_split('/\s+/u', $filters['search'], -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);
            foreach ($words as $wordIndex => $word) {
                $alternatives = [];
                foreach ($columns as $columnIndex => $column) {
                    $placeholder = ':search_' . $wordIndex . '_' . $columnIndex;
                    $alternatives[] = "unaccent({$column}) ILIKE unaccent({$placeholder})";
                    $parameters[$placeholder] = '%' . addcslashes($word, '%_\\') . '%';
                }
                $clauses[] = '(' . implode(' OR ', $alternatives) . ')';
            }
        }
        if ($filters['sede'] !== '') {
            $clauses[] = 'sede_nombre = :sede';
            $parameters[':sede'] = $filters['sede'];
        }
        if ($filters['date_from'] !== '') {
            $clauses[] = 'appointment_date >= :date_from';
            $parameters[':date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $clauses[] = 'appointment_date <= :date_to';
            $parameters[':date_to'] = $filters['date_to'];
        }
        return [implode(' AND ', $clauses), $parameters];
    }
}
