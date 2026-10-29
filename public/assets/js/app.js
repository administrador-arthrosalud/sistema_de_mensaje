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
            if (response.status === 401) { window.location.href = 'login.php'; return; }
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

    // Texto inicial del recordatorio. La persona lo revisa y puede editarlo antes de enviar.
    const CLINIC_NAME = 'Clínica Arthrosalud';
    // appointment.sede_nombre solo trae "LIMA" o "LINCE" (ver AppointmentRepository); la
    // dirección completa se arma aquí porque no viene de la base de datos.
    const SEDE_ADDRESSES = {
        LIMA: 'Jr. Sánchez Pinillos 188, Lima 15082',
        LINCE: 'Av. P.º de la República 2772, Lince 15046',
        'LINCE - CONSULTORIO 3': 'Jr. Joaquín Bernal 344, Lince',
    };
    const SEDE_MAP_LINKS = {
        LIMA: 'https://maps.app.goo.gl/uuT3UsKLBS57XV4Z9',
        LINCE: 'https://maps.app.goo.gl/GJPnx7zYda94qndy5',
        'LINCE - CONSULTORIO 3': 'https://maps.app.goo.gl/SSGKyWAWZuA3S9xC7',
    };
    const sedeLine = (sede) => {
        if (!sede) return null;
        const key = String(sede).trim().toUpperCase();
        const address = SEDE_ADDRESSES[key];
        return address ? `📍*Sede ${key}:* ${address}\n🗺️*Mapa:* ${SEDE_MAP_LINKS[key]}` : `📍*Sede:* ${titleCase(sede)}`;
    };
    const lowerWords = new Set(['de', 'del', 'la', 'las', 'los', 'y']);
    const titleCase = (value) => String(value || '').trim().toLocaleLowerCase('es-PE').split(/\s+/)
        .map((word, index) => (index > 0 && lowerWords.has(word)) ? word : word.charAt(0).toLocaleUpperCase('es-PE') + word.slice(1))
        .join(' ');
    const time12h = (value) => {
        const match = /^(\d{1,2}):(\d{2})/.exec(String(value || ''));
        if (!match) return String(value || '');
        const hours = Number(match[1]);
        return `${hours % 12 || 12}:${match[2]} ${hours < 12 ? 'a. m.' : 'p. m.'}`;
    };
    // Sin las opciones numeradas (1/2/3) hasta que Gestionalo confirme el
    // webhook de respuestas entrantes: pedirlas sería engañoso si nadie las lee.
    // Ver docs/operacion-local.md, sección "Mensajes entrantes (webhook)".
    const buildReminderMessage = ({ patient, date, time, doctor, sede }) => [
        `*Hola, ${titleCase(patient)}.*`,
        `Le recordamos que tiene una cita programada en la ${CLINIC_NAME}.`,
        '',
        `📅*Fecha:* ${date}`,
        `🕐*Hora:* ${time12h(time)}`,
        ...(doctor ? [`🩺*Médico:* Dr(a). ${titleCase(doctor)}`] : []),
        ...(sedeLine(sede) ? [sedeLine(sede)] : []),
    ].join('\n');

    // Validación previa de la imagen opcional; PHP vuelve a validarla al recibirla.
    document.querySelectorAll('input[type="file"][name="attachment"]').forEach((input) => {
        input.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) return;
            if (!['image/png', 'image/jpeg'].includes(file.type)) {
                window.alert('Solo se permiten imágenes PNG o JPG.');
                input.value = '';
            } else if (file.size > 5 * 1024 * 1024) {
                window.alert('La imagen debe pesar como máximo 5 MB.');
                input.value = '';
            }
        });
    });

    reloadAppointments?.addEventListener('click', () => window.location.reload());
    window.setInterval(checkAppointmentUpdates, 3000);

    // La búsqueda de texto se envía con Enter (no al escribir, para no recargar a
    // mitad de palabra). Sede y fechas son selecciones puntuales, no texto: se
    // envían solas en cuanto cambian, sin necesidad de un botón "Filtrar".
    document.querySelectorAll('#filter-form select[name="sede"], #filter-form input[name="desde"], #filter-form input[name="hasta"]')
        .forEach((field) => field.addEventListener('change', () => field.form?.requestSubmit()));

    const manualDialog = document.querySelector('#manual-reminder-dialog');
    const manualForm = document.querySelector('#send-manual-reminder-form');
    const manualContactId = document.querySelector('#manual-contact-id');
    const manualDestination = document.querySelector('#manual-destination');
    const manualMessage = document.querySelector('#manual-message');
    const closeManualDialog = document.querySelector('#close-manual-reminder');

    if (manualDialog && manualForm && manualContactId && manualDestination && manualMessage) {
        document.querySelectorAll('[data-open-manual-reminder]').forEach((button) => {
            button.addEventListener('click', () => {
                manualForm.reset();
                manualContactId.value = button.dataset.contactId || '';
                manualDestination.value = button.dataset.destination || '';
                manualMessage.value = buildReminderMessage({
                    patient: button.dataset.patient,
                    date: button.dataset.date,
                    time: button.dataset.time,
                    doctor: button.dataset.doctor,
                    sede: button.dataset.sede,
                });
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
            reminderForm.reset();
            appointmentId.value = button.dataset.appointmentId || '';
            messageField.value = buildReminderMessage({
                patient: button.dataset.patient,
                date: button.dataset.date,
                time: button.dataset.time,
                doctor: button.dataset.doctor,
                sede: button.dataset.sede,
            });
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
