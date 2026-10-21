<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10233f">
    <title>Chats | Chatbot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../public/assets/css/app.css') ?>">
    <script src="assets/js/chats.js?v=<?= (int) filemtime(__DIR__ . '/../public/assets/js/chats.js') ?>" defer></script>
</head>
<body data-chat-destination="<?= $escape($selected) ?>" data-chat-last-id="<?= $thread === [] ? 0 : (int) $thread[count($thread) - 1]['id'] ?>">
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
        <a class="nav-item is-active" href="chats.php" aria-current="page">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v10.5H10l-4.3 3.4v-3.4H4Z"/><path d="M8 9.7h8M8 12.7h5"/></svg>
            <span>Chats</span>
            <span class="nav-badge" id="chat-nav-badge" <?= $chatUnread > 0 ? '' : 'hidden' ?>><?= (int) $chatUnread ?></span>
        </a>
        <?= authAdminNav() ?>
        <?= authUserBox() ?>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">Agenda <span>/</span> <strong>Chats</strong></div>
            <div class="topbar-meta"><span class="live-dot"></span> Mensajes de WhatsApp</div>
        </header>

        <div class="page-content chat-page">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">GESTIONALO</p>
                    <h1>Chats con pacientes</h1>
                    <p class="heading-copy">Responde a quienes contestaron un recordatorio. Las respuestas llegan por el webhook configurado en Gestionalo.</p>
                </div>
            </section>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert" role="alert"><strong>Sin conexión</strong><span><?= $escape($errorMessage) ?></span></div>
            <?php endif; ?>
            <?php if (is_array($notification)): ?>
                <div class="notification <?= ($notification['type'] ?? '') === 'success' ? 'notification-success' : 'notification-error' ?>" role="status"><?= $escape($notification['message'] ?? '') ?></div>
            <?php endif; ?>
            <?php if (!$chatbotConfigured): ?>
                <div class="api-config-note" role="status">Puedes ver los chats; para responder, configura CHATBOT_LINE_KEY en config/local.php.</div>
            <?php endif; ?>

            <section class="chat-shell">
                <div class="chat-list-panel">
                    <div class="chat-list-heading">
                        <h2>Conversaciones</h2>
                        <span class="result-count"><?= number_format(count($conversations), 0, ',', '.') ?></span>
                    </div>
                    <div class="chat-list" id="chat-list">
                        <?php if ($conversations === []): ?>
                            <div class="empty-state chat-empty"><span class="empty-icon">⌕</span><strong>Sin conversaciones</strong><small>Aquí aparecerán los pacientes que respondan un mensaje.</small></div>
                        <?php else: ?>
                            <?php foreach ($conversations as $conversation): ?>
                                <?php
                                $isSelected = $conversation['destination'] === $selected;
                                $preview = mb_strimwidth((string) $conversation['last_body'], 0, 70, '…', 'UTF-8');
                                $displayName = $conversation['patient_name'] !== null && $conversation['patient_name'] !== ''
                                    ? $conversation['patient_name']
                                    : $conversation['destination'];
                                ?>
                                <a class="chat-item<?= $isSelected ? ' is-active' : '' ?><?= ((int) $conversation['unread'] > 0) ? ' is-unread' : '' ?>"
                                   href="chats.php?telefono=<?= rawurlencode((string) $conversation['destination']) ?>"
                                   data-destination="<?= $escape((string) $conversation['destination']) ?>">
                                    <span class="chat-item-top">
                                        <strong><?= $escape($displayName) ?></strong>
                                        <small><?= $escape($formatTime((string) $conversation['last_at'])) ?></small>
                                    </span>
                                    <span class="chat-item-bottom">
                                        <small><?= $conversation['last_direction'] === 'outbound' ? 'Tú: ' : '' ?><?= $escape($preview) ?></small>
                                        <?php if ((int) $conversation['unread'] > 0): ?><span class="chat-badge"><?= (int) $conversation['unread'] ?></span><?php endif; ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="chat-thread-panel">
                    <?php if ($selected === ''): ?>
                        <div class="empty-state chat-empty-thread"><span class="empty-icon">💬</span><strong>Elige una conversación</strong><small>Selecciona un chat de la izquierda para ver los mensajes.</small></div>
                    <?php else: ?>
                        <div class="chat-thread-heading">
                            <div><strong><?= $escape($selectedName ?: $selected) ?></strong><small><?= $escape($selected) ?></small></div>
                        </div>
                        <div class="chat-thread" id="chat-thread">
                            <?php if ($thread === []): ?>
                                <div class="empty-state chat-empty-thread"><small>Todavía no hay mensajes con este celular.</small></div>
                            <?php endif; ?>
                            <?php foreach ($thread as $item): ?>
                                <?php $isOutbound = $item['direction'] === 'outbound'; ?>
                                <div class="chat-bubble <?= $isOutbound ? 'bubble-outbound' : 'bubble-inbound' ?>" data-message-id="<?= (int) $item['id'] ?>">
                                    <p><?= nl2br($escape($item['body']), false) ?></p>
                                    <small>
                                        <?= $escape((new DateTimeImmutable($item['created_at']))->format('d/m H:i')) ?>
                                        <?php if ($isOutbound): ?>
                                            · <?= match ($item['status']) { 'sent' => 'Enviado', 'failed' => 'Falló', default => 'Enviando…' } ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form class="chat-composer" method="post" action="chats.php?action=send" enctype="multipart/form-data" id="chat-composer-form">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="destination" value="<?= $escape($selected) ?>">
                            <textarea name="message" maxlength="1000" placeholder="Escribe una respuesta…" required <?= $chatbotConfigured ? '' : 'disabled' ?>></textarea>
                            <input type="file" name="attachment" accept="image/png,image/jpeg" aria-label="Imagen opcional, PNG o JPG hasta 5 MB" title="Imagen opcional (PNG o JPG, máx. 5 MB)" <?= $chatbotConfigured ? '' : 'disabled' ?>>
                            <button class="button-primary" type="submit" <?= $chatbotConfigured ? '' : 'disabled' ?>>Enviar</button>
                        </form>
                        <?php if (!$chatbotConfigured): ?><small class="api-required">Respuesta desactivada hasta configurar la API de Gestionalo.</small><?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
