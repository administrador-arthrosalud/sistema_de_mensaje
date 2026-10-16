(() => {
    const body = document.body;
    const selectedDestination = body.dataset.chatDestination || '';
    let lastMessageId = parseInt(body.dataset.chatLastId || '0', 10) || 0;
    let listVersion = '';

    const escapeHtml = (value) => String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const navBadge = document.querySelector('#chat-nav-badge');
    const setNavBadge = (count) => {
        if (!navBadge) return;
        if (count > 0) {
            navBadge.hidden = false;
            navBadge.textContent = String(count);
        } else {
            navBadge.hidden = true;
        }
    };

    const timeOnly = (value) => {
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return '';
        return date.toLocaleString('es-PE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    };

    const renderList = (conversations) => {
        const list = document.querySelector('#chat-list');
        if (!list) return;
        if (conversations.length === 0) {
            list.innerHTML = '<div class="empty-state chat-empty"><span class="empty-icon">⌕</span><strong>Sin conversaciones</strong><small>Aquí aparecerán los pacientes que respondan un mensaje.</small></div>';
            return;
        }
        list.innerHTML = conversations.map((conversation) => {
            const isActive = conversation.destination === selectedDestination;
            const hasUnread = Number(conversation.unread) > 0;
            const name = conversation.patient_name && conversation.patient_name !== ''
                ? conversation.patient_name
                : conversation.destination;
            const prefix = conversation.last_direction === 'outbound' ? 'Tú: ' : '';
            const preview = String(conversation.last_body || '');
            const shortPreview = preview.length > 70 ? preview.slice(0, 70) + '…' : preview;
            const badge = hasUnread ? `<span class="chat-badge">${conversation.unread}</span>` : '';
            return `<a class="chat-item${isActive ? ' is-active' : ''}${hasUnread ? ' is-unread' : ''}" href="chats.php?telefono=${encodeURIComponent(conversation.destination)}" data-destination="${escapeHtml(conversation.destination)}">
                <span class="chat-item-top"><strong>${escapeHtml(name)}</strong><small>${escapeHtml(timeOnly(conversation.last_at))}</small></span>
                <span class="chat-item-bottom"><small>${escapeHtml(prefix)}${escapeHtml(shortPreview)}</small>${badge}</span>
            </a>`;
        }).join('');
    };

    const pollList = async () => {
        try {
            const response = await fetch('chat-messages.php', { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (!data.version) return;
            setNavBadge(data.unread_total || 0);
            if (data.version === listVersion) return;
            listVersion = data.version;
            renderList(data.conversations || []);
        } catch {
            // Un problema temporal de red o de la base de datos se reintenta en el siguiente sondeo.
        }
    };

    const appendMessage = (item) => {
        const thread = document.querySelector('#chat-thread');
        if (!thread) return;
        const isOutbound = item.direction === 'outbound';
        const statusLabel = item.status === 'sent' ? 'Enviado' : (item.status === 'failed' ? 'Falló' : 'Enviando…');
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (isOutbound ? 'bubble-outbound' : 'bubble-inbound');
        bubble.dataset.messageId = String(item.id);
        const paragraph = document.createElement('p');
        paragraph.innerHTML = escapeHtml(item.body).replace(/\n/g, '<br>');
        const meta = document.createElement('small');
        meta.textContent = timeOnly(item.created_at) + (isOutbound ? ' · ' + statusLabel : '');
        bubble.append(paragraph, meta);
        thread.appendChild(bubble);
        thread.scrollTop = thread.scrollHeight;
    };

    const pollThread = async () => {
        if (!selectedDestination) return;
        try {
            const response = await fetch(`chat-messages.php?destination=${encodeURIComponent(selectedDestination)}&after_id=${lastMessageId}`, {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return;
            const data = await response.json();
            (data.messages || []).forEach((item) => {
                appendMessage(item);
                lastMessageId = Math.max(lastMessageId, parseInt(item.id, 10) || 0);
            });
        } catch {
            // Igual que en pollList: se reintenta en el siguiente sondeo.
        }
    };

    // Validación previa de la imagen opcional; PHP vuelve a validarla al recibirla.
    const attachment = document.querySelector('#chat-composer-form input[name="attachment"]');
    attachment?.addEventListener('change', () => {
        const file = attachment.files?.[0];
        if (!file) return;
        if (!['image/png', 'image/jpeg'].includes(file.type)) {
            window.alert('Solo se permiten imágenes PNG o JPG.');
            attachment.value = '';
        } else if (file.size > 5 * 1024 * 1024) {
            window.alert('La imagen debe pesar como máximo 5 MB.');
            attachment.value = '';
        }
    });

    const thread = document.querySelector('#chat-thread');
    if (thread) thread.scrollTop = thread.scrollHeight;

    window.setInterval(pollList, 4000);
    window.setInterval(pollThread, 3000);
})();
