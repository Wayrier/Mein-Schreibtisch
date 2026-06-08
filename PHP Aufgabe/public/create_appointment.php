<?php

// =======================================
// create_appointment.php
// Zweck: Neuen Termin erstellen
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
$error = null;
$subject = '';
$start_date = appointment_default_start_date_value();
$due_date = appointment_default_due_date_value();
$content = '';
$status = 'open';

appointment_ensure_start_date_column($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } else {
        $subject = is_string($_POST['subject'] ?? null) ? trim($_POST['subject']) : '';
        $start_date = is_string($_POST['start_date'] ?? null) ? trim($_POST['start_date']) : '';
        $due_date = is_string($_POST['due_date'] ?? null) ? trim($_POST['due_date']) : '';
        $content = is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : 'open';

        $user_id = (int)$_SESSION['user_id'];
        $allowed_statuses = ['open', 'done', 'cancelled'];
        $start_date = $start_date !== '' ? $start_date : $due_date;
        $start_date_object = appointment_parse_datetime_local($start_date);
        $due_date_object = appointment_parse_datetime_local($due_date);

        if ($subject === '') {
            $error = "Betreff darf nicht leer sein.";
        } elseif (strlen($subject) > 150) {
            $error = "Betreff darf maximal 150 Zeichen lang sein.";
        } elseif (strlen($content) > 10000) {
            $error = "Beschreibung darf maximal 10000 Zeichen lang sein.";
        } elseif ($start_date === '') {
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
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO appointments (user_id, subject, start_date, due_date, content, status)
                    VALUES (:user_id, :subject, :start_date, :due_date, :content, :status)
                ");

                $stmt->execute([
                    'user_id' => $user_id,
                    'subject' => $subject,
                    'start_date' => appointment_storage_datetime_value($start_date_object),
                    'due_date' => appointment_storage_datetime_value($due_date_object),
                    'content' => $content,
                    'status' => $status
                ]);

                header("Location: appointments.php");
                exit;
            } catch (PDOException $e) {
                $error = "Termin konnte nicht gespeichert werden. Bitte versuchen Sie es spaeter erneut.";
            }
        }
    }
}

app_render_header('Neuer Termin', 'appointments', [
    'subtitle' => 'Lege einen neuen Termin oder eine Aufgabe an.',
    'actions' => '<a class="button button-secondary" href="appointments.php">&larr; Zurueck</a>'
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= e($error) ?></p>
<?php endif; ?>

<form method="POST" class="panel">
    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

    <div class="form-grid">
        <label>
            Betreff
            <input type="text" name="subject" value="<?= e($subject) ?>" required maxlength="150">
        </label>

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

        <label class="full">
            Beschreibung
            <textarea name="content" rows="8" maxlength="10000"><?= e($content) ?></textarea>
        </label>
    </div>

    <div class="form-actions">
        <button type="submit">Termin speichern</button>
    </div>
</form>

<?php app_render_footer(); ?>
