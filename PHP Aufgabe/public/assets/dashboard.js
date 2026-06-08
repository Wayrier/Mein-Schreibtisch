const dashboardConfig = window.dashboardConfig || {};
const weatherCity = dashboardConfig.weatherCity || 'Mannheim';
const useGeolocation = Boolean(dashboardConfig.useGeolocation);
const timeElement = document.getElementById('dashboard-time');
const dateElement = document.getElementById('dashboard-date');
const weatherTempElement = document.getElementById('dashboard-weather-temp');
const weatherPlaceElement = document.getElementById('dashboard-weather-place');
const weatherDetailsElement = document.getElementById('dashboard-weather-details');
const quickNoteForm = document.getElementById('quick-note-form');
const quickNoteMessage = document.getElementById('quick-note-message');
const dashboardModalTriggers = document.querySelectorAll('[data-dashboard-modal-target]');
const dashboardModals = document.querySelectorAll('.dashboard-modal');
const dashboardModalCloseButtons = document.querySelectorAll('[data-dashboard-modal-close]');
const dashboardNoteModalForm = document.getElementById('dashboard-note-modal-form');
const dashboardAppointmentModalForm = document.getElementById('dashboard-appointment-modal-form');
const dashboardNoteModalMessage = document.getElementById('dashboard-note-modal-message');
const dashboardAppointmentModalMessage = document.getElementById('dashboard-appointment-modal-message');
const dashboardNotesList = document.getElementById('dashboard-notes-list');
const dashboardAppointmentsList = document.getElementById('dashboard-appointments-list');
const dashboardNotesCount = document.getElementById('dashboard-notes-count');
const dashboardAppointmentsCount = document.getElementById('dashboard-appointments-count');

const weatherCodes = {
    0: 'Klar',
    1: 'Ueberwiegend klar',
    2: 'Teilweise bewoelkt',
    3: 'Bewoelkt',
    45: 'Nebel',
    48: 'Reifnebel',
    51: 'Leichter Nieselregen',
    53: 'Nieselregen',
    55: 'Starker Nieselregen',
    61: 'Leichter Regen',
    63: 'Regen',
    65: 'Starker Regen',
    71: 'Leichter Schneefall',
    73: 'Schneefall',
    75: 'Starker Schneefall',
    80: 'Leichte Schauer',
    81: 'Schauer',
    82: 'Starke Schauer',
    95: 'Gewitter',
    96: 'Gewitter mit Hagel',
    99: 'Starkes Gewitter mit Hagel'
};

function dashboardSetMessage(element, message, isError = false) {
    if (!element) {
        return;
    }

    element.textContent = message;
    element.classList.remove('is-success', 'is-error');

    if (message !== '') {
        element.classList.add(isError ? 'is-error' : 'is-success');
    }
}

function openDashboardModal(modalId) {
    const modal = document.getElementById(modalId);

    if (!modal) {
        return;
    }

    dashboardModals.forEach((currentModal) => {
        currentModal.hidden = currentModal !== modal;
    });

    modal.hidden = false;
    document.body.classList.add('modal-open');
    modal.querySelectorAll('.status-message').forEach((message) => dashboardSetMessage(message, ''));

    const firstField = modal.querySelector('input:not([type="hidden"]), textarea, select, button');

    if (firstField) {
        firstField.focus();
    }
}

function closeDashboardModal(modal) {
    if (!modal) {
        return;
    }

    modal.hidden = true;

    if (!Array.from(dashboardModals).some((currentModal) => !currentModal.hidden)) {
        document.body.classList.remove('modal-open');
    }
}

function closeAllDashboardModals() {
    dashboardModals.forEach(closeDashboardModal);
}

function dashboardExcerpt(value, limit = 58) {
    const text = String(value || '').trim();

    return text.length > limit ? `${text.slice(0, limit - 3)}...` : text;
}

function trimDashboardList(list) {
    if (!list) {
        return;
    }

    Array.from(list.querySelectorAll('.list-row')).slice(5).forEach((row) => row.remove());
}

function incrementDashboardCount(element) {
    if (!element) {
        return;
    }

    element.textContent = String(Number(element.textContent || 0) + 1);
}

function addDashboardNote(note) {
    if (!dashboardNotesList || !note) {
        return;
    }

    const emptyMessage = document.getElementById('dashboard-notes-empty');

    if (emptyMessage) {
        emptyMessage.remove();
    }

    const row = document.createElement('a');
    row.className = 'list-row';
    row.href = `edit_note.php?id=${encodeURIComponent(note.id)}`;

    const textWrap = document.createElement('span');
    const title = document.createElement('span');
    title.className = 'list-title';
    title.textContent = note.title || 'Ohne Titel';

    const lineBreak = document.createElement('br');
    const preview = document.createElement('span');
    preview.className = 'list-meta';
    preview.textContent = dashboardExcerpt(note.content || '');

    const created = document.createElement('span');
    created.className = 'list-meta';
    created.textContent = 'gerade eben';

    textWrap.append(title, lineBreak, preview);
    row.append(textWrap, created);
    dashboardNotesList.prepend(row);
    trimDashboardList(dashboardNotesList);
    incrementDashboardCount(dashboardNotesCount);
}

