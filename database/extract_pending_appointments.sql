-- Ejecutar contra PostgreSQL de SIGES (isis3_arths).
-- La ubicación se obtiene de programación -> consultorio.
-- SIGES no tiene FK directa a sede; el CASE usa los códigos IPRESS validados
-- en el extracto actual: 00027276 = LIMA y 00033237 = LINCE.
SELECT
    c.id AS cita_id,
    COALESCE(
        NULLIF(TRIM(CONCAT_WS(' ', pe.paterno, pe.materno, pe.nombres)), ''),
        NULLIF(c.sinhistoria, '')::json ->> 'paciente'
    ) AS paciente,
    pm.medico AS medico_asignado,
    pm.especialidad,
    COALESCE(sd.nombre, 'SEDE NO MAPEADA') AS sede,
    co.descripcion AS consultorio,
    co.modulo,
    co.codigo_ipress,
    c.observacion AS observacion_cita,
    atencion.motivoconsulta AS motivo_consulta_registrado_en_atencion,
    c.fecha AS dia,
    c.hora,
    c.estado
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
    WHERE a.cita_id = c.id
      AND a.estado <> 'ANULADA'
    ORDER BY a.id DESC
    LIMIT 1
) atencion ON TRUE
WHERE c.fecha >= CURRENT_DATE
  AND c.estado = 'RESERVADO'
ORDER BY c.fecha, c.hora;
