(() => {
    const liveNotice = document.querySelector('#live-update-notice');
    const reloadAppointments = document.querySelector('#reload-appointments');
    const reminderDialog = document.querySelector('#reminder-dialog');
    let appointmentVersion = document.body.dataset.appointmentsVersion || '';

    const filtersHaveUnsavedChanges = () => {
        const form = document.querySelector('#filter-form');
        if (!form) return false;

        const submitted = new URLSearchParams(window.location.search);
        return ['q', 'desde', 'hasta'].some((name) => {
            const field = form.elements.namedItem(name);
            return field && field.value !== (submitted.get(name) || '');
        });
    };

    const checkAppointmentUpdates = async () => {
        try {
            const response = await fetch('appointments-version.php', {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data.version) return;
            if (!appointmentVersion) {
                appointmentVersion = data.version;
                return;
            }
            if (data.version === appointmentVersion) return;

            if (reminderDialog?.open || filtersHaveUnsavedChanges()) {
                if (liveNotice) liveNotice.hidden = false;
                return;
            }
            window.location.reload();
        } catch {
            // A temporary local database/network issue is retried on the next poll.
        }
    };

    reloadAppointments?.addEventListener('click', () => window.location.reload());
    window.setInterval(checkAppointmentUpdates, 3000);

    const form = document.querySelector('#filter-form');
    const search = form?.querySelector('input[type="search"]');

    if (form && search) {
        let timer;
        search.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => form.requestSubmit(), 450);
        });
    }

    const manualDialog = document.querySelector('#manual-reminder-dialog');
    const manualForm = document.querySelector('#send-manual-reminder-form');
    const manualContactId = document.querySelector('#manual-contact-id');
    const manualDestination = document.querySelector('#manual-destination');
    const manualMessage = document.querySelector('#manual-message');
    const closeManualDialog = document.querySelector('#close-manual-reminder');

    if (manualDialog && manualForm && manualContactId && manualDestination && manualMessage) {
        document.querySelectorAll('[data-open-manual-reminder]').forEach((button) => {
            button.addEventListener('click', () => {
                const patient = button.dataset.patient || '';
                const date = button.dataset.date || '';
                const time = button.dataset.time || '';
                manualForm.reset();
                manualContactId.value = button.dataset.contactId || '';
                manualDestination.value = button.dataset.destination || '';
                manualMessage.value = `Hola ${patient}, te recordamos tu cita médica programada para el ${date} a las ${time}. Si necesitas reprogramar, comunícate con la clínica.`;
                manualDialog.showModal();
            });
        });
        closeManualDialog?.addEventListener('click', () => manualDialog.close());
        manualDialog.addEventListener('click', (event) => {
            if (event.target === manualDialog) manualDialog.close();
        });
        manualForm.addEventListener('submit', (event) => {
            if (!window.confirm('Enviar este recordatorio al celular indicado mediante Gestionalo?')) {
                event.preventDefault();
            }
        });
    }

    document.querySelectorAll('[data-delete-manual]').forEach((deleteForm) => {
        deleteForm.addEventListener('submit', (event) => {
            if (!window.confirm('Quitar este contacto de la lista temporal? Los envíos anteriores se conservarán.')) {
                event.preventDefault();
            }
        });
    });

    const dialog = document.querySelector('#reminder-dialog');
    const reminderForm = document.querySelector('#send-reminder-form');
    const appointmentId = document.querySelector('#reminder-appointment-id');
    const messageField = document.querySelector('#reminder-message');
    const closeButton = document.querySelector('#close-reminder');

    if (!dialog || !reminderForm || !appointmentId || !messageField) return;

    document.querySelectorAll('[data-open-reminder]').forEach((button) => {
        button.addEventListener('click', () => {
            const patient = button.dataset.patient || '';
            const date = button.dataset.date || '';
            const time = button.dataset.time || '';
            reminderForm.reset();
            appointmentId.value = button.dataset.appointmentId || '';
            messageField.value = `Hola ${patient}, te recordamos tu cita médica programada para el ${date} a las ${time}. Si necesitas reprogramar, comunícate con la clínica.`;
            dialog.showModal();
        });
    });

    closeButton?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    reminderForm.addEventListener('submit', (event) => {
        if (!window.confirm('Enviar el texto editado a chatbot.gestionalo.pe para el celular indicado?')) {
            event.preventDefault();
        }
    });
})();
