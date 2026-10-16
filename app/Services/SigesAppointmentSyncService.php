<?php
declare(strict_types=1);

final class SigesAppointmentSyncService
{
    private PDOStatement $upsertAppointment;
    private PDOStatement $matchExistingAppointment;
    private PDOStatement $updateExistingAppointment;
    private PDOStatement $markAppointmentNotCurrent;

    public function __construct(private PDO $siges, private PDO $local)
    {
        $this->upsertAppointment = $this->local->prepare(
            "INSERT INTO appointments
                (source_key, siges_cita_id, patient_name, appointment_date, appointment_time, doctor_name,
                 specialty, sede_nombre, consultorio, modulo, codigo_ipress, appointment_note, visit_reason,
                 destination, status)
             VALUES
                (:source_key, :siges_cita_id, :patient_name, :appointment_date, :appointment_time, :doctor_name,
                 :specialty, :sede_nombre, :consultorio, :modulo, :codigo_ipress, :appointment_note, :visit_reason,
                 :destination, :status)
             ON CONFLICT (source_key) DO UPDATE SET
                siges_cita_id = EXCLUDED.siges_cita_id,
                patient_name = EXCLUDED.patient_name,
                appointment_date = EXCLUDED.appointment_date,
                appointment_time = EXCLUDED.appointment_time,
                doctor_name = EXCLUDED.doctor_name,
                specialty = EXCLUDED.specialty,
                sede_nombre = EXCLUDED.sede_nombre,
                consultorio = EXCLUDED.consultorio,
                modulo = EXCLUDED.modulo,
                codigo_ipress = EXCLUDED.codigo_ipress,
                appointment_note = EXCLUDED.appointment_note,
                visit_reason = EXCLUDED.visit_reason,
                destination = EXCLUDED.destination,
                status = EXCLUDED.status"
        );
        $this->matchExistingAppointment = $this->local->prepare(
            "SELECT id FROM appointments
             WHERE siges_cita_id IS NULL
               AND patient_name = :patient_name
               AND appointment_date = :appointment_date
               AND appointment_time = :appointment_time
               AND doctor_name = :doctor_name
               AND COALESCE(sede_nombre, '') = COALESCE(:sede_nombre, '')
               AND COALESCE(consultorio, '') = COALESCE(:consultorio, '')
             ORDER BY id ASC LIMIT 1"
        );
        $this->updateExistingAppointment = $this->local->prepare(
            "UPDATE appointments SET source_key = :source_key, siges_cita_id = :siges_cita_id,
                patient_name = :patient_name, appointment_date = :appointment_date,
                appointment_time = :appointment_time, doctor_name = :doctor_name, specialty = :specialty,
                sede_nombre = :sede_nombre, consultorio = :consultorio, modulo = :modulo,
                codigo_ipress = :codigo_ipress, appointment_note = :appointment_note,
                visit_reason = :visit_reason, destination = :destination, status = :status
             WHERE id = :local_id"
        );
        $this->markAppointmentNotCurrent = $this->local->prepare(
            "UPDATE appointments SET status = 'NO_VIGENTE'
             WHERE siges_cita_id = :siges_cita_id
               AND appointment_date >= CURRENT_DATE
               AND status <> 'NO_VIGENTE'"
        );
    }

