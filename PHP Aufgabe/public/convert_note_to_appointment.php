<?php

// =======================================
// convert_note_to_appointment.php
// Zweck: Aus einer Notiz einen Termin erstellen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/appointment_dates.php';
require_once '../src/response.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$user_id = $_SESSION['user_id'];
$note_id = $_GET['id'] ?? ($_POST['id'] ?? null);
$note_id = is_scalar($note_id) ? filter_var($note_id, FILTER_VALIDATE_INT) : false;
$error = null;
$start_date = appointment_default_start_date_value();
$due_date = appointment_default_due_date_value();
$status = 'open';

appointment_ensure_start_date_column($pdo);

if ($note_id === false || $note_id <= 0) {
    app_redirect_error('notes.php', 'Keine oder ungueltige Notiz-ID angegeben.');
}

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM notes
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $note_id,
        'user_id' => $user_id
    ]);

    $note = $stmt->fetch();

    if (!$note) {
        app_redirect_error('notes.php', 'Notiz nicht gefunden oder kein Zugriff.');
    }
} catch (PDOException $e) {
    error_log("Notiz konnte nicht geladen werden: " . $e->getMessage());
    app_redirect_error('notes.php', 'Notiz konnte nicht geladen werden.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } else {
        $start_date = is_string($_POST['start_date'] ?? null) ? trim($_POST['start_date']) : '';
        $due_date = is_string($_POST['due_date'] ?? null) ? trim($_POST['due_date']) : '';
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : 'open';
        $allowed_statuses = ['open', 'done', 'cancelled'];
        $start_date = $start_date !== '' ? $start_date : $due_date;
        $start_date_object = appointment_parse_datetime_local($start_date);
        $due_date_object = appointment_parse_datetime_local($due_date);

        if ($start_date === '') {
            $error = "Startdatum darf nicht leer sein.";
        } elseif (!$start_date_object) {
            $error = "Startdatum ist ungueltig.";
        } elseif ($due_date === '') {
            $error = "Enddatum darf nicht leer sein.";
        } elseif (!$due_date_object) {
            $error = "Enddatum ist ungueltig.";
        } elseif ($due_date_object < $start_date_object) {
            $error = "Enddatum darf nicht vor dem Startdatum liegen.";
        } elseif (!in_array($status, $allowed_statuses, true)) {
            $error = "Ungueltiger Status.";
            $status = 'open';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT c.target_id
                    FROM conversions c
                    INNER JOIN appointments a ON a.id = c.target_id AND a.user_id = c.user_id
                    WHERE c.user_id = :user_id
                      AND c.source_type = 'note'
                      AND c.source_id = :source_id
                      AND c.target_type = 'appointment'
                    LIMIT 1
                ");

                $stmt->execute([
                    'user_id' => $user_id,
                    'source_id' => $note_id
                ]);

                $existing_conversion = $stmt->fetch();

                if ($existing_conversion) {
                    app_redirect_info('edit_appointment.php?id=' . (int)$existing_conversion['target_id'], 'Diese Notiz wurde bereits in einen Termin umgewandelt.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO appointments (user_id, subject, start_date, due_date, content, status)
                    VALUES (:user_id, :subject, :start_date, :due_date, :content, :status)
                ");

                $stmt->execute([
                    'user_id' => $user_id,
                    'subject' => $note['title'],
                    'start_date' => appointment_storage_datetime_value($start_date_object),
                    'due_date' => appointment_storage_datetime_value($due_date_object),
                    'content' => $note['content'],
                    'status' => $status
                ]);

                $appointment_id = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO appointment_files (appointment_id, file_id)
                    SELECT :appointment_id, file_id
                    FROM note_files
                    WHERE note_id = :note_id
                ");

                $stmt->execute([
                    'appointment_id' => $appointment_id,
                    'note_id' => $note_id
                ]);

                $stmt = $pdo->prepare("
                    INSERT INTO conversions (user_id, source_type, source_id, target_type, target_id)
                    VALUES (:user_id, 'note', :source_id, 'appointment', :target_id)
                ");

                $stmt->execute([
                    'user_id' => $user_id,
                    'source_id' => $note_id,
                    'target_id' => $appointment_id
                ]);

                $pdo->commit();

                app_redirect_success('edit_appointment.php?id=' . $appointment_id, 'Notiz wurde in einen Termin umgewandelt.');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Notiz konnte nicht in einen Termin umgewandelt werden: " . $e->getMessage());
                $error = "Notiz konnte nicht in einen Termin umgewandelt werden.";
            }
        }
    }
}

?>

<?php
app_render_header('Notiz in Termin umwandeln', 'notes', [
    'subtitle' => 'Quelle: ' . (string)$note['title'],
    'actions' => '<a class="button button-secondary" href="notes.php">&larr; Zurueck</a>'
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" class="panel">
    <input type="hidden" name="id" value="<?= (int)$note_id ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

    <div class="form-grid">
        <label>
            Startdatum
            <input type="datetime-local" name="start_date" value="<?= e($start_date) ?>" required>
        </label>

        <label>
            Enddatum / Faelligkeit
            <input type="datetime-local" name="due_date" value="<?= e($due_date) ?>" required>
        </label>

        <div class="due-date-presets full" aria-label="Schnellauswahl Faelligkeitsdatum">
            <?php foreach (appointment_due_date_presets() as $preset): ?>
                <button type="button" class="button-secondary" data-due-date-value="<?= e($preset['value']) ?>"><?= e($preset['label']) ?></button>
            <?php endforeach; ?>
        </div>

        <label>
            Status
            <select name="status">
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Offen</option>
                <option value="done" <?= $status === 'done' ? 'selected' : '' ?>>Erledigt</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Abgebrochen</option>
            </select>
        </label>
    </div>

    <div class="form-actions">
        <button type="submit">Termin erstellen</button>
    </div>
</form>

<?php app_render_footer(); ?>
