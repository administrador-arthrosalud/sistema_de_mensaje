-- Ejecutar contra PostgreSQL de SIGES (isis3_arths).
-- La ubicación se obtiene de programación -> consultorio.
-- SIGES no tiene FK directa a sede; el CASE usa los códigos IPRESS validados en
-- el extracto actual: 00027276 = LIMA y 00033237 = LINCE. "Consultorio 3 Lince"
-- comparte el IPRESS 00033237 con los consultorios 1 y 2, pero es una dirección
-- distinta (Jr. Joaquín Bernal 344), así que se distingue por su nombre, no por
-- el IPRESS. Los consultorios sin IPRESS cuyo nombre contiene "LINCE" se asignan
-- a LINCE.
SELECT
    c.id AS cita_id,
    COALESCE(
        NULLIF(TRIM(CONCAT_WS(' ', pe.paterno, pe.materno, pe.nombres)), ''),
        NULLIF(c.sinhistoria, '')::json ->> 'paciente'
    ) AS paciente,
    pm.medico AS medico_asignado,
    pm.especialidad,
    CASE
        WHEN co.descripcion ILIKE '%CONSULTORIO 3 LINCE%' THEN 'LINCE - CONSULTORIO 3'
        WHEN co.codigo_ipress = '00027276' THEN 'LIMA'
        WHEN co.codigo_ipress = '00033237' THEN 'LINCE'
        WHEN co.descripcion ILIKE '%LINCE%' THEN 'LINCE'
        ELSE 'SEDE NO MAPEADA'
    END AS sede,
    co.descripcion AS consultorio,
    co.modulo,
    co.codigo_ipress,
    c.observacion AS observacion_cita,
    atencion.motivoconsulta AS motivo_consulta_registrado_en_atencion,
    -- JSON de texto, p. ej. '["987654321"]'; SigesAppointmentSyncService::extractDestination()
    -- se queda con el primer número que parezca un celular peruano válido.
    c.contactos AS celular_json,
    c.fecha AS dia,
    c.hora,
    c.estado
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
    WHERE a.cita_id = c.id
      AND a.estado <> 'ANULADA'
    ORDER BY a.id DESC
    LIMIT 1
) atencion ON TRUE
WHERE c.fecha >= CURRENT_DATE
  AND c.estado = 'RESERVADO'
  -- Lista blanca: solo tipo NORMAL. SIGES también tiene ADICIONAL (cupos fuera del
  -- horario regular) y otros tipos (p. ej. PROCEDIMIENTO) que también se excluyen.
  AND c.tipo::text = 'NORMAL'
ORDER BY c.fecha, c.hora;