function dashboardAppointmentState(appointment) {
    if (appointment.status === 'done') {
        return {
            rowClass: 'appointment-state-done',
            dotClass: 'dot-green',
            pillClass: 'status-pill-done',
            label: 'Erledigt'
        };
    }

    if (appointment.status === 'cancelled') {
        return {
            rowClass: 'appointment-state-muted',
            dotClass: 'dot-blue',
            pillClass: 'status-pill-muted',
            label: 'Abgebrochen'
        };
    }

    const dueTimestamp = Number(appointment.due_date_sort || 0) * 1000;
    const todayStart = new Date();
    todayStart.setHours(0, 0, 0, 0);
    const tomorrowStart = new Date(todayStart);
    tomorrowStart.setDate(tomorrowStart.getDate() + 1);

    if (dueTimestamp && dueTimestamp < todayStart.getTime()) {
        return {
            rowClass: 'appointment-state-overdue',
            dotClass: 'dot-red',
            pillClass: 'status-pill-overdue',
            label: 'Ueberfaellig'
        };
    }

    if (dueTimestamp && dueTimestamp < tomorrowStart.getTime()) {
        return {
            rowClass: 'appointment-state-today',
            dotClass: 'dot-yellow',
            pillClass: 'status-pill-today',
            label: 'Heute'
        };
    }

    return {
        rowClass: 'appointment-state-upcoming',
        dotClass: 'dot-green',
        pillClass: 'status-pill-upcoming',
        label: 'Kommend'
    };
}

function createDashboardAppointmentRow(appointment) {
    const state = dashboardAppointmentState(appointment);
    const row = document.createElement('a');
    row.className = `list-row appointment-row ${state.rowClass}`;
    row.href = `edit_appointment.php?id=${encodeURIComponent(appointment.id)}`;
    row.dataset.dueDateSort = appointment.due_date_sort || '0';

    const textWrap = document.createElement('span');
    const dot = document.createElement('span');
    dot.className = `status-dot ${state.dotClass}`;

    const title = document.createElement('span');
    title.className = 'list-title';
    title.textContent = appointment.subject || 'Ohne Betreff';

    const metaWrap = document.createElement('span');
    metaWrap.className = 'appointment-meta';

    const pill = document.createElement('span');
    pill.className = `status-pill ${state.pillClass}`;
    pill.textContent = state.label;

    const dueDate = document.createElement('span');
    dueDate.className = 'list-meta';
    dueDate.textContent = appointment.due_date || '';

    textWrap.append(dot, title);
    metaWrap.append(pill, dueDate);
    row.append(textWrap, metaWrap);

    return row;
}

function placeDashboardAppointmentRow(row) {
    if (!dashboardAppointmentsList || !row) {
        return;
    }

    const emptyMessage = document.getElementById('dashboard-appointments-empty');

    if (emptyMessage) {
        emptyMessage.remove();
    }

    const sortValue = Number(row.dataset.dueDateSort || 0);
    const nextRow = Array
        .from(dashboardAppointmentsList.querySelectorAll('.appointment-row'))
        .filter((currentRow) => currentRow !== row)
        .find((currentRow) => Number(currentRow.dataset.dueDateSort || 0) > sortValue);

    dashboardAppointmentsList.insertBefore(row, nextRow || null);
    trimDashboardList(dashboardAppointmentsList);
    incrementDashboardCount(dashboardAppointmentsCount);
}

async function submitDashboardModalForm(form, messageElement, onSuccess) {
    const submitButton = form.querySelector('button[type="submit"]');
    dashboardSetMessage(messageElement, '');

    if (submitButton) {
        submitButton.disabled = true;
    }

    try {
        const response = await fetch(form.dataset.ajaxUrl, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'Accept': 'application/json'
            }
        });
        const data = await response.json();

        if (data.success) {
            onSuccess(data);
            form.reset();
            restoreDefaultDueDate(form);
            dashboardSetMessage(messageElement, 'Gespeichert.');
            window.setTimeout(() => closeDashboardModal(form.closest('.dashboard-modal')), 500);
        } else {
            dashboardSetMessage(messageElement, data.message || 'Fehler beim Speichern.', true);
        }
    } catch (error) {
        dashboardSetMessage(messageElement, 'Fehler beim Speichern.', true);
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
}

