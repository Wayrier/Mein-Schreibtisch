<?php

// =======================================
// dashboard.php
// Zweck: Grafische Uebersicht nach Login
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/appointment_dates.php';
require_once '../src/dashboard_service.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$user_id = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'Benutzer');
$default_start_date = appointment_default_start_date_value();
$default_due_date = appointment_default_due_date_value();
$dashboard_data = dashboard_load_data($pdo, $user_id);
$weather_city = $dashboard_data['weather_city'];
$use_geolocation = $dashboard_data['use_geolocation'];
$notes = $dashboard_data['notes'];
$appointments = $dashboard_data['appointments'];
$stats = $dashboard_data['stats'];

app_render_header('Uebersicht', 'dashboard', [
    'show_heading' => false,
    'wide' => true
]);
?>

<section class="page-heading">
    <div>
        <p class="eyebrow">Willkommen zurueck</p>
        <h1>Hallo, <?= e($username) ?></h1>
        <p>Deine Notizen, Termine und Dateien auf einen Blick.</p>
    </div>
    <div class="page-actions">
        <a class="button" href="notes.php" data-dashboard-modal-target="dashboard-note-modal">+ Neue Notiz</a>
        <a class="button button-green" href="appointments.php" data-dashboard-modal-target="dashboard-appointment-modal">+ Neuer Termin</a>
        <a class="button button-secondary" href="#quick-actions">Schnellaktionen</a>
    </div>
</section>

<section class="dashboard-grid">
    <article class="card dashboard-top-card dashboard-hero-card span-4">
        <div class="card-header">
            <h2 class="card-title">&#9728; Wetter &amp; Uhrzeit</h2>
        </div>

        <div class="weather-time-grid">
            <div class="weather-panel">
                <div class="weather-visual">
                    <span class="sun-cloud">&#9728;</span>
                    <div>
                        <p class="big-number" id="dashboard-weather-temp">--&deg;C</p>
                        <p class="muted" id="dashboard-weather-details">Wetter wird geladen...</p>
                    </div>
                </div>
                <p class="muted" id="dashboard-weather-place"><?= e($weather_city) ?></p>
                <div class="forecast-row" aria-label="Vorschau">
                    <span class="forecast-item">Heute<br><strong>Aktuell</strong></span>
                    <span class="forecast-item">Morgen<br><strong>Planen</strong></span>
                    <span class="forecast-item">Notizen<br><strong><?= (int)$stats['notes'] ?></strong></span>
                    <span class="forecast-item">Termine<br><strong><?= (int)$stats['appointments'] ?></strong></span>
                </div>
            </div>

            <div class="time-panel">
                <p class="card-title time-title">&#9711; Uhrzeit</p>
                <p class="big-number" id="dashboard-time"><?= e(date('H:i:s')) ?></p>
                <p class="muted" id="dashboard-date"><?= e(date('d.m.Y')) ?></p>
            </div>
        </div>
    </article>

    <article class="card dashboard-top-card card-accent quick-note-card span-4">
        <div class="card-header">
            <h2 class="card-title">&#9998; Schnellnotiz</h2>
        </div>
        <form method="POST" action="create_note.php" id="quick-note-form" data-ajax-url="create_note_ajax.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="title" value="Schnellnotiz">
            <textarea name="content" maxlength="10000" placeholder="Schreibe eine Notiz..."></textarea>
            <div class="quick-note-footer">
                <span id="quick-note-message" class="status-message" role="status"></span>
                <button class="button-yellow" type="submit">Speichern</button>
            </div>
        </form>
    </article>

    <article class="card dashboard-top-card quick-actions-card span-4" id="quick-actions">
        <div class="card-header">
            <h2 class="card-title">&#9889; Schnellaktionen</h2>
        </div>
        <div class="quick-actions">
            <a class="quick-action" href="notes.php" data-dashboard-modal-target="dashboard-note-modal">Neue Notiz erstellen <span>&rsaquo;</span></a>
            <a class="quick-action" href="appointments.php" data-dashboard-modal-target="dashboard-appointment-modal">Neuen Termin erstellen <span>&rsaquo;</span></a>
            <a class="quick-action" href="files.php">Dateien durchsuchen <span>&rsaquo;</span></a>
            <a class="quick-action" href="backup.php">Backup erstellen <span>&rsaquo;</span></a>
            <a class="quick-action" href="settings.php">Einstellungen oeffnen <span>&rsaquo;</span></a>
        </div>
    </article>

    <article class="card dashboard-pair-card span-6">
        <div class="card-header">
            <h2 class="card-title">&#9998; Meine Notizen</h2>
            <a class="card-link" href="notes.php">Alle anzeigen</a>
        </div>
        <div class="item-list" id="dashboard-notes-list">
            <?php if (empty($notes)): ?>
                <p class="muted" id="dashboard-notes-empty">Noch keine Notizen vorhanden.</p>
            <?php else: ?>
                <?php foreach ($notes as $note): ?>
                    <a class="list-row" href="edit_note.php?id=<?= (int)$note['id'] ?>">
                        <span>
                            <span class="list-title"><?= e($note['title']) ?></span><br>
                            <span class="list-meta"><?= e(dashboard_excerpt($note['content'])) ?></span>
                        </span>
                        <span class="list-meta"><?= e(dashboard_relative_time($note['created_at'])) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <p class="muted"><span id="dashboard-notes-count"><?= (int)$stats['notes'] ?></span> Notizen insgesamt</p>
    </article>

    <article class="card dashboard-pair-card span-6">
        <div class="card-header">
            <h2 class="card-title">&#9633; Naechste Termine</h2>
            <a class="card-link" href="appointments.php">Alle anzeigen</a>
        </div>
        <div class="item-list" id="dashboard-appointments-list">
            <?php if (empty($appointments)): ?>
                <p class="muted" id="dashboard-appointments-empty">Keine Termine vorhanden.</p>
            <?php else: ?>
                <?php foreach ($appointments as $appointment): ?>
                    <?php $appointment_state = dashboard_appointment_state($appointment); ?>
                    <a class="list-row appointment-row <?= e($appointment_state['row_class']) ?>" href="edit_appointment.php?id=<?= (int)$appointment['id'] ?>" data-due-date-sort="<?= e((string)(strtotime($appointment['due_date']) ?: 0)) ?>">
                        <span>
                            <span class="status-dot <?= e($appointment_state['dot_class']) ?>"></span>
                            <span class="list-title"><?= e($appointment['subject']) ?></span>
                        </span>
                        <span class="appointment-meta">
                            <span class="status-pill <?= e($appointment_state['pill_class']) ?>"><?= e($appointment_state['label']) ?></span>
                            <span class="list-meta"><?= e(dashboard_datetime($appointment['due_date'])) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <p class="muted"><span id="dashboard-appointments-count"><?= (int)$stats['appointments'] ?></span> Termine insgesamt</p>
    </article>