    /** On first run, records the current maximum id as a baseline to avoid duplicating the imported snapshot. */
    public function runOnce(): array
    {
        $this->local->beginTransaction();
        try {
            $state = $this->local->query(
                "SELECT last_cita_id FROM siges_sync_state WHERE sync_key = 'appointments' FOR UPDATE"
            )->fetchColumn();

            if ($state === false) {
                $maxId = (int) $this->siges->query('SELECT COALESCE(MAX(id), 0) FROM public.cita')->fetchColumn();
                $initialRows = $this->fetchCurrentAppointments();
                foreach ($initialRows as $row) {
                    $this->saveAppointment($row);
                }
                $insertState = $this->local->prepare(
                    "INSERT INTO siges_sync_state (sync_key, last_cita_id) VALUES ('appointments', :last_cita_id)"
                );
                $insertState->execute([':last_cita_id' => $maxId]);
                $this->local->commit();

                return ['initialized' => true, 'imported' => count($initialRows), 'last_id' => $maxId];
            }

            $lastId = (int) $state;
            $snapshots = $this->fetchCurrentAppointmentSnapshots();
            $localRows = $this->local->query(
                'SELECT siges_cita_id, appointment_date, appointment_time, status
                 FROM appointments
                 WHERE siges_cita_id IS NOT NULL AND appointment_date >= CURRENT_DATE'
            )->fetchAll();
            $localBySourceId = [];
            foreach ($localRows as $localRow) {
                $localBySourceId[(int) $localRow['siges_cita_id']] = $localRow;
            }

            $changedIds = [];
            $currentSourceIds = [];
            $maxId = $lastId;
            foreach ($snapshots as $snapshot) {
                $sourceId = (int) $snapshot['siges_cita_id'];
                $currentSourceIds[$sourceId] = true;
                $maxId = max($maxId, $sourceId);
                $localRow = $localBySourceId[$sourceId] ?? null;
                if ($localRow === null
                    || (string) $localRow['appointment_date'] !== (string) $snapshot['appointment_date']
                    || substr((string) $localRow['appointment_time'], 0, 8) !== substr((string) $snapshot['appointment_time'], 0, 8)
                    || (string) $localRow['status'] !== (string) $snapshot['status']) {
                    $changedIds[] = $sourceId;
                }
            }

            foreach ($localBySourceId as $sourceId => $localRow) {
                if (!isset($currentSourceIds[$sourceId])) {
                    $this->markAppointmentNotCurrent->execute([':siges_cita_id' => $sourceId]);
                }
            }

            $sourceRows = $this->fetchAppointmentsByIds($changedIds);
            foreach ($sourceRows as $row) {
                $this->saveAppointment($row);
            }

            $updateState = $this->local->prepare(
                "UPDATE siges_sync_state SET last_cita_id = :last_cita_id WHERE sync_key = 'appointments'"
            );
            $updateState->execute([':last_cita_id' => $maxId]);
            $this->local->commit();

            return ['initialized' => false, 'imported' => count($sourceRows), 'last_id' => $maxId];
        } catch (Throwable $error) {
            if ($this->local->inTransaction()) {
                $this->local->rollBack();
            }
            throw $error;
        }
    }

    /**
     * SIGES guarda el celular en cita.contactos como JSON de texto, p. ej.
     * '["987654321"]' o con varios números: '["987654321","912345678"]'.
     * Devuelve el primer número que parezca un celular peruano válido, en
     * formato local normalizado (con 51 antepuesto); null si no hay ninguno
     * reconocible. Es solo un punto de partida: el envío sigue mostrando el
     * número para que una persona lo revise o corrija antes de confirmar.
     */
    public static function extractDestination(?string $rawContacts): ?string
    {
        if ($rawContacts === null || trim($rawContacts) === '') {
            return null;
        }
        $decoded = json_decode($rawContacts, true);
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $candidate) {
            $digits = preg_replace('/\D+/', '', (string) $candidate) ?? '';
            if (preg_match('/^9\d{8}$/', $digits)) {
                return '51' . $digits;
            }
            if (preg_match('/^51\d{9}$/', $digits)) {
                return $digits;
            }
        }

