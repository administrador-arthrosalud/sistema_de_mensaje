<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10233f">
    <title>Contactos enviados | SIGES</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../public/assets/css/app.css') ?>">
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
        <a class="nav-item is-active" href="messages.php" aria-current="page">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v12H9l-5 3v-15Z"/><path d="M8 10h8M8 13.5h5"/></svg>
            <span>Contactos enviados</span>
        </a>
        <a class="nav-item" href="manual-reminders.php">
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
            <div class="breadcrumb">Agenda <span>/</span> <strong>Contactos enviados</strong></div>
            <div class="topbar-meta"><span class="sent-indicator-dot"></span> Historial local</div>
        </header>

        <div class="page-content">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">SEGUIMIENTO</p>
                    <h1>Contactos enviados</h1>
                    <p class="heading-copy">Recordatorios aceptados por Gestionalo, con su contacto y cita asociada.</p>
                </div>
                <div class="date-chip"><span class="sent-indicator-dot"></span><?= number_format($total, 0, ',', '.') ?> envíos registrados</div>
            </section>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert" role="alert"><?= $escape($errorMessage) ?></div>
            <?php endif; ?>

            <section class="appointments-panel history-panel">
                <div class="panel-heading">
                    <div><h2>Historial de recordatorios</h2><p>Solo aparecen solicitudes que Gestionalo aceptó.</p></div>
                    <a class="back-link" href="index.php">Volver a citas</a>
                </div>
                <form class="filters history-filters" method="get" action="messages.php">
                    <label class="search-field">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.6"/><path d="m16 16 4.2 4.2"/></svg>
                        <input type="search" name="q" value="<?= $escape($filters['search']) ?>" placeholder="Buscar paciente, celular o médico" aria-label="Buscar contactos enviados">
                    </label>
                    <label class="date-field"><span>Desde</span><input type="date" name="desde" value="<?= $escape($filters['date_from']) ?>"></label>
                    <label class="date-field"><span>Hasta</span><input type="date" name="hasta" value="<?= $escape($filters['date_to']) ?>"></label>
                    <button class="button-primary" type="submit">Filtrar</button>
                    <?php if ($filters['search'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== ''): ?>
                        <a class="clear-link" href="messages.php">Limpiar</a>
                    <?php endif; ?>
                </form>

                <div class="table-wrap">
                    <table class="history-table">
                        <thead><tr><th>Contacto</th><th>Cita</th><th>Médico</th><th>Origen</th><th>Enviado</th><th>Resultado</th></tr></thead>
                        <tbody>
                        <?php if ($messages === []): ?>
                            <tr><td class="empty-state" colspan="6"><span class="empty-icon">⌕</span><strong>Aún no hay contactos enviados</strong><small>Los envíos aceptados por Gestionalo aparecerán aquí.</small></td></tr>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <tr>
                                    <td class="history-contact" data-label="Contacto"><strong><?= $escape($message['patient_name']) ?></strong><small><?= $escape($message['destination']) ?></small></td>
                                    <td class="schedule-cell" data-label="Cita"><span><?= $escape($formatDate($message['appointment_date'])) ?></span><strong class="time-cell"><?= $escape(substr((string) $message['appointment_time'], 0, 5)) ?></strong></td>
                                    <td class="doctor-cell" data-label="Médico"><strong><?= $escape($message['doctor_name'] ?: 'No asignado') ?></strong></td>
                                    <td data-label="Origen"><span class="origin-pill <?= ($message['origin'] ?? 'siges') === 'manual' ? 'origin-manual' : '' ?>"><?= ($message['origin'] ?? 'siges') === 'manual' ? 'Temporal' : 'Cita SIGES' ?></span></td>
                                    <td class="nowrap" data-label="Enviado"><?= $escape($formatDateTime($message['sent_at'])) ?></td>
                                    <td data-label="Resultado"><span class="delivery-status"><i></i>Aceptado por Gestionalo</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <footer class="table-footer">
                    <span>Mostrando <strong><?= $messages === [] ? 0 : (($page - 1) * $pageSize + 1) ?></strong>–<strong><?= ($page - 1) * $pageSize + count($messages) ?></strong> de <strong><?= number_format($total, 0, ',', '.') ?></strong></span>
                    <nav class="pagination" aria-label="Paginación">
                        <?php if ($page > 1): ?><a href="?<?= $escape(http_build_query($pageQuery + ['pagina' => $page - 1])) ?>" aria-label="Página anterior">‹</a><?php else: ?><span class="disabled" aria-hidden="true">‹</span><?php endif; ?>
                        <span class="page-number">Página <?= $page ?> de <?= $pages ?></span>
                        <?php if ($page < $pages): ?><a href="?<?= $escape(http_build_query($pageQuery + ['pagina' => $page + 1])) ?>" aria-label="Página siguiente">›</a><?php else: ?><span class="disabled" aria-hidden="true">›</span><?php endif; ?>
                    </nav>
                </footer>
            </section>
            <p class="data-note">“Aceptado por Gestionalo” confirma la aceptación de la solicitud; no confirma la entrega o lectura en WhatsApp.</p>
        </div>
    </main>
</div>
</body>
</html>
