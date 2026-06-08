(function () {
    const createNoteForm = document.getElementById('create-note-form');
    const createNoteMessage = document.getElementById('create-note-message');
    const editNoteForm = document.getElementById('edit-note-form');
    const editNoteMessage = document.getElementById('edit-note-message');
    const cancelNoteEditButton = document.getElementById('cancel-note-edit');
    const notesBoard = document.getElementById('notes-board');
    const emptyNotesMessage = document.getElementById('empty-notes-message');
    const noFilteredNotesMessage = document.getElementById('no-filtered-notes-message');
    const notesFilter = document.getElementById('notes-filter');
    const notesCount = document.getElementById('notes-count');
    const noteModals = document.querySelectorAll('.note-modal');
    const noteModalTriggers = document.querySelectorAll('[data-note-modal-target]');
    const noteModalCloseButtons = document.querySelectorAll('[data-note-modal-close]');
    const createNoteModal = document.getElementById('note-create-modal');
    const editNoteModal = document.getElementById('note-edit-modal');

    if (!createNoteForm || !editNoteForm || !notesBoard) {
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

    function openNoteModal(modal) {
        if (!modal) {
            return;
        }

        noteModals.forEach((currentModal) => {
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

    function closeNoteModal(modal) {
        if (!modal) {
            return;
        }

        modal.hidden = true;

        if (!Array.from(noteModals).some((currentModal) => !currentModal.hidden)) {
            document.body.classList.remove('modal-open');
        }
    }

    function closeAllNoteModals() {
        noteModals.forEach(closeNoteModal);
    }

    function createHiddenInput(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }

    function noteAccentClass(id) {
        const accents = ['note-accent-blue', 'note-accent-green', 'note-accent-yellow', 'note-accent-red'];
        return accents[Number(id || 0) % accents.length];
    }

    function fillNoteCard(card, note) {
        card.dataset.noteId = note.id;
        card.dataset.title = note.title || '';
        card.dataset.content = note.content || '';
        card.classList.remove('note-accent-blue', 'note-accent-green', 'note-accent-yellow', 'note-accent-red');
        card.classList.add(noteAccentClass(note.id));
        card.querySelector('.note-title-cell').textContent = note.title || 'Ohne Titel';
        card.querySelector('.note-content-cell').textContent = note.content || 'Noch kein Inhalt.';

        const createdElement = card.querySelector('.note-created-cell');
        createdElement.textContent = note.created_relative || createdElement.textContent || note.created_at || 'gerade eben';
        createdElement.title = note.created_at || '';
    }

    function createNoteCard(note) {
        const card = document.createElement('article');
        card.className = `note-card ${noteAccentClass(note.id)}`;
        card.dataset.noteId = note.id;

        const top = document.createElement('div');
        top.className = 'note-card-top';

        const dot = document.createElement('span');
        dot.className = 'note-card-dot';
        dot.setAttribute('aria-hidden', 'true');

        const created = document.createElement('span');
        created.className = 'note-created-cell';
        top.append(dot, created);

        const title = document.createElement('h3');
        title.className = 'note-title-cell';

        const content = document.createElement('p');
        content.className = 'note-content-cell';

        const actionGroup = document.createElement('div');
        actionGroup.className = 'note-card-actions action-group';

        const editLink = document.createElement('a');
        editLink.href = `edit_note.php?id=${encodeURIComponent(note.id)}`;
        editLink.className = 'button-link ajax-edit-note-link';
        editLink.textContent = '\u270F\uFE0F Bearbeiten';

        const uploadLink = document.createElement('a');
        uploadLink.href = `upload_note_file.php?id=${encodeURIComponent(note.id)}`;
        uploadLink.className = 'button-link';
        uploadLink.textContent = '\u{1F4CE} Datei anh\u00e4ngen';

        const convertLink = document.createElement('a');
        convertLink.href = `convert_note_to_appointment.php?id=${encodeURIComponent(note.id)}`;
        convertLink.className = 'button-link';
        convertLink.textContent = '\u21AA Als Termin';

        const deleteForm = document.createElement('form');
        deleteForm.method = 'POST';
        deleteForm.action = 'delete_note.php';
        deleteForm.className = 'action-inline ajax-delete-form';
        deleteForm.dataset.ajaxUrl = 'delete_note_ajax.php';

        const deleteButton = document.createElement('button');
        deleteButton.type = 'submit';
        deleteButton.textContent = '\u{1F5D1}\uFE0F L\u00f6schen';
        deleteButton.className = 'button-link button-link-danger';

        deleteForm.append(
            createHiddenInput('id', note.id),
            createHiddenInput('csrf_token', csrfToken()),
            deleteButton
        );

        actionGroup.append(editLink, uploadLink, convertLink, deleteForm);
        card.append(top, title, content, actionGroup);
        fillNoteCard(card, {
            ...note,
            created_relative: note.created_relative || 'gerade eben'
        });

        return card;
    }

    function hideEditNoteForm() {
        editNoteForm.reset();
        setMessage(editNoteMessage, '');
        closeNoteModal(editNoteModal);
    }

    function showEditNoteForm(card) {
        editNoteForm.elements.id.value = card.dataset.noteId;
        editNoteForm.elements.title.value = card.dataset.title || '';
        editNoteForm.elements.content.value = card.dataset.content || '';
        openNoteModal(editNoteModal);
        setMessage(editNoteMessage, '');
        editNoteForm.elements.title.focus();
    }

    function updateNotesState() {
        const cards = Array.from(notesBoard.querySelectorAll('.note-card'));
        const total = cards.length;
        const query = notesFilter ? notesFilter.value.trim().toLowerCase() : '';
        let visible = 0;

        cards.forEach((card) => {
            const haystack = `${card.dataset.title || ''} ${card.dataset.content || ''}`.toLowerCase();
            const matches = query === '' || haystack.includes(query);
            card.classList.toggle('is-filter-hidden', !matches);

            if (matches) {
                visible += 1;
            }
        });

        if (notesCount) {
            notesCount.textContent = String(total);
        }

        notesBoard.classList.toggle('is-hidden', total === 0);
        emptyNotesMessage.classList.toggle('is-hidden', total !== 0);
        noFilteredNotesMessage.classList.toggle('is-hidden', total === 0 || query === '' || visible > 0);
    }

    createNoteForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submitButton = createNoteForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        setMessage(createNoteMessage, '');

        try {
            const response = await fetch(createNoteForm.dataset.ajaxUrl, {
                method: 'POST',
                body: new FormData(createNoteForm),
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await response.json();

            if (data.success) {
                notesBoard.prepend(createNoteCard(data.note));
                createNoteForm.reset();
                updateNotesState();
                setMessage(createNoteMessage, 'Notiz gespeichert.');
                window.setTimeout(() => closeNoteModal(createNoteModal), 450);
            } else {
                setMessage(createNoteMessage, data.message || 'Fehler beim Speichern.', true);
            }
        } catch (error) {
            setMessage(createNoteMessage, 'Fehler beim Speichern.', true);
        } finally {
            submitButton.disabled = false;
        }
    });

    editNoteForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submitButton = editNoteForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        setMessage(editNoteMessage, '');

        try {
            const response = await fetch(editNoteForm.dataset.ajaxUrl, {
                method: 'POST',
                body: new FormData(editNoteForm),
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await response.json();

            if (data.success) {
                const card = notesBoard.querySelector(`[data-note-id="${data.note.id}"]`);

                if (card) {
                    fillNoteCard(card, data.note);
                    updateNotesState();
                }

                setMessage(editNoteMessage, 'Notiz aktualisiert.');
                window.setTimeout(() => closeNoteModal(editNoteModal), 450);
            } else {
                setMessage(editNoteMessage, data.message || 'Fehler beim Speichern.', true);
            }
        } catch (error) {
            setMessage(editNoteMessage, 'Fehler beim Speichern.', true);
        } finally {
            submitButton.disabled = false;
        }
    });

    cancelNoteEditButton.addEventListener('click', hideEditNoteForm);

    noteModalTriggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            const modal = document.getElementById(trigger.dataset.noteModalTarget || '');

            if (!modal) {
                return;
            }

            event.preventDefault();
            openNoteModal(modal);
        });
    });

    noteModalCloseButtons.forEach((button) => {
        button.addEventListener('click', () => closeNoteModal(button.closest('.note-modal')));
    });

    noteModals.forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeNoteModal(modal);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllNoteModals();
        }
    });

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const editLink = event.target.closest('.ajax-edit-note-link');

        if (!editLink) {
            return;
        }

        event.preventDefault();

        const card = editLink.closest('.note-card');

        if (card) {
            showEditNoteForm(card);
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
                const card = form.closest('.note-card');
                const deletedId = card ? card.dataset.noteId : '';

                if (card) {
                    card.remove();
                }

                if (editNoteForm.elements.id.value === deletedId) {
                    hideEditNoteForm();
                }

                updateNotesState();
            } else {
                alert('Fehler beim L\u00f6schen');
            }
        } catch (error) {
            alert('Fehler beim L\u00f6schen');
        }
    });

    if (notesFilter) {
        notesFilter.addEventListener('input', updateNotesState);
    }

    updateNotesState();
})();
