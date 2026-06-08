<?php

// =======================================
// edit_appointment.php
// Zweck: Eigenen Termin bearbeiten
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
$user_id = (int)$_SESSION['user_id'];
$id = $_GET['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;

appointment_ensure_start_date_column($pdo);

if ($id === false || $id <= 0) {
    app_redirect_error('appointments.php', 'Keine oder ungueltige Termin-ID angegeben.');
}

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM appointments
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $id,
        'user_id' => $user_id
    ]);

    $appointment = $stmt->fetch();

    if (!$appointment) {
        app_redirect_error('appointments.php', 'Termin nicht gefunden oder kein Zugriff.');
    }
} catch (PDOException $e) {
    error_log("Termin konnte nicht geladen werden: " . $e->getMessage());
    app_redirect_error('appointments.php', 'Fehler beim Laden des Termins. Bitte versuchen Sie es spaeter erneut.');
}

$error = null;
$form_start_date = appointment_start_date_input_value($appointment['start_date'] ?? null, $appointment['due_date'] ?? null);
$appointment_due_date = strtotime($appointment['due_date']);
$form_subject = $appointment['subject'];
$form_due_date = $appointment_due_date ? date('Y-m-d\TH:i', $appointment_due_date) : '';
$form_content = $appointment['content'] ?? '';
$form_status = $appointment['status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } else {
        $form_subject = is_string($_POST['subject'] ?? null) ? trim($_POST['subject']) : '';
        $form_start_date = is_string($_POST['start_date'] ?? null) ? trim($_POST['start_date']) : '';
        $form_due_date = is_string($_POST['due_date'] ?? null) ? trim($_POST['due_date']) : '';
        $form_content = is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
        $form_status = is_string($_POST['status'] ?? null) ? $_POST['status'] : 'open';

        $allowed_statuses = ['open', 'done', 'cancelled'];
        $form_start_date = $form_start_date !== '' ? $form_start_date : $form_due_date;
        $start_date_object = appointment_parse_datetime_local($form_start_date);
        $due_date_object = appointment_parse_datetime_local($form_due_date);

        if ($form_subject === '') {
            $error = "Betreff darf nicht leer sein.";
        } elseif (strlen($form_subject) > 150) {
            $error = "Betreff darf maximal 150 Zeichen lang sein.";
        } elseif (strlen($form_content) > 10000) {
            $error = "Beschreibung darf maximal 10000 Zeichen lang sein.";
        } elseif ($form_start_date === '') {
            $error = "Startdatum darf nicht leer sein.";
        } elseif (!$start_date_object) {
            $error = "Startdatum ist ungueltig.";
        } elseif ($form_due_date === '') {
            $error = "Enddatum darf nicht leer sein.";
        } elseif (!$due_date_object) {
            $error = "Enddatum ist ungueltig.";
        } elseif ($due_date_object < $start_date_object) {
            $error = "Enddatum darf nicht vor dem Startdatum liegen.";
        } elseif (!in_array($form_status, $allowed_statuses, true)) {
            $error = "Ungueltiger Status.";
            $form_status = 'open';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE appointments
                    SET subject = :subject,
                        start_date = :start_date,
                        due_date = :due_date,
                        content = :content,
                        status = :status
                    WHERE id = :id AND user_id = :user_id
                ");

                $stmt->execute([
                    'subject' => $form_subject,
                    'start_date' => appointment_storage_datetime_value($start_date_object),
                    'due_date' => appointment_storage_datetime_value($due_date_object),
                    'content' => $form_content,
                    'status' => $form_status,
                    'id' => $id,
                    'user_id' => $user_id
                ]);

                header("Location: appointments.php");
                exit;
            } catch (PDOException $e) {
                $error = "Termin konnte nicht aktualisiert werden. Bitte versuchen Sie es spaeter erneut.";
            }
        }
    }
}

app_render_header('Termin bearbeiten', 'appointments', [
    'subtitle' => 'Bearbeite Betreff, Datum und Status.',
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
            <input type="text" name="subject" value="<?= e($form_subject) ?>" required maxlength="150">
        </label>

        <label>
            Startdatum
            <input type="datetime-local" name="start_date" value="<?= e($form_start_date) ?>" required>
        </label>

        <label>
            Enddatum / Faelligkeit
            <input type="datetime-local" name="due_date" value="<?= e($form_due_date) ?>" required>
        </label>

        <label>
            Status
            <select name="status">
                <option value="open" <?= $form_status === 'open' ? 'selected' : '' ?>>Offen</option>
                <option value="done" <?= $form_status === 'done' ? 'selected' : '' ?>>Erledigt</option>
                <option value="cancelled" <?= $form_status === 'cancelled' ? 'selected' : '' ?>>Abgebrochen</option>
            </select>
        </label>

        <label class="full">
            Beschreibung
            <textarea name="content" rows="8" maxlength="10000"><?= e($form_content) ?></textarea>
        </label>
    </div>

    <div class="form-actions">
        <button type="submit">Speichern</button>
    </div>
</form>

<?php app_render_footer(); ?>
