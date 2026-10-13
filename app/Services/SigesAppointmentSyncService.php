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
                 specialty, sede_nombre, consultorio, modulo, codigo_ipress, appointment_note, visit_reason, status)
             VALUES
                (:source_key, :siges_cita_id, :patient_name, :appointment_date, :appointment_time, :doctor_name,
                 :specialty, :sede_nombre, :consultorio, :modulo, :codigo_ipress, :appointment_note, :visit_reason, :status)
             ON DUPLICATE KEY UPDATE
                siges_cita_id = VALUES(siges_cita_id),
                patient_name = VALUES(patient_name),
                appointment_date = VALUES(appointment_date),
                appointment_time = VALUES(appointment_time),
                doctor_name = VALUES(doctor_name),
                specialty = VALUES(specialty),
                sede_nombre = VALUES(sede_nombre),
                consultorio = VALUES(consultorio),
                modulo = VALUES(modulo),
                codigo_ipress = VALUES(codigo_ipress),
                appointment_note = VALUES(appointment_note),
                visit_reason = VALUES(visit_reason),
                status = VALUES(status)"
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
                visit_reason = :visit_reason, status = :status
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
            'SELECT id AS siges_cita_id, fecha AS appointment_date, hora AS appointment_time,
                    estado::text AS status
             FROM public.cita
             WHERE fecha >= CURRENT_DATE
             ORDER BY id ASC'
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
                COALESCE(sd.nombre, 'SEDE NO MAPEADA') AS sede_nombre,
                co.descripcion AS consultorio,
                co.modulo,
                co.codigo_ipress,
                c.observacion AS appointment_note,
                atencion.motivoconsulta AS visit_reason,
                COALESCE(c.estado::text, '') AS status
            FROM public.cita c
            LEFT JOIN public.historia h ON h.id = c.historia_id
            LEFT JOIN public.persona pe ON pe.id = h.persona_id
            LEFT JOIN public.vw_programacion_medica pm ON pm.programacion_id = c.programacion_id
            LEFT JOIN public.programacion pr ON pr.id = c.programacion_id
            LEFT JOIN public.consultorio co ON co.id = pr.consultorio_id
            LEFT JOIN public.sede sd ON UPPER(BTRIM(sd.nombre)) = CASE co.codigo_ipress
                WHEN '00027276' THEN 'LIMA'
                WHEN '00033237' THEN 'LINCE'
                ELSE NULL
            END
            LEFT JOIN LATERAL (
                SELECT am.motivoconsulta
                FROM public.admision a
                JOIN public.actomedico am ON am.admision_id = a.id
                WHERE a.cita_id = c.id AND a.estado <> 'ANULADA'
                ORDER BY a.id DESC
                LIMIT 1
            ) atencion ON TRUE
            WHERE %s
            ORDER BY c.id ASC
            SQL;
        $statement = $this->siges->prepare(sprintf($sql, $condition));
        $statement->execute($parameters);

        return $statement->fetchAll();
    }
}