</section>

<div class="modal-backdrop dashboard-modal" id="dashboard-note-modal" hidden>
    <section class="app-modal" role="dialog" aria-modal="true" aria-labelledby="dashboard-note-modal-title">
        <div class="modal-header">
            <div>
                <p class="eyebrow">Schnell erstellen</p>
                <h2 id="dashboard-note-modal-title">Neue Notiz</h2>
            </div>
            <button type="button" class="icon-button modal-close-button" data-dashboard-modal-close aria-label="Schliessen">&times;</button>
        </div>

        <form method="POST" action="create_note.php" id="dashboard-note-modal-form" data-ajax-url="create_note_ajax.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

            <label for="dashboard-note-title">Titel</label>
            <input id="dashboard-note-title" type="text" name="title" maxlength="150" required>

            <label for="dashboard-note-content">Inhalt</label>
            <textarea id="dashboard-note-content" name="content" maxlength="10000"></textarea>

            <div class="form-actions">
                <span id="dashboard-note-modal-message" class="status-message" role="status"></span>
                <button type="button" class="button-secondary" data-dashboard-modal-close>Abbrechen</button>
                <button type="submit">Speichern</button>
            </div>
        </form>
    </section>
</div>

<div class="modal-backdrop dashboard-modal" id="dashboard-appointment-modal" hidden>
    <section class="app-modal" role="dialog" aria-modal="true" aria-labelledby="dashboard-appointment-modal-title">
        <div class="modal-header">
            <div>
                <p class="eyebrow">Schnell erstellen</p>
                <h2 id="dashboard-appointment-modal-title">Neuer Termin</h2>
            </div>
            <button type="button" class="icon-button modal-close-button" data-dashboard-modal-close aria-label="Schliessen">&times;</button>
        </div>

        <form method="POST" action="create_appointment.php" id="dashboard-appointment-modal-form" data-ajax-url="create_appointment_ajax.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

            <label for="dashboard-appointment-subject">Betreff</label>
            <input id="dashboard-appointment-subject" type="text" name="subject" maxlength="150" required>

            <label for="dashboard-appointment-start-date">Startdatum</label>
            <input id="dashboard-appointment-start-date" type="datetime-local" name="start_date" value="<?= e($default_start_date) ?>" data-default-start-date="<?= e($default_start_date) ?>" required>

            <label for="dashboard-appointment-due-date">Enddatum / Faelligkeit</label>
            <input id="dashboard-appointment-due-date" type="datetime-local" name="due_date" value="<?= e($default_due_date) ?>" data-default-due-date="<?= e($default_due_date) ?>" required>

            <div class="due-date-presets" aria-label="Schnellauswahl Faelligkeitsdatum">
                <?php foreach (appointment_due_date_presets() as $preset): ?>
                    <button type="button" class="button-secondary" data-due-date-value="<?= e($preset['value']) ?>"><?= e($preset['label']) ?></button>
                <?php endforeach; ?>
            </div>

            <label for="dashboard-appointment-content">Beschreibung</label>
            <textarea id="dashboard-appointment-content" name="content" maxlength="10000"></textarea>

            <label for="dashboard-appointment-status">Status</label>
            <select id="dashboard-appointment-status" name="status">
                <option value="open">Offen</option>
                <option value="done">Erledigt</option>
                <option value="cancelled">Abgebrochen</option>
            </select>

            <div class="form-actions">
                <span id="dashboard-appointment-modal-message" class="status-message" role="status"></span>
                <button type="button" class="button-secondary" data-dashboard-modal-close>Abbrechen</button>
                <button type="submit" class="button-green">Speichern</button>
            </div>
        </form>
    </section>
</div>

<p class="footer-note">&copy; <?= e(date('Y')) ?> MeinSchreibtisch. Alle Rechte vorbehalten.</p>

<script>
window.dashboardConfig = <?= json_encode(['weatherCity' => $weather_city, 'useGeolocation' => $use_geolocation], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(app_asset_url('assets/dashboard.js')) ?>"></script>

<?php app_render_footer(); ?>
