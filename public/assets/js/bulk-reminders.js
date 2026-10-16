(() => {
    const selectAll = document.querySelector('#bulk-select-all');
    const rowChecks = () => Array.from(document.querySelectorAll('.bulk-row-check'));
    const visibleRowChecks = () => rowChecks().filter((box) => !box.closest('tr')?.hasAttribute('hidden'));

    // Filtro por nombre: solo oculta filas; no desmarca pacientes ya seleccionados
    // fuera de la vista, para que el total a enviar nunca cambie por error al filtrar.
    const nameFilter = document.querySelector('#bulk-name-filter');
    const tableBody = document.querySelector('#bulk-table-body');
    const noMatchRow = document.querySelector('#bulk-no-match-row');
    const normalize = (value) => String(value || '').toLocaleLowerCase('es-PE')
        .normalize('NFD').replace(/[̀-ͯ]/g, '');

    const applyNameFilter = () => {
        if (!tableBody) return;
        const query = normalize(nameFilter?.value || '').trim();
        let visibleCount = 0;
        Array.from(tableBody.querySelectorAll('tr[data-patient-name]')).forEach((row) => {
            const matches = query === '' || normalize(row.dataset.patientName).includes(query);
            row.hidden = !matches;
            if (matches) visibleCount++;
        });
        if (noMatchRow) noMatchRow.hidden = visibleCount !== 0;
        if (selectAll) {
            const visible = visibleRowChecks();
            selectAll.checked = visible.length > 0 && visible.every((box) => box.checked);
        }
    };
    nameFilter?.addEventListener('input', applyNameFilter);
    const countLabel = document.querySelector('#bulk-selected-count');
    const sendButton = document.querySelector('#bulk-send-button');
    const form = document.querySelector('#bulk-send-form');

    const updateCount = () => {
        const checked = rowChecks().filter((box) => box.checked).length;
        if (countLabel) countLabel.textContent = String(checked);
        if (sendButton) sendButton.disabled = checked === 0 && !sendButton.hasAttribute('data-keep-enabled');
    };

    // Afecta solo a las filas visibles: si hay un filtro aplicado, no toca
    // pacientes ocultos que la persona no está revisando en ese momento.
    selectAll?.addEventListener('change', () => {
        visibleRowChecks().forEach((box) => { box.checked = selectAll.checked; });
        updateCount();
    });
    rowChecks().forEach((box) => box.addEventListener('change', updateCount));
    updateCount();

    form?.addEventListener('submit', (event) => {
        const checked = rowChecks().filter((box) => box.checked).length;
        if (checked === 0) {
            event.preventDefault();
            window.alert('Selecciona al menos un paciente.');
            return;
        }
        if (!window.confirm(`¿Enviar el recordatorio a ${checked} paciente(s) mediante Gestionalo? Esta acción no se puede deshacer.`)) {
            event.preventDefault();
        }
    });
})();
