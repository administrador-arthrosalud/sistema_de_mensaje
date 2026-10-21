<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10233f">
    <title>Estado del sistema | Chatbot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../public/assets/css/app.css') ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Navegación principal">
        <a class="brand" href="index.php" aria-label="Chatbot, inicio">
            <span><strong>CHATBOT</strong><small>Agenda médica</small></span>
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
        <a class="nav-item" href="bulk-reminders.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5h16M4 12h16M4 16.5h10"/><circle cx="19" cy="16.5" r="2.2"/></svg>
            <span>Envío masivo</span>
        </a>
        <a class="nav-item" href="chats.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v10.5H10l-4.3 3.4v-3.4H4Z"/><path d="M8 9.7h8M8 12.7h5"/></svg>
            <span>Chats</span>
            <span class="nav-badge" <?= $chatUnread > 0 ? '' : 'hidden' ?>><?= (int) $chatUnread ?></span>
        </a>
        <?= authAdminNav('health') ?>
        <?= authUserBox() ?>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Administración <span>/</span> <strong>Estado del sistema</strong></div>
            <div class="topbar-meta">Verificado a las <?= $escape($checkedAt) ?></div>
        </header>

        <div class="page-content">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">SOLO ADMINISTRADORES</p>
                    <h1>Estado del sistema</h1>
                    <p class="heading-copy">Comprueba que los datos de SIGES se estén copiando bien y que el envío esté listo. No muestra datos de pacientes.</p>
                </div>
                <a class="button-primary health-refresh" href="health.php">Volver a verificar</a>
            </section>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert" role="alert"><?= $escape($errorMessage) ?></div>
            <?php else: ?>
                <div class="health-summary health-<?= $escape($overall) ?>" role="status">
                    <strong><?= $overall === 'ok' ? 'Todo funciona correctamente' : ($overall === 'warn' ? 'Funciona, con advertencias' : 'Hay problemas que requieren atención') ?></strong>
                    <span><?= (int) $counts['ok'] ?> correctas · <?= (int) $counts['warn'] ?> advertencias · <?= (int) $counts['fail'] ?> fallas</span>
                </div>
                <?php foreach ($groups as $group => $rows): ?>
                    <section class="health-card">
                        <h2><?= $escape($group) ?></h2>
                        <?php foreach ($rows as $row): ?>
                            <div class="health-row">
                                <span class="health-badge health-<?= $escape($row['status']) ?>"><?= $escape(['ok' => 'OK', 'warn' => 'Aviso', 'fail' => 'Falla', 'info' => 'Info'][$row['status']]) ?></span>
                                <div><strong><?= $escape($row['name']) ?></strong><p><?= $escape($row['detail']) ?></p></div>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
                <p class="heading-copy">La comparación exacta contra SIGES se hace por consola con <code>scripts/verify-sync.php</code>.</p>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
