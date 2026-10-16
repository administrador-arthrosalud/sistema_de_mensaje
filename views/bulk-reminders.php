<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10233f">
    <title>Envío masivo | SIGES</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../public/assets/css/app.css') ?>">
    <script src="assets/js/bulk-reminders.js?v=<?= (int) filemtime(__DIR__ . '/../public/assets/js/bulk-reminders.js') ?>" defer></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Navegación principal">
        <a class="brand" href="index.php" aria-label="SIGES, inicio">
            <span class="brand-mark" aria-hidden="true">S</span>
            <span><strong>SIGES</strong><small>Agenda médica</small></span>
        </a>
        <div class="sidebar-section-label">CONSULTAS</div>
        <a class="nav-item" href="index.php">
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
        <a class="nav-item is-active" href="bulk-reminders.php" aria-current="page">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5h16M4 12h16M4 16.5h10"/><circle cx="19" cy="16.5" r="2.2"/></svg>
            <span>Envío masivo</span>
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
            <div class="breadcrumb">Agenda <span>/</span> <strong>Envío masivo</strong></div>
            <div class="topbar-meta"><span class="live-dot"></span> Celular detectado desde SIGES</div>
        </header>

        <div class="page-content">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">GESTIONALO · LOTE</p>
                    <h1>Envío masivo de recordatorios</h1>
                    <p class="heading-copy">Elige una fecha, revisa la lista completa y confirma una sola vez para enviar el recordatorio a todos los seleccionados.</p>
                </div>
            </section>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert" role="alert"><strong>Sin conexión</strong><span><?= $escape($errorMessage) ?></span></div>
            <?php endif; ?>
            <?php if (is_array($notification)): ?>
                <div class="notification <?= ($notification['type'] ?? '') === 'success' ? 'notification-success' : 'notification-error' ?>" role="status"><?= $escape($notification['message'] ?? '') ?></div>
            <?php endif; ?>
            <?php if (!$chatbotConfigured): ?>
                <div class="api-config-note" role="status">Envío desactivado: faltan las claves de Gestionalo en la configuración local.</div>
            <?php endif; ?>
            <div class="alert" role="note"><strong>Antes de enviar</strong><span>El celular se extrajo automáticamente de SIGES y puede tener errores. Revisa la lista con calma; cada envío real sigue registrándose como si lo hicieras uno por uno.</span></div>

            <section class="appointments-panel bulk-panel">
                <div class="panel-heading">
                    <div><h2>Citas de la fecha elegida</h2><p>Solo se listan las que tienen celular detectado y no se les envió un recordatorio aceptado todavía.</p></div>
                    <span class="result-count"><?= number_format(count($previews), 0, ',', '.') ?> listas para enviar</span>
                </div>
                <form class="filters" method="get" action="bulk-reminders.php" id="bulk-date-form">
                    <label class="date-field"><span>Fecha</span><input type="date" name="fecha" min="<?= $escape($today->format('Y-m-d')) ?>" value="<?= $escape($date) ?>" required></label>
                    <button class="button-primary" type="submit">Ver citas</button>
                </form>
                <?php if ($missingDestinationCount > 0): ?>
                    <p class="data-note">Además, <strong><?= number_format($missingDestinationCount, 0, ',', '.') ?></strong> cita<?= $missingDestinationCount === 1 ? '' : 's' ?> de ese día no tiene<?= $missingDestinationCount === 1 ? '' : 'n' ?> un celular detectado en SIGES; envíalas una por una desde <a href="index.php">Citas pendientes</a> escribiendo el número a mano.</p>
                <?php endif; ?>

                <?php if ($previews !== []): ?>
                    <form class="send-form" method="post" action="bulk-reminders.php?action=send" id="bulk-send-form">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="fecha" value="<?= $escape($date) ?>">
                        <label class="search-field bulk-name-filter-field">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.6"/><path d="m16 16 4.2 4.2"/></svg>
                            <input type="search" id="bulk-name-filter" placeholder="Buscar por nombre del paciente" aria-label="Buscar por nombre del paciente">
                        </label>
                        <div class="table-wrap">
                            <table class="bulk-table">
                                <thead><tr>
                                    <th><input type="checkbox" id="bulk-select-all" checked aria-label="Seleccionar todos"></th>
                                    <th>Paciente</th><th>Hora</th><th>Profesional</th><th>Sede</th><th>Celular</th><th>Mensaje</th>
                                </tr></thead>
                                <tbody id="bulk-table-body">
                                <?php foreach ($previews as $appointment): ?>
                                    <tr data-patient-name="<?= $escape(mb_strtolower((string) $appointment['patient_name'], 'UTF-8')) ?>">
                                        <td><input type="checkbox" name="appointment_id[]" value="<?= (int) $appointment['id'] ?>" class="bulk-row-check" checked></td>
                                        <td data-label="Paciente"><strong><?= $escape($appointment['patient_name']) ?></strong></td>
                                        <td data-label="Hora"><?= $escape(substr((string) $appointment['appointment_time'], 0, 5)) ?></td>
                                        <td data-label="Profesional"><?= $escape($appointment['doctor_name'] ?: 'No asignado') ?></td>
                                        <td data-label="Sede"><?= $escape($appointment['sede_nombre'] === 'SEDE NO MAPEADA' ? 'No informada' : ($appointment['sede_nombre'] ?: 'No informada')) ?></td>
                                        <td data-label="Celular"><?= $escape($appointment['destination']) ?></td>
                                        <td data-label="Mensaje">
                                            <details class="location-details">
                                                <summary>Ver mensaje</summary>
                                                <pre class="bulk-message-preview"><?= $escape($appointment['preview_message']) ?></pre>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="bulk-no-match-row" hidden><td class="empty-state" colspan="7"><span class="empty-icon">⌕</span><strong>Sin coincidencias</strong><small>Prueba con otro nombre.</small></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <label class="transmission-consent">
                            <input type="checkbox" name="confirm_transmission" value="yes" required>
                            Confirmo que revisé la lista de arriba y autorizo enviar este recordatorio, por Gestionalo, a cada paciente seleccionado.
                        </label>
                        <button class="button-primary send-button" type="submit" id="bulk-send-button" <?= $chatbotConfigured ? '' : 'disabled' ?>>Enviar a los seleccionados (<span id="bulk-selected-count"><?= count($previews) ?></span>)</button>
                        <?php if (!$chatbotConfigured): ?><small class="api-required">Envío desactivado: configura CHATBOT_LINE_KEY en config/local.php.</small><?php endif; ?>
                    </form>
                <?php else: ?>
                    <div class="empty-state"><span class="empty-icon">⌕</span><strong>Nada pendiente para esta fecha</strong><small>No hay citas con celular detectado que sigan sin un recordatorio aceptado.</small></div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
