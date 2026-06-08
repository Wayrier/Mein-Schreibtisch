<?php

// =======================================
// create_appointment_ajax.php
// Zweck: Neuen Termin via AJAX erstellen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/appointment_dates.php';

header('Content-Type: application/json');
function send_json(array $data): void
{
    echo json_encode($data);
    exit;
}

function appointment_response(array $appointment): array
{
    $start_date_value = (string)($appointment['start_date'] ?? $appointment['due_date']);
    $start_date_timestamp = strtotime($start_date_value);
    $due_date_timestamp = strtotime($appointment['due_date']);

    return [
        'id' => (int)$appointment['id'],
        'subject' => $appointment['subject'],
        'start_date' => $start_date_timestamp ? date('d.m.Y H:i', $start_date_timestamp) : $start_date_value,
        'start_date_input' => $start_date_timestamp ? date('Y-m-d\TH:i', $start_date_timestamp) : '',
        'start_date_sort' => $start_date_timestamp ?: 0,
        'due_date' => $due_date_timestamp ? date('d.m.Y H:i', $due_date_timestamp) : $appointment['due_date'],
        'due_date_input' => $due_date_timestamp ? date('Y-m-d\TH:i', $due_date_timestamp) : '',
        'due_date_sort' => $due_date_timestamp ?: 0,
        'content' => $appointment['content'] ?? '',
        'status' => $appointment['status']
    ];
}

if (!isset($_SESSION['user_id'])) {
    send_json([
        'success' => false,
        'message' => 'Bitte zuerst einloggen.'
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json([
        'success' => false,
        'message' => 'Ungueltige Anfrage.'
    ]);
}

$posted_csrf_token = $_POST['csrf_token'] ?? '';
if (
    empty($_SESSION['csrf_token']) ||
    !is_string($posted_csrf_token) ||
    !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
) {
    send_json([
        'success' => false,
        'message' => 'Sicherheitsvalidierung fehlgeschlagen.'
    ]);
}

appointment_ensure_start_date_column($pdo);

$posted_subject = $_POST['subject'] ?? '';
$posted_start_date = $_POST['start_date'] ?? '';
$posted_due_date = $_POST['due_date'] ?? '';
$posted_content = $_POST['content'] ?? '';
$posted_status = $_POST['status'] ?? 'open';

$subject = is_scalar($posted_subject) ? trim((string)$posted_subject) : '';
$start_date = is_scalar($posted_start_date) ? trim((string)$posted_start_date) : '';
$due_date = is_scalar($posted_due_date) ? trim((string)$posted_due_date) : '';
$content = is_scalar($posted_content) ? trim((string)$posted_content) : '';
$status = is_scalar($posted_status) ? (string)$posted_status : 'open';

$allowed_statuses = ['open', 'done', 'cancelled'];
$start_date = $start_date !== '' ? $start_date : $due_date;
$start_date_object = appointment_parse_datetime_local($start_date);
$due_date_object = appointment_parse_datetime_local($due_date);

if ($subject === '') {
    send_json([
        'success' => false,
        'message' => 'Betreff darf nicht leer sein.'
    ]);
}

if (strlen($subject) > 150) {
    send_json([
        'success' => false,
        'message' => 'Betreff darf maximal 150 Zeichen lang sein.'
    ]);
}

if (strlen($content) > 10000) {
    send_json([
        'success' => false,
        'message' => 'Beschreibung darf maximal 10000 Zeichen lang sein.'
    ]);
}

if ($start_date === '') {
    send_json([
        'success' => false,
        'message' => 'Startdatum darf nicht leer sein.'
    ]);
}

if (!$start_date_object) {
    send_json([
        'success' => false,
        'message' => 'Startdatum ist ungueltig.'
    ]);
}

if ($due_date === '') {
    send_json([
        'success' => false,
        'message' => 'Enddatum darf nicht leer sein.'
    ]);
}

if (!$due_date_object) {
    send_json([
        'success' => false,
        'message' => 'Enddatum ist ungueltig.'
    ]);
}

if ($due_date_object < $start_date_object) {
    send_json([
        'success' => false,
        'message' => 'Enddatum darf nicht vor dem Startdatum liegen.'
    ]);
}

if (!in_array($status, $allowed_statuses, true)) {
    send_json([
        'success' => false,
        'message' => 'Ungueltiger Status.'
    ]);
}

$user_id = $_SESSION['user_id'];

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

    $appointment_id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        SELECT id, subject, start_date, due_date, content, status
        FROM appointments
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $appointment_id,
        'user_id' => $user_id
    ]);

    $appointment = $stmt->fetch();

    if (!$appointment) {
        send_json([
            'success' => false,
            'message' => 'Termin konnte nicht geladen werden.'
        ]);
    }

    send_json([
        'success' => true,
        'appointment' => appointment_response($appointment)
    ]);
} catch (PDOException $e) {
    send_json([
        'success' => false,
        'message' => 'Termin konnte nicht gespeichert werden. Bitte versuchen Sie es spaeter erneut.'
    ]);
}
