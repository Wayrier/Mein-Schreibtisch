(function () {
    const appointmentStatusLabels = {
        open: 'Offen',
        done: 'Erledigt',
        cancelled: 'Abgebrochen'
    };
    const createAppointmentForm = document.getElementById('create-appointment-form');
    const createAppointmentMessage = document.getElementById('create-appointment-message');
    const editAppointmentForm = document.getElementById('edit-appointment-form');
    const editAppointmentMessage = document.getElementById('edit-appointment-message');
    const cancelAppointmentEditButton = document.getElementById('cancel-appointment-edit');
    const appointmentsTable = document.getElementById('appointments-table');
    const appointmentsTableBody = document.getElementById('appointments-table-body');
    const emptyAppointmentsMessage = document.getElementById('empty-appointments-message');
    const appointmentModals = document.querySelectorAll('.appointment-modal');
    const appointmentModalTriggers = document.querySelectorAll('[data-appointment-modal-target]');
    const appointmentModalCloseButtons = document.querySelectorAll('[data-appointment-modal-close]');
    const createAppointmentModal = document.getElementById('appointment-create-modal');
    const editAppointmentModal = document.getElementById('appointment-edit-modal');

    if (!createAppointmentForm || !editAppointmentForm || !appointmentsTableBody) {
        return;
    }

    function csrfToken() {
        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    function setMessage(element, message, isError = false) {
        if (!element) {
            return;
        }

        element.textContent = message;
        element.classList.remove('is-success', 'is-error');

        if (message !== '') {
            element.classList.add(isError ? 'is-error' : 'is-success');
        }
    }

    function openAppointmentModal(modal) {
        if (!modal) {
            return;
        }

        appointmentModals.forEach((currentModal) => {
            currentModal.hidden = currentModal !== modal;
        });

        modal.hidden = false;
        document.body.classList.add('modal-open');
        modal.querySelectorAll('.status-message').forEach((message) => setMessage(message, ''));

        const firstField = modal.querySelector('input:not([type="hidden"]), textarea, select') || modal.querySelector('button');

        if (firstField) {
            firstField.focus();
        }
    }

    function closeAppointmentModal(modal) {
        if (!modal) {
            return;
        }

        modal.hidden = true;

        if (!Array.from(appointmentModals).some((currentModal) => !currentModal.hidden)) {
            document.body.classList.remove('modal-open');
        }
    }

    function closeAllAppointmentModals() {
        appointmentModals.forEach(closeAppointmentModal);
    }

    function createHiddenInput(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }

    function fillAppointmentRow(row, appointment) {
        row.dataset.appointmentId = appointment.id;
        row.dataset.subject = appointment.subject;
        row.dataset.startDateInput = appointment.start_date_input || appointment.due_date_input;
        row.dataset.startDateSort = appointment.start_date_sort || appointment.due_date_sort;
        row.dataset.dueDateInput = appointment.due_date_input;
        row.dataset.dueDateSort = appointment.due_date_sort;
        row.dataset.content = appointment.content;
        row.dataset.status = appointment.status;
        row.querySelector('.appointment-subject-cell').textContent = appointment.subject;
        row.querySelector('.appointment-start-date-cell').textContent = appointment.start_date || appointment.due_date;
        row.querySelector('.appointment-due-date-cell').textContent = appointment.due_date;
        row.querySelector('.appointment-content-cell').textContent = appointment.content;
        row.querySelector('.appointment-status-cell').textContent = appointmentStatusLabels[appointment.status] || appointment.status;
    }

    function placeAppointmentRow(row) {
        const dueDateSort = Number(row.dataset.dueDateSort);
        const nextRow = Array
            .from(appointmentsTableBody.children)
            .filter((currentRow) => currentRow !== row)
            .find((currentRow) => Number(currentRow.dataset.dueDateSort) > dueDateSort);

        appointmentsTableBody.insertBefore(row, nextRow || null);
    }

    function createAppointmentRow(appointment) {
        const row = document.createElement('tr');
        row.dataset.appointmentId = appointment.id;

        const subjectCell = document.createElement('td');
        subjectCell.className = 'appointment-subject-cell';

        const startDateCell = document.createElement('td');
        startDateCell.className = 'appointment-start-date-cell';

        const dueDateCell = document.createElement('td');
        dueDateCell.className = 'appointment-due-date-cell';

        const contentCell = document.createElement('td');
        contentCell.className = 'appointment-content-cell';

        const statusCell = document.createElement('td');
        statusCell.className = 'appointment-status-cell';

        const actionsCell = document.createElement('td');
        actionsCell.className = 'action-cell';
        const actionGroup = document.createElement('div');
        actionGroup.className = 'action-group';

        const editLink = document.createElement('a');
        editLink.href = `edit_appointment.php?id=${encodeURIComponent(appointment.id)}`;
        editLink.className = 'button-link ajax-edit-appointment-link';
        editLink.textContent = '\u270F\uFE0F Bearbeiten';

        const uploadLink = document.createElement('a');
        uploadLink.href = `upload_appointment_file.php?id=${encodeURIComponent(appointment.id)}`;
        uploadLink.className = 'button-link';
        uploadLink.textContent = '\u{1F4CE} Datei anh\u00e4ngen';

        const convertForm = document.createElement('form');
        convertForm.method = 'POST';
        convertForm.action = 'convert_appointment_to_note.php';
        convertForm.className = 'action-inline';

        const convertButton = document.createElement('button');
        convertButton.type = 'submit';
        convertButton.textContent = '\u21AA Als Notiz';
        convertButton.className = 'button-link';

        convertForm.append(
            createHiddenInput('id', appointment.id),
            createHiddenInput('csrf_token', csrfToken()),
            convertButton
        );

        const deleteForm = document.createElement('form');
        deleteForm.method = 'POST';
        deleteForm.action = 'delete_appointment.php';
        deleteForm.className = 'action-inline ajax-delete-form';
        deleteForm.dataset.ajaxUrl = 'delete_appointment_ajax.php';

        const deleteButton = document.createElement('button');
        deleteButton.type = 'submit';
        deleteButton.textContent = '\u{1F5D1}\uFE0F L\u00f6schen';
        deleteButton.className = 'button-link button-link-danger';

        deleteForm.append(
            createHiddenInput('id', appointment.id),
            createHiddenInput('csrf_token', csrfToken()),
            deleteButton
        );

        const appointmentActionPair = document.createElement('div');
        appointmentActionPair.className = 'appointment-action-pair';
        appointmentActionPair.append(convertForm, deleteForm);

        actionGroup.append(editLink, uploadLink, appointmentActionPair);
        actionsCell.append(actionGroup);
        row.append(subjectCell, startDateCell, dueDateCell, contentCell, statusCell, actionsCell);
        fillAppointmentRow(row, appointment);

        return row;
    }

    function hideEditAppointmentForm() {
        editAppointmentForm.reset();
        setMessage(editAppointmentMessage, '');
        closeAppointmentModal(editAppointmentModal);
    }

    function showEditAppointmentForm(row) {
        editAppointmentForm.elements.id.value = row.dataset.appointmentId;
        editAppointmentForm.elements.subject.value = row.dataset.subject || '';
        editAppointmentForm.elements.start_date.value = row.dataset.startDateInput || row.dataset.dueDateInput || '';
        editAppointmentForm.elements.due_date.value = row.dataset.dueDateInput || '';
        editAppointmentForm.elements.content.value = row.dataset.content || '';
        editAppointmentForm.elements.status.value = row.dataset.status || 'open';
        openAppointmentModal(editAppointmentModal);
        setMessage(editAppointmentMessage, '');
        editAppointmentForm.elements.subject.focus();
    }

    createAppointmentForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submitButton = createAppointmentForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        setMessage(createAppointmentMessage, '');

        try {
            const response = await fetch(createAppointmentForm.dataset.ajaxUrl, {
                method: 'POST',
                body: new FormData(createAppointmentForm),
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await response.json();

            if (data.success) {
                const row = createAppointmentRow(data.appointment);
                placeAppointmentRow(row);
                appointmentsTable.classList.remove('is-hidden');
                emptyAppointmentsMessage.classList.add('is-hidden');
                createAppointmentForm.reset();
                createAppointmentForm.elements.start_date.value = createAppointmentForm.elements.start_date.dataset.defaultStartDate || '';
                createAppointmentForm.elements.due_date.value = createAppointmentForm.elements.due_date.dataset.defaultDueDate || '';
                setMessage(createAppointmentMessage, 'Termin gespeichert.');
                window.setTimeout(() => closeAppointmentModal(createAppointmentModal), 450);
            } else {
                setMessage(createAppointmentMessage, data.message || 'Fehler beim Speichern.', true);
            }
        } catch (error) {
            setMessage(createAppointmentMessage, 'Fehler beim Speichern.', true);
        } finally {
            submitButton.disabled = false;
        }
    });

    editAppointmentForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submitButton = editAppointmentForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        setMessage(editAppointmentMessage, '');

        try {
            const response = await fetch(editAppointmentForm.dataset.ajaxUrl, {
                method: 'POST',
                body: new FormData(editAppointmentForm),
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await response.json();

            if (data.success) {
                const row = appointmentsTableBody.querySelector(`[data-appointment-id="${data.appointment.id}"]`);

                if (row) {
                    fillAppointmentRow(row, data.appointment);
                    placeAppointmentRow(row);
                }

                setMessage(editAppointmentMessage, 'Termin aktualisiert.');
                window.setTimeout(() => closeAppointmentModal(editAppointmentModal), 450);
            } else {
                setMessage(editAppointmentMessage, data.message || 'Fehler beim Speichern.', true);
            }
        } catch (error) {
            setMessage(editAppointmentMessage, 'Fehler beim Speichern.', true);
        } finally {
            submitButton.disabled = false;
        }
    });

    cancelAppointmentEditButton.addEventListener('click', hideEditAppointmentForm);

    appointmentModalTriggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            const modal = document.getElementById(trigger.dataset.appointmentModalTarget || '');

            if (!modal) {
                return;
            }

            event.preventDefault();
            openAppointmentModal(modal);
        });
    });

    appointmentModalCloseButtons.forEach((button) => {
        button.addEventListener('click', () => closeAppointmentModal(button.closest('.appointment-modal')));
    });

    appointmentModals.forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeAppointmentModal(modal);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllAppointmentModals();
        }
    });

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const editLink = event.target.closest('.ajax-edit-appointment-link');

        if (!editLink) {
            return;
        }

        event.preventDefault();

        const row = editLink.closest('tr');

        if (row) {
            showEditAppointmentForm(row);
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.classList.contains('ajax-delete-form')) {
            return;
        }

        event.preventDefault();

        if (!confirm('Wirklich l\u00f6schen?')) {
            return;
        }

        try {
            const response = await fetch(form.dataset.ajaxUrl, {
                method: 'POST',
                body: new FormData(form)
            });
            const data = await response.json();

            if (data.success) {
                const row = form.closest('tr');
                const deletedId = row ? row.dataset.appointmentId : '';

                if (row) {
                    row.remove();
                }

                if (editAppointmentForm.elements.id.value === deletedId) {
                    hideEditAppointmentForm();
                }

                if (appointmentsTableBody.children.length === 0) {
                    appointmentsTable.classList.add('is-hidden');
                    emptyAppointmentsMessage.classList.remove('is-hidden');
                }
            } else {
                alert('Fehler beim L\u00f6schen');
            }
        } catch (error) {
            alert('Fehler beim L\u00f6schen');
        }
    });
})();
