<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10233f">
    <title>Citas pendientes | SIGES</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../public/assets/css/app.css') ?>">
    <script src="assets/js/app.js?v=<?= (int) filemtime(__DIR__ . '/../public/assets/js/app.js') ?>" defer></script>
</head>
<body data-appointments-version="<?= $escape($dataVersion) ?>">
<div class="app-shell">
    <aside class="sidebar" aria-label="Navegación principal">
        <a class="brand" href="index.php" aria-label="SIGES, inicio">
            <span class="brand-mark" aria-hidden="true">S</span>
            <span><strong>SIGES</strong><small>Agenda médica</small></span>
        </a>
        <div class="sidebar-section-label">CONSULTAS</div>
        <a class="nav-item is-active" href="index.php" aria-current="page">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="4.5" width="17" height="16" rx="2.5"/><path d="M8 2.8v3.4M16 2.8v3.4M3.5 9.5h17M8 13h3m-3 3h6"/></svg>
            <span>Citas pendientes</span>
        </a>
        <a class="nav-item" href="messages.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v12H9l-5 3v-15Z"/><path d="M8 10h8M8 13.5h5"/></svg>
            <span>Contactos enviados</span>
        </a>
        <a class="nav-item" href="manual-reminders.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.8 20c.7-3.7 2.8-5.5 6.2-5.5 2 0 3.5.6 4.6 1.8M17.5 13v8M13.5 17h8"/></svg>
            <span>Recordatorios temporales</span>
        </a>
        <a class="nav-item" href="chats.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v10.5H10l-4.3 3.4v-3.4H4Z"/><path d="M8 9.7h8M8 12.7h5"/></svg>
            <span>Chats</span>
            <span class="nav-badge" <?= $chatUnread > 0 ? '' : 'hidden' ?>><?= (int) $chatUnread ?></span>
        </a>
        <div class="sidebar-footer"><span class="status-dot"></span> Datos de SIGES <small>Solo lectura</small></div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Agenda <span>/</span> <strong>Citas pendientes</strong></div>
            <div class="topbar-meta"><span class="live-dot"></span> Estado RESERVADO</div>
        </header>

        <div class="page-content">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">AGENDA DE ATENCIÓN</p>
                    <h1>Citas pendientes</h1>
                    <p class="heading-copy">Consulta las próximas atenciones programadas y el profesional asignado.</p>
                </div>
                <div class="date-chip"><span class="calendar-icon" aria-hidden="true">▦</span> <?= $errorMessage === '' ? 'Datos cargados desde MySQL' : 'MySQL desconectado' ?></div>
            </section>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert" role="alert"><strong>Sin conexión</strong><span><?= $escape($errorMessage) ?></span></div>
            <?php endif; ?>
            <?php if (is_array($notification)): ?>
                <div class="notification <?= ($notification['type'] ?? '') === 'success' ? 'notification-success' : 'notification-error' ?>" role="status"><?= $escape($notification['message'] ?? '') ?></div>
            <?php endif; ?>
            <div class="notification live-update-notice" id="live-update-notice" role="status" hidden>
                Hay cambios en las citas. <button type="button" id="reload-appointments">Actualizar lista</button>
            </div>
            <?php if (!$chatbotConfigured): ?>
                <div class="api-config-note" role="status">Envío desactivado: faltan las claves de Gestionalo en la configuración local.</div>
            <?php endif; ?>

            <section class="metric-grid" aria-label="Resumen de citas filtradas">
                <article class="metric-card metric-primary">
                    <div class="metric-icon calendar-tint" aria-hidden="true">▦</div>
                    <p>Total de citas</p><strong><?= number_format($summary['total'], 0, ',', '.') ?></strong>
                    <span class="metric-caption">Con estado pendiente</span>
                </article>
                <article class="metric-card">
                    <div class="metric-icon doctor-tint" aria-hidden="true">⌖</div>
                    <p>Sedes</p><strong><?= number_format($summary['sites'], 0, ',', '.') ?></strong>
                    <span class="metric-caption">Con citas en este filtro</span>
                </article>
                <article class="metric-card">
                    <div class="metric-icon note-tint" aria-hidden="true">▤</div>
                    <p>Con observación</p><strong><?= number_format($summary['with_note'], 0, ',', '.') ?></strong>
                    <span class="metric-caption">Nota registrada en la cita</span>
                </article>
                <article class="metric-card">
                    <div class="metric-icon reason-tint" aria-hidden="true">⌕</div>
                    <p>Motivo clínico</p><strong><?= number_format($summary['with_reason'], 0, ',', '.') ?></strong>
                    <span class="metric-caption">Registrado en una atención</span>
                </article>
            </section>

            <section class="appointments-panel">
                <div class="panel-heading">
                    <div><h2>Agenda programada</h2><p>Se muestran únicamente citas con estado <span class="status-pill">RESERVADO</span></p></div>
                    <span class="result-count"><?= number_format($summary['total'], 0, ',', '.') ?> resultados</span>
                </div>
                <form class="filters" method="get" action="index.php" id="filter-form">
                    <label class="search-field">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.6"/><path d="m16 16 4.2 4.2"/></svg>
                        <input type="search" name="q" value="<?= $escape($filters['search']) ?>" placeholder="Buscar paciente, médico o motivo" aria-label="Buscar en citas">
                    </label>
                    <label class="date-field"><span>Desde</span><input type="date" name="desde" min="<?= $escape($today) ?>" value="<?= $escape($filters['date_from']) ?>"></label>
                    <label class="date-field"><span>Hasta</span><input type="date" name="hasta" min="<?= $escape($today) ?>" value="<?= $escape($filters['date_to']) ?>"></label>
                    <button class="button-primary" type="submit">Filtrar</button>
                    <?php if ($filters['search'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== ''): ?>
                        <a class="clear-link" href="index.php">Limpiar</a>
                    <?php endif; ?>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>Paciente</th><th>Fecha y hora</th><th>Profesional</th>
                            <th>Sede</th><th>Motivo / observación</th><th>Acción</th>
                        </tr></thead>
                        <tbody>
                        <?php if ($appointments === []): ?>
                            <tr><td class="empty-state" colspan="6"><span class="empty-icon">⌕</span><strong>No hay citas para mostrar</strong><small>Prueba cambiando la búsqueda o el rango de fechas.</small></td></tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $appointment): ?>
                                <?php
                                $clinicalReason = trim((string) ($appointment['visit_reason'] ?? ''));
                                $appointmentNote = trim((string) ($appointment['appointment_note'] ?? ''));
                                $hasAppointmentNote = $appointmentNote !== '' && $appointmentNote !== 'Sin observación registrada';
                                $reasonText = $clinicalReason ?: ($hasAppointmentNote ? $appointmentNote : 'Sin motivo registrado');
                                $reasonType = $clinicalReason !== '' ? 'Motivo clínico' : ($hasAppointmentNote ? 'Observación de la cita' : '');
                                $locationDetails = array_filter([
                                    $appointment['consultorio'] ?: '',
                                    $appointment['modulo'] ? 'Módulo: ' . $appointment['modulo'] : '',
                                ]);
                                ?>
                                <tr>
                                    <?php
                                    $reminderStatus = (string) ($appointment['reminder_status'] ?? '');
                                    $contactState = match ($reminderStatus) {
                                        'sent' => ['sent', 'Aceptado por Gestionalo'],
                                        'failed' => ['failed', 'Falló el envío'],
                                        'pending' => ['sending', 'Envío en curso'],
                                        default => ['pending', 'Pendiente de contacto'],
                                    };
                                    ?>
                                    <td class="patient-cell" data-label="Paciente">
                                        <strong class="patient-name"><?= $escape($appointment['patient_name']) ?></strong>
                                        <span class="contact-status status-<?= $escape($contactState[0]) ?>"><i aria-hidden="true"></i><?= $escape($contactState[1]) ?></span>
                                    </td>
                                    <td class="schedule-cell" data-label="Fecha y hora">
                                        <span><?= $escape($formatDate($appointment['appointment_date'])) ?></span>
                                        <strong class="time-cell"><?= $escape(substr((string) $appointment['appointment_time'], 0, 5)) ?></strong>
                                    </td>
                                    <td class="doctor-cell" data-label="Profesional"><strong><?= $escape($appointment['doctor_name'] ?: 'No asignado') ?></strong><small><?= $escape($appointment['specialty'] ?: 'Especialidad no indicada') ?></small></td>
                                    <td class="location-cell" data-label="Sede">
                                        <strong><?= $escape($appointment['sede_nombre'] ?: 'Sede no informada') ?></strong>
                                        <?php if ($locationDetails !== [] || !empty($appointment['codigo_ipress'])): ?>
                                            <details class="location-details">
                                                <summary>Ver consultorio</summary>
                                                <?php if ($locationDetails !== []): ?><small><?= $escape(implode(' · ', $locationDetails)) ?></small><?php endif; ?>
                                                <?php if (!empty($appointment['codigo_ipress'])): ?><small class="ipress-detail">IPRESS <?= $escape($appointment['codigo_ipress']) ?></small><?php endif; ?>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td class="reason-cell" data-label="Motivo / observación"><span><?= $escape($reasonText) ?></span><?php if ($reasonType !== ''): ?><small><?= $escape($reasonType) ?></small><?php endif; ?></td>
                                    <td class="action-cell" data-label="Acción">
                                        <button
                                            class="send-action"
                                            type="button"
                                            data-open-reminder
                                            data-appointment-id="<?= (int) $appointment['id'] ?>"
                                            data-patient="<?= $escape($appointment['patient_name']) ?>"
                                            data-date="<?= $escape($formatDate($appointment['appointment_date'])) ?>"
                                            data-time="<?= $escape(substr((string) $appointment['appointment_time'], 0, 5)) ?>"
                                        >Recordar</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <dialog class="reminder-dialog" id="reminder-dialog" aria-labelledby="reminder-title">
                    <div class="reminder-dialog-heading">
                        <div><p class="eyebrow">GESTIONALO</p><h2 id="reminder-title">Enviar recordatorio</h2></div>
                        <button class="dialog-close" type="button" id="close-reminder" aria-label="Cerrar">×</button>
                    </div>
                    <p class="dialog-description">Revisa el destino y el mensaje antes de enviarlo.</p>
                    <form action="send-reminder.php" method="post" class="send-form" id="send-reminder-form">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="appointment_id" id="reminder-appointment-id">
                        <label>Celular de destino (9 dígitos; se agrega 51)
                            <input type="tel" name="destination" inputmode="tel" autocomplete="off" placeholder="996080370" pattern="[0-9+() -]{8,20}" required>
                        </label>
                        <label>Mensaje (puedes editarlo)
                            <textarea name="message" id="reminder-message" rows="5" maxlength="1000" required></textarea>
                        </label>
                        <label class="transmission-consent"><input type="checkbox" name="confirm_transmission" value="yes" required> Confirmo enviar el texto mostrado al celular por Gestionalo.</label>
                        <button class="button-primary send-button" type="submit" <?= $chatbotConfigured ? '' : 'disabled' ?>>Enviar</button>
                        <?php if (!$chatbotConfigured): ?><small class="api-required">Envío desactivado: configura CHATBOT_LINE_KEY (serie de la línea) en config/local.php.</small><?php endif; ?>
                    </form>
                </dialog>

                <footer class="table-footer">
                    <span>Mostrando <strong><?= $appointments === [] ? 0 : (($pagination['page'] - 1) * $pagination['page_size'] + 1) ?></strong>–<strong><?= ($pagination['page'] - 1) * $pagination['page_size'] + count($appointments) ?></strong> de <strong><?= number_format($summary['total'], 0, ',', '.') ?></strong></span>
                    <nav class="pagination" aria-label="Paginación">
                        <?php if ($pagination['page'] > 1): ?>
                            <a href="?<?= $escape(http_build_query($paginationQuery + ['pagina' => $pagination['page'] - 1])) ?>" aria-label="Página anterior">‹</a>
                        <?php else: ?><span class="disabled" aria-hidden="true">‹</span><?php endif; ?>
                        <span class="page-number">Página <?= $pagination['page'] ?> de <?= $pagination['pages'] ?></span>
                        <?php if ($pagination['page'] < $pagination['pages']): ?>
                            <a href="?<?= $escape(http_build_query($paginationQuery + ['pagina' => $pagination['page'] + 1])) ?>" aria-label="Página siguiente">›</a>
                        <?php else: ?><span class="disabled" aria-hidden="true">›</span><?php endif; ?>
                    </nav>
                </footer>
            </section>
            <p class="data-note"><strong>Nota:</strong> el motivo clínico corresponde a lo registrado durante una atención. Si la cita aún no fue atendida, puede aparecer como “Aún no registrado”.</p>
        </div>
    </main>
</div>
</body>
</html>
