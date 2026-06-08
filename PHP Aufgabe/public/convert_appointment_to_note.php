<?php

// =======================================
// convert_appointment_to_note.php
// Zweck: Aus einem Termin eine Notiz erstellen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/response.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('appointments.php');
}

app_require_csrf('appointments.php');

$user_id = (int)$_SESSION['user_id'];
$appointment_id = $_POST['id'] ?? null;
$appointment_id = is_scalar($appointment_id) ? filter_var($appointment_id, FILTER_VALIDATE_INT) : false;

if ($appointment_id === false || $appointment_id <= 0) {
    app_redirect_error('appointments.php', 'Keine oder ungueltige Termin-ID angegeben.');
}

function appointment_status_label(string $status): string
{
    $labels = [
        'open' => 'Offen',
        'done' => 'Erledigt',
        'cancelled' => 'Abgebrochen'
    ];

    return $labels[$status] ?? $status;
}

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM appointments
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $appointment_id,
        'user_id' => $user_id
    ]);

    $appointment = $stmt->fetch();

    if (!$appointment) {
        app_redirect_error('appointments.php', 'Termin nicht gefunden oder kein Zugriff.');
    }

    $stmt = $pdo->prepare("
        SELECT c.target_id
        FROM conversions c
        INNER JOIN notes n ON n.id = c.target_id AND n.user_id = c.user_id
        WHERE c.user_id = :user_id
          AND c.source_type = 'appointment'
          AND c.source_id = :source_id
          AND c.target_type = 'note'
        LIMIT 1
    ");

    $stmt->execute([
        'user_id' => $user_id,
        'source_id' => $appointment_id
    ]);

    $existing_conversion = $stmt->fetch();

    if ($existing_conversion) {
        app_redirect_info('edit_note.php?id=' . (int)$existing_conversion['target_id'], 'Dieser Termin wurde bereits in eine Notiz umgewandelt.');
    }

    $due_date_timestamp = strtotime($appointment['due_date']);
    $due_date_text = $due_date_timestamp ? date('d.m.Y H:i', $due_date_timestamp) : $appointment['due_date'];
    $content_parts = [
        'Aus Termin erstellt',
        'Faelligkeit: ' . $due_date_text,
        'Status: ' . appointment_status_label($appointment['status'])
    ];

    if (trim((string)$appointment['content']) !== '') {
        $content_parts[] = '';
        $content_parts[] = $appointment['content'];
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO notes (user_id, title, content)
        VALUES (:user_id, :title, :content)
    ");

    $stmt->execute([
        'user_id' => $user_id,
        'title' => $appointment['subject'],
        'content' => implode("\n", $content_parts)
    ]);

    $note_id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO note_files (note_id, file_id)
        SELECT :note_id, file_id
        FROM appointment_files
        WHERE appointment_id = :appointment_id
    ");

    $stmt->execute([
        'note_id' => $note_id,
        'appointment_id' => $appointment_id
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO conversions (user_id, source_type, source_id, target_type, target_id)
        VALUES (:user_id, 'appointment', :source_id, 'note', :target_id)
    ");

    $stmt->execute([
        'user_id' => $user_id,
        'source_id' => $appointment_id,
        'target_id' => $note_id
    ]);

    $pdo->commit();

    app_redirect_success('edit_note.php?id=' . $note_id, 'Termin wurde in eine Notiz umgewandelt.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Termin konnte nicht in eine Notiz umgewandelt werden: " . $e->getMessage());
    app_redirect_error('appointments.php', 'Termin konnte nicht in eine Notiz umgewandelt werden.');
}