function restoreDefaultDueDate(form) {
    const dueDateInput = form.querySelector('[data-default-due-date]');
    const startDateInput = form.querySelector('[data-default-start-date]');

    if (startDateInput) {
        startDateInput.value = startDateInput.dataset.defaultStartDate || '';
    }

    if (dueDateInput) {
        dueDateInput.value = dueDateInput.dataset.defaultDueDate || '';
    }
}

dashboardModalTriggers.forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        openDashboardModal(trigger.dataset.dashboardModalTarget);
    });
});

dashboardModalCloseButtons.forEach((button) => {
    button.addEventListener('click', () => {
        closeDashboardModal(button.closest('.dashboard-modal'));
    });
});

dashboardModals.forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeDashboardModal(modal);
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeAllDashboardModals();
    }
});

if (dashboardNoteModalForm) {
    dashboardNoteModalForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submitDashboardModalForm(dashboardNoteModalForm, dashboardNoteModalMessage, (data) => {
            addDashboardNote(data.note);
        });
    });
}

if (dashboardAppointmentModalForm) {
    dashboardAppointmentModalForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submitDashboardModalForm(dashboardAppointmentModalForm, dashboardAppointmentModalMessage, (data) => {
            placeDashboardAppointmentRow(createDashboardAppointmentRow(data.appointment));
        });
    });
}

function updateDashboardClock() {
    const now = new Date();
    timeElement.textContent = new Intl.DateTimeFormat('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    }).format(now);
    dateElement.textContent = new Intl.DateTimeFormat('de-DE', {
        weekday: 'long',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    }).format(now);
}

async function getCoordinatesByCity(city) {
    const params = new URLSearchParams({
        name: city,
        count: '1',
        language: 'de',
        format: 'json'
    });
    const response = await fetch(`https://geocoding-api.open-meteo.com/v1/search?${params}`);
    const data = await response.json();
    const place = data.results && data.results[0];

    if (!place) {
        throw new Error('Ort nicht gefunden');
    }

    return {
        latitude: place.latitude,
        longitude: place.longitude,
        label: [place.name, place.country_code].filter(Boolean).join(', ')
    };
}

function getBrowserCoordinates() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation nicht verfuegbar'));
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => {
                resolve({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    label: 'Aktueller Standort'
                });
            },
            reject,
            {
                enableHighAccuracy: false,
                maximumAge: 10 * 60 * 1000,
                timeout: 5000
            }
        );
    });
}

async function getWeatherLocation() {
    if (useGeolocation) {
        try {
            return await getBrowserCoordinates();
        } catch (error) {
            return getCoordinatesByCity(weatherCity);
        }
    }

    return getCoordinatesByCity(weatherCity);
}

async function loadWeather() {
    try {
        const location = await getWeatherLocation();
        const params = new URLSearchParams({
            latitude: location.latitude,
            longitude: location.longitude,
            current: 'temperature_2m,weather_code,wind_speed_10m',
            timezone: 'auto'
        });
        const response = await fetch(`https://api.open-meteo.com/v1/forecast?${params}`);
        const data = await response.json();
        const current = data.current;

        if (!current) {
            throw new Error('Keine Wetterdaten erhalten');
        }

        const weatherText = weatherCodes[current.weather_code] || 'Wetterdaten';
        weatherTempElement.textContent = `${Math.round(current.temperature_2m)} \u00b0C`;
        weatherPlaceElement.textContent = location.label;
        weatherDetailsElement.textContent = `${weatherText}, Wind ${Math.round(current.wind_speed_10m)} km/h`;
    } catch (error) {
        weatherTempElement.textContent = '-- \u00b0C';
        weatherDetailsElement.textContent = 'Wetter nicht verfuegbar';
    }
}

quickNoteForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    quickNoteMessage.textContent = '';
    quickNoteMessage.classList.remove('is-success', 'is-error');

    try {
        const response = await fetch(quickNoteForm.dataset.ajaxUrl, {
            method: 'POST',
            body: new FormData(quickNoteForm),
            headers: {
                'Accept': 'application/json'
            }
        });
        const data = await response.json();

        if (data.success) {
            quickNoteForm.reset();
            quickNoteMessage.textContent = 'Gespeichert.';
            quickNoteMessage.classList.remove('is-error');
            quickNoteMessage.classList.add('is-success');
        } else {
            quickNoteMessage.textContent = data.message || 'Fehler beim Speichern.';
            quickNoteMessage.classList.remove('is-success');
            quickNoteMessage.classList.add('is-error');
        }
    } catch (error) {
        quickNoteMessage.textContent = 'Fehler beim Speichern.';
        quickNoteMessage.classList.remove('is-success');
        quickNoteMessage.classList.add('is-error');
    }
});

updateDashboardClock();
setInterval(updateDashboardClock, 1000);
loadWeather();
