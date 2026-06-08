<?php

// =======================================
// appointments.php
// Zweck: Eigene Termine anzeigen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/appointment_dates.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$user_id = $_SESSION['user_id'];
$default_start_date = appointment_default_start_date_value();
$default_due_date = appointment_default_due_date_value();

appointment_ensure_start_date_column($pdo);

function appointment_status_label(string $status): string
{
    $labels = [
        'open' => 'Offen',
        'done' => 'Erledigt',
        'cancelled' => 'Abgebrochen'
    ];

    return $labels[$status] ?? $status;
}

$stmt = $pdo->prepare("
    SELECT id, subject, start_date, due_date, content, status, created_at
    FROM appointments
    WHERE user_id = :user_id
    ORDER BY due_date ASC
");

$stmt->execute([
    'user_id' => $user_id
]);

$appointments = $stmt->fetchAll();

?>

<?php
app_render_header('Termine', 'appointments', [
    'subtitle' => 'Plane Aufgaben, Faelligkeiten und wichtige Ereignisse.',
    'actions' => '<a class="button button-green" href="create_appointment.php" data-appointment-modal-target="appointment-create-modal">+ Neuer Termin</a>'
]);
?>

<noscript>
    <p><a href="create_appointment.php">Neuen Termin auf eigener Seite erstellen</a></p>
</noscript>

<section class="panel">

<p id="empty-appointments-message" class="empty-state <?= empty($appointments) ? '' : 'is-hidden' ?>">Keine Termine vorhanden.</p>

<table id="appointments-table" class="data-table appointment-table <?= empty($appointments) ? 'is-hidden' : '' ?>">
    <colgroup>
        <col class="appointment-subject-col">
        <col class="appointment-date-col">
        <col class="appointment-date-col">
        <col class="appointment-content-col">
        <col class="appointment-status-col">
        <col class="appointment-actions-col">
    </colgroup>

    <thead>
    <tr>
        <th>Betreff</th>
        <th>Startdatum</th>
        <th>Enddatum</th>
        <th>Beschreibung</th>
        <th>Status</th>
        <th>Aktionen</th>
    </tr>
    </thead>

    <tbody id="appointments-table-body">
    <?php foreach ($appointments as $appointment): ?>
        <?php
        $start_date_timestamp = strtotime((string)($appointment['start_date'] ?: $appointment['due_date']));
        $start_date_display = $start_date_timestamp ? date('d.m.Y H:i', $start_date_timestamp) : ($appointment['start_date'] ?: $appointment['due_date']);
        $start_date_input = $start_date_timestamp ? date('Y-m-d\TH:i', $start_date_timestamp) : '';
        $due_date_timestamp = strtotime($appointment['due_date']);
        $due_date_display = $due_date_timestamp ? date('d.m.Y H:i', $due_date_timestamp) : $appointment['due_date'];
        $due_date_input = $due_date_timestamp ? date('Y-m-d\TH:i', $due_date_timestamp) : '';
        ?>
        <tr
            data-appointment-id="<?= (int)$appointment['id'] ?>"
            data-subject="<?= htmlspecialchars($appointment['subject']) ?>"
            data-start-date-input="<?= htmlspecialchars($start_date_input) ?>"
            data-start-date-sort="<?= htmlspecialchars((string)($start_date_timestamp ?: 0)) ?>"
            data-due-date-input="<?= htmlspecialchars($due_date_input) ?>"
            data-due-date-sort="<?= htmlspecialchars((string)($due_date_timestamp ?: 0)) ?>"
            data-content="<?= htmlspecialchars($appointment['content'] ?? '') ?>"
            data-status="<?= htmlspecialchars($appointment['status']) ?>"
        >
            <td class="appointment-subject-cell"><?= htmlspecialchars($appointment['subject']) ?></td>
            <td class="appointment-start-date-cell"><?= htmlspecialchars($start_date_display) ?></td>
            <td class="appointment-due-date-cell"><?= htmlspecialchars($due_date_display) ?></td>
            <td class="appointment-content-cell"><?= nl2br(htmlspecialchars($appointment['content'] ?? '')) ?></td>
            <td class="appointment-status-cell"><?= htmlspecialchars(appointment_status_label($appointment['status'])) ?></td>
            <td class="action-cell">
                <div class="action-group">
                <a href="edit_appointment.php?id=<?= (int)$appointment['id'] ?>" class="button-link ajax-edit-appointment-link">&#9998; Bearbeiten</a>
                <a href="upload_appointment_file.php?id=<?= (int)$appointment['id'] ?>" class="button-link">&#128206; Datei anh&auml;ngen</a>
                <div class="appointment-action-pair">
                    <form method="POST" action="convert_appointment_to_note.php" class="action-inline">
                        <input type="hidden" name="id" value="<?= (int)$appointment['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="button-link">&#8618; Als Notiz</button>
                    </form>
                    <form method="POST" action="delete_appointment.php" class="action-inline ajax-delete-form" data-ajax-url="delete_appointment_ajax.php">
                        <input type="hidden" name="id" value="<?= (int)$appointment['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="button-link button-link-danger">&#128465; L&ouml;schen</button>
                    </form>
                </div>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</section>

<div class="modal-backdrop appointment-modal" id="appointment-create-modal" hidden>
    <section class="app-modal app-modal-wide" role="dialog" aria-modal="true" aria-labelledby="appointment-create-modal-title">
        <div class="modal-header">
            <div>
                <p class="eyebrow">Termin planen</p>
                <h2 id="appointment-create-modal-title">Neuer Termin</h2>
            </div>
            <button type="button" class="icon-button modal-close-button" data-appointment-modal-close aria-label="Schliessen">&times;</button>
        </div>

        <form method="POST" action="create_appointment.php" id="create-appointment-form" data-ajax-url="create_appointment_ajax.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

            <label>
                Betreff
                <input type="text" name="subject" required maxlength="150">
            </label>

            <label>
                Startdatum
                <input type="datetime-local" name="start_date" value="<?= e($default_start_date) ?>" data-default-start-date="<?= e($default_start_date) ?>" required>
            </label>

            <label>
                Enddatum / Faelligkeit
                <input type="datetime-local" name="due_date" value="<?= e($default_due_date) ?>" data-default-due-date="<?= e($default_due_date) ?>" required>
            </label>

            <div class="due-date-presets" aria-label="Schnellauswahl Faelligkeitsdatum">
                <?php foreach (appointment_due_date_presets() as $preset): ?>
                    <button type="button" class="button-secondary" data-due-date-value="<?= e($preset['value']) ?>"><?= e($preset['label']) ?></button>
                <?php endforeach; ?>
            </div>

            <label>
                Beschreibung
                <textarea name="content" maxlength="10000"></textarea>
            </label>

            <label>
                Status
                <select name="status">
                    <option value="open">Offen</option>
                    <option value="done">Erledigt</option>
                    <option value="cancelled">Abgebrochen</option>
                </select>
            </label>

            <div class="form-actions">
                <span id="create-appointment-message" class="status-message" role="status"></span>
                <button type="button" class="button-secondary" data-appointment-modal-close>Abbrechen</button>
                <button type="submit" class="button-green">Termin speichern</button>
            </div>
        </form>
    </section>
</div>

<div class="modal-backdrop appointment-modal" id="appointment-edit-modal" hidden>
    <section class="app-modal app-modal-wide" role="dialog" aria-modal="true" aria-labelledby="appointment-edit-modal-title">
        <div class="modal-header">
            <div>
                <p class="eyebrow">Termin bearbeiten</p>
                <h2 id="appointment-edit-modal-title">Termin bearbeiten</h2>
            </div>
            <button type="button" class="icon-button modal-close-button" data-appointment-modal-close aria-label="Schliessen">&times;</button>
        </div>

        <form method="POST" id="edit-appointment-form" data-ajax-url="edit_appointment_ajax.php">
            <input type="hidden" name="id">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

            <label>
                Betreff
                <input type="text" name="subject" required maxlength="150">
            </label>

            <label>
                Startdatum
                <input type="datetime-local" name="start_date" required>
            </label>

            <label>
                Enddatum / Faelligkeit
                <input type="datetime-local" name="due_date" required>
            </label>

            <label>
                Beschreibung
                <textarea name="content" maxlength="10000"></textarea>
            </label>

            <label>
                Status
                <select name="status">
                    <option value="open">Offen</option>
                    <option value="done">Erledigt</option>
                    <option value="cancelled">Abgebrochen</option>
                </select>
            </label>

            <div class="form-actions">
                <span id="edit-appointment-message" class="status-message" role="status"></span>
                <button type="button" class="button-secondary" id="cancel-appointment-edit" data-appointment-modal-close>Abbrechen</button>
                <button type="submit" class="button-green">Aenderungen speichern</button>
            </div>
        </form>
    </section>
</div>

<script src="<?= e(app_asset_url('assets/appointments.js')) ?>"></script>

<?php app_render_footer(); ?>
