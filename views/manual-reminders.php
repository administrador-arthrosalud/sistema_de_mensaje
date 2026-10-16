<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10233f">
    <title>Recordatorios temporales | SIGES</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../public/assets/css/app.css') ?>">
    <script src="assets/js/app.js?v=<?= (int) filemtime(__DIR__ . '/../public/assets/js/app.js') ?>" defer></script>
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
        <a class="nav-item is-active" href="manual-reminders.php" aria-current="page">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.8 20c.7-3.7 2.8-5.5 6.2-5.5 2 0 3.5.6 4.6 1.8M17.5 13v8M13.5 17h8"/></svg>
            <span>Recordatorios temporales</span>
        </a>
        <a class="nav-item" href="bulk-reminders.php">
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
            <div class="breadcrumb">Agenda <span>/</span> <strong>Recordatorios temporales</strong></div>
            <div class="topbar-meta"><span class="sent-indicator-dot"></span> Registro local</div>
        </header>

        <div class="page-content">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">CONTACTOS MANUALES</p>
                    <h1>Recordatorios temporales</h1>
                    <p class="heading-copy">Agrega una cita manual y envía su recordatorio sin incorporarla a SIGES.</p>
                </div>
                <div class="date-chip"><?= number_format(count($contacts), 0, ',', '.') ?> contactos temporales</div>
            </section>

            <?php if (is_array($notification)): ?>
                <div class="notification <?= ($notification['type'] ?? '') === 'success' ? 'notification-success' : 'notification-error' ?>" role="status"><?= $escape($notification['message'] ?? '') ?></div>
            <?php endif; ?>
            <?php if (!$chatbotConfigured): ?>
                <div class="api-config-note" role="status">Puedes registrar contactos; para enviar, configura CHATBOT_LINE_KEY en config/local.php.</div>
            <?php endif; ?>

            <section class="appointments-panel manual-entry-panel">
                <div class="panel-heading">
                    <div><h2>Agregar una persona</h2><p>Registra solo los datos necesarios para recordar la cita.</p></div>
                </div>
                <form class="manual-contact-form" method="post" action="manual-reminders.php?action=create">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <label>Nombre del paciente
                        <input type="text" name="patient_name" maxlength="255" autocomplete="name" required>
                    </label>
                    <label>Celular
                        <input type="tel" name="destination" inputmode="tel" autocomplete="tel" placeholder="999 888 777" maxlength="20" required>
                    </label>
                    <label>Fecha de la cita
                        <input type="date" name="appointment_date" min="<?= $escape($today) ?>" value="<?= $escape($today) ?>" required>
                    </label>
                    <label>Hora
                        <input type="time" name="appointment_time" required>
                    </label>
                    <label class="manual-doctor-field">Profesional (opcional)
                        <input type="text" name="doctor_name" maxlength="255" autocomplete="off">
                    </label>
                    <label class="manual-sede-field">Sede (opcional, para probar el mensaje)
                        <select name="sede">
                            <option value="">Sin indicar</option>
                            <option value="LIMA">LIMA</option>
                            <option value="LINCE">LINCE</option>
                            <option value="LINCE - CONSULTORIO 3">LINCE - CONSULTORIO 3</option>
                        </select>
                    </label>
                    <button class="button-primary manual-add-button" type="submit">Agregar contacto</button>
                </form>
                <p class="manual-data-note">Los datos se guardan solo en esta base local. Confirma con la persona que acepta recibir el mensaje antes de enviarlo.</p>
            </section>

            <section class="appointments-panel manual-list-panel">
                <div class="panel-heading">
                    <div><h2>Lista temporal</h2><p>Revisa los datos y abre el mensaje antes de enviarlo.</p></div>
                    <a class="back-link" href="index.php">Volver a citas SIGES</a>
                </div>
                <div class="table-wrap">
                    <table class="manual-table">
                        <thead><tr><th>Paciente</th><th>Celular</th><th>Fecha y hora</th><th>Profesional</th><th>Sede</th><th>Estado</th><th>Acción</th></tr></thead>
                        <tbody>
                        <?php if ($contacts === []): ?>
                            <tr><td class="empty-state" colspan="7"><span class="empty-icon">⌕</span><strong>No hay contactos temporales</strong><small>Agrega una persona con los datos de su cita.</small></td></tr>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <?php
                                $status = (string) ($contact['reminder_status'] ?? '');
                                $contactState = match ($status) {
                                    'sent' => ['sent', 'Aceptado por Gestionalo'],
                                    'failed' => ['failed', 'Falló el envío'],
                                    'pending' => ['sending', 'Envío en curso'],
                                    default => ['pending', 'Pendiente de contacto'],
                                };
                                ?>
                                <tr>
                                    <td class="patient-cell" data-label="Paciente"><strong class="patient-name"><?= $escape($contact['patient_name']) ?></strong></td>
                                    <td class="history-contact" data-label="Celular"><?= $escape($contact['destination']) ?></td>
                                    <td class="schedule-cell" data-label="Fecha y hora"><span><?= $escape($formatDate($contact['appointment_date'])) ?></span><strong class="time-cell"><?= $escape(substr((string) $contact['appointment_time'], 0, 5)) ?></strong></td>
                                    <td class="doctor-cell" data-label="Profesional"><strong><?= $escape($contact['doctor_name'] ?: 'No indicado') ?></strong></td>
                                    <td data-label="Sede"><?= $escape($contact['sede'] ?: 'No indicada') ?></td>
                                    <td data-label="Estado"><span class="contact-status status-<?= $escape($contactState[0]) ?>"><i aria-hidden="true"></i><?= $escape($contactState[1]) ?></span></td>
                                    <td class="manual-actions" data-label="Acción">
                                        <button class="send-action" type="button" data-open-manual-reminder
                                            data-contact-id="<?= (int) $contact['id'] ?>"
                                            data-patient="<?= $escape($contact['patient_name']) ?>"
                                            data-destination="<?= $escape($contact['destination']) ?>"
                                            data-date="<?= $escape($formatDate($contact['appointment_date'])) ?>"
                                            data-time="<?= $escape(substr((string) $contact['appointment_time'], 0, 5)) ?>"
                                            data-doctor="<?= $escape($contact['doctor_name']) ?>"
                                            data-sede="<?= $escape($contact['sede'] ?? '') ?>">Recordar</button>
                                        <form method="post" action="manual-reminders.php?action=delete" data-delete-manual>
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                                            <button class="manual-delete-button" type="submit" aria-label="Quitar <?= $escape($contact['patient_name']) ?> de la lista">Quitar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="manual-data-note manual-history-note">Quitar un contacto lo elimina de esta lista; los mensajes enviados permanecen en Contactos enviados.</p>
            </section>
        </div>
    </main>