        return null;
    }

    private function saveAppointment(array $row): void
    {
        $sourceId = (int) $row['siges_cita_id'];
        $values = [
            ':source_key' => hash('sha256', 'siges-cita:' . $sourceId),
            ':siges_cita_id' => $sourceId,
            ':patient_name' => $row['patient_name'] ?: 'PACIENTE NO IDENTIFICADO',
            ':appointment_date' => $row['appointment_date'],
            ':appointment_time' => $row['appointment_time'],
            ':doctor_name' => $row['doctor_name'] ?? '',
            ':specialty' => $row['specialty'] ?? '',
            ':sede_nombre' => $row['sede_nombre'],
            ':consultorio' => $row['consultorio'],
            ':modulo' => $row['modulo'],
            ':codigo_ipress' => $row['codigo_ipress'],
            ':appointment_note' => $row['appointment_note'],
            ':visit_reason' => $row['visit_reason'],
            ':destination' => self::extractDestination($row['raw_contacts'] ?? null),
            ':status' => $row['status'] ?? '',
        ];

        $this->matchExistingAppointment->execute([
            ':patient_name' => $values[':patient_name'],
            ':appointment_date' => $values[':appointment_date'],
            ':appointment_time' => $values[':appointment_time'],
            ':doctor_name' => $values[':doctor_name'],
            ':sede_nombre' => $values[':sede_nombre'],
            ':consultorio' => $values[':consultorio'],
        ]);
        $localId = $this->matchExistingAppointment->fetchColumn();
        if ($localId === false) {
            $this->upsertAppointment->execute($values);
            return;
        }

        $values[':local_id'] = (int) $localId;
        $this->updateExistingAppointment->execute($values);
    }

    private function fetchCurrentAppointments(): array
    {
        return $this->fetchAppointments('c.fecha >= CURRENT_DATE', []);
    }

    private function fetchCurrentAppointmentSnapshots(): array
    {
        return $this->siges->query(
            "SELECT id AS siges_cita_id, fecha AS appointment_date, hora AS appointment_time,
                    estado::text AS status
             FROM public.cita
             WHERE fecha >= CURRENT_DATE AND tipo::text = 'NORMAL'
             ORDER BY id ASC"
        )->fetchAll();
    }

    private function fetchAppointmentsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $parameters = [];
        $placeholders = [];
        foreach (array_values($ids) as $index => $id) {
            $name = ':source_id_' . $index;
            $placeholders[] = $name;
            $parameters[$name] = (int) $id;
        }

        return $this->fetchAppointments('c.id IN (' . implode(', ', $placeholders) . ')', $parameters);
    }

    private function fetchAppointments(string $condition, array $parameters): array
    {
        $sql = <<<'SQL'
            SELECT
                c.id AS siges_cita_id,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', pe.paterno, pe.materno, pe.nombres)), ''),
                    NULLIF(c.sinhistoria, '')::json ->> 'paciente',
                    'PACIENTE NO IDENTIFICADO'
                ) AS patient_name,
                c.fecha AS appointment_date,
                c.hora AS appointment_time,
                COALESCE(pm.medico, '') AS doctor_name,
                COALESCE(pm.especialidad, '') AS specialty,
                CASE
                    -- Consultorio 3 Lince es una dirección propia (Jr. Joaquín Bernal 344),
                    -- distinta de la sede Lince principal, aunque comparte el mismo código
                    -- IPRESS (00033237) que los consultorios 1 y 2. SIGES no lo modela como
                    -- una fila propia en public.sede, así que se distingue por el nombre del
                    -- consultorio, no por el IPRESS. %% porque la consulta pasa por sprintf().
                    WHEN co.descripcion ILIKE '%%CONSULTORIO 3 LINCE%%' THEN 'LINCE - CONSULTORIO 3'
                    WHEN co.codigo_ipress = '00027276' THEN 'LIMA'
                    WHEN co.codigo_ipress = '00033237' THEN 'LINCE'
                    -- Consultorios sin IPRESS en SIGES (p. ej. "Consultorio Atención Inmediata Lince").
                    WHEN co.descripcion ILIKE '%%LINCE%%' THEN 'LINCE'
                    ELSE 'SEDE NO MAPEADA'
                END AS sede_nombre,
                co.descripcion AS consultorio,
                co.modulo,
                co.codigo_ipress,
                c.observacion AS appointment_note,
                atencion.motivoconsulta AS visit_reason,
                c.contactos AS raw_contacts,
                COALESCE(c.estado::text, '') AS status
            FROM public.cita c
            LEFT JOIN public.historia h ON h.id = c.historia_id
            LEFT JOIN public.persona pe ON pe.id = h.persona_id
            LEFT JOIN public.vw_programacion_medica pm ON pm.programacion_id = c.programacion_id
            LEFT JOIN public.programacion pr ON pr.id = c.programacion_id
            LEFT JOIN public.consultorio co ON co.id = pr.consultorio_id
            LEFT JOIN LATERAL (
                SELECT am.motivoconsulta
                FROM public.admision a
                JOIN public.actomedico am ON am.admision_id = a.id
                WHERE a.cita_id = c.id AND a.estado <> 'ANULADA'
                ORDER BY a.id DESC
                LIMIT 1
            ) atencion ON TRUE
            -- Solo tipo NORMAL entra a la base local (lista blanca, no solo excluir
            -- ADICIONAL): SIGES también tiene otros tipos, p. ej. PROCEDIMIENTO, que
            -- tampoco deben aparecer en la agenda ni en los envíos.
            WHERE (%s) AND c.tipo::text = 'NORMAL'
            ORDER BY c.id ASC
            SQL;
        $statement = $this->siges->prepare(sprintf($sql, $condition));
        $statement->execute($parameters);

        return $statement->fetchAll();
    }
}
