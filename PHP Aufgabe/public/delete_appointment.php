<?php

// =======================================
// delete_appointment.php
// Zweck: Eigenen Termin loeschen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/file_cleanup.php';
require_once '../src/response.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

app_require_post('appointments.php');
app_require_csrf('appointments.php');

$id = $_POST['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;

if ($id === false || $id <= 0) {
    app_redirect_error('appointments.php', 'Keine oder ungueltige Termin-ID angegeben.');
}

$user_id = (int)$_SESSION['user_id'];

try {
    // Dateien vor dem Loeschen merken, weil appointment_files per Foreign Key
    // automatisch entfernt wird.
    $files_for_cleanup = collect_appointment_files_for_cleanup($pdo, $user_id, $id);

    $pdo->beginTransaction();

    // Termin nur loeschen, wenn er wirklich dem eingeloggten User gehoert.
    $stmt = $pdo->prepare("
        DELETE FROM appointments
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $id,
        'user_id' => $user_id
    ]);

    $deleted = $stmt->rowCount() > 0;
    $paths_to_delete = [];

    if ($deleted) {
        // Conversion-Historie zum geloeschten Termin entfernen,
        // ohne die daraus entstandene Notiz oder den Ursprung zu loeschen.
        $stmt = $pdo->prepare("
            DELETE FROM conversions
            WHERE user_id = :user_id
              AND (
                  (source_type = 'appointment' AND source_id = :source_id)
                  OR
                  (target_type = 'appointment' AND target_id = :target_id)
              )
        ");

        $stmt->execute([
            'user_id' => $user_id,
            'source_id' => $id,
            'target_id' => $id
        ]);

        // Nur unbenutzte Dateien aus der DB loeschen.
        $paths_to_delete = delete_unlinked_file_records($pdo, $user_id, $files_for_cleanup);
    }

    $pdo->commit();

    // Erst nach erfolgreicher DB-Transaktion werden Dateien vom Dateisystem geloescht.
    delete_physical_uploaded_files($paths_to_delete);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Termin konnte nicht geloescht werden: " . $e->getMessage());
    app_redirect_error('appointments.php', 'Termin konnte nicht geloescht werden. Bitte versuchen Sie es spaeter erneut.');
}

if ($deleted) {
    app_redirect_success('appointments.php', 'Termin wurde geloescht.');
}

app_redirect_error('appointments.php', 'Termin nicht gefunden oder kein Zugriff.');