</div>

<dialog class="reminder-dialog" id="manual-reminder-dialog" aria-labelledby="manual-dialog-title">
    <div class="reminder-dialog-heading">
        <div><p class="eyebrow">GESTIONALO</p><h2 id="manual-dialog-title">Enviar recordatorio</h2></div>
        <button class="dialog-close" id="close-manual-reminder" type="button" aria-label="Cerrar">×</button>
    </div>
    <p class="dialog-description">Confirma el celular y revisa el mensaje antes de enviarlo.</p>
    <form class="send-form" method="post" action="manual-reminders.php?action=send" enctype="multipart/form-data" id="send-manual-reminder-form">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="contact_id" id="manual-contact-id">
        <label>Celular de destino
            <input type="tel" id="manual-destination" readonly>
        </label>
        <label>Mensaje (puedes editarlo)
            <textarea name="message" id="manual-message" rows="15" maxlength="1000" required></textarea>
        </label>
        <label>Imagen (opcional, PNG o JPG hasta 5 MB)
            <input type="file" name="attachment" accept="image/png,image/jpeg">
        </label>
        <label class="transmission-consent"><input type="checkbox" name="confirm_transmission" value="yes" required> Confirmo que revisé el destino y autorizo enviar este mensaje.</label>
        <button class="button-primary send-button" type="submit" <?= $chatbotConfigured ? '' : 'disabled' ?>>Enviar recordatorio</button>
        <?php if (!$chatbotConfigured): ?><small class="api-required">Envío desactivado hasta configurar la API de Gestionalo.</small><?php endif; ?>
    </form>
</dialog>
</body>
</html>
