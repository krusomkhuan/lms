(() => {
    const form = document.getElementById('attendance-form');
    if (!form) return;
    const rows = [...form.querySelectorAll('[data-attendance-row]')];
    const headers = [...form.querySelectorAll('[data-attendance-all]')];
    const activeRows = rows.filter(row => !row.querySelector('[data-attendance-value]').disabled);
    const updateHeaders = () => {
        headers.forEach(header => {
            const count = activeRows.filter(row => row.querySelector('[data-attendance-value]').value === header.dataset.attendanceAll).length;
            header.checked = activeRows.length > 0 && count === activeRows.length;
            header.indeterminate = count > 0 && count < activeRows.length;
        });
    };
    const selectStatus = (row, status) => {
        row.querySelector('[data-attendance-value]').value = status;
        row.querySelectorAll('[data-attendance-status]').forEach(box => {
            box.checked = box.dataset.attendanceStatus === status;
        });
    };
    rows.forEach(row => {
        row.querySelectorAll('[data-attendance-status]').forEach(box => {
            box.addEventListener('change', () => {
                // Keep exactly one status, including when the selected box is clicked again.
                selectStatus(row, box.dataset.attendanceStatus);
                updateHeaders();
            });
        });
    });
    headers.forEach(header => header.addEventListener('change', () => {
        activeRows.forEach(row => selectStatus(row, header.dataset.attendanceAll));
        updateHeaders();
    }));
    updateHeaders();
})();
