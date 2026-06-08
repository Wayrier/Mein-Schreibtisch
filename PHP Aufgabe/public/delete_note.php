<?php

// =======================================
// delete_note.php
// Zweck: Notiz loeschen
// =======================================

require_once '../src/session_check.php';

require_once '../config/database.php';
require_once '../src/file_cleanup.php';
require_once '../src/response.php';

// Diese Seite ist der klassische POST-Fallback fuer das Loeschen einer Notiz.
// notes.php nutzt normalerweise AJAX, aber ohne JavaScript funktioniert dieser Weg.

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

app_require_post('notes.php');
app_require_csrf('notes.php');

$id = $_POST['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;

if ($id === false || $id <= 0) {
    app_redirect_error('notes.php', 'Keine oder ungueltige Notiz-ID angegeben.');
}

$user_id = (int)$_SESSION['user_id'];

try {
    // Dateien vor dem Loeschen merken, weil die note_files-Zeilen danach
    // durch Foreign Keys automatisch verschwinden.
    $files_for_cleanup = collect_note_files_for_cleanup($pdo, $user_id, $id);

    $pdo->beginTransaction();

    // Die Notiz selbst wird nur geloescht, wenn sie dem eingeloggten User gehoert.
    $stmt = $pdo->prepare("
        DELETE FROM notes
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $id,
        'user_id' => $user_id
    ]);

    $deleted = $stmt->rowCount() > 0;
    $paths_to_delete = [];

    if ($deleted) {
        // Conversion-Historie zur geloeschten Notiz entfernen.
        // Das Zielobjekt selbst wird dabei nicht geloescht.
        $stmt = $pdo->prepare("
            DELETE FROM conversions
            WHERE user_id = :user_id
              AND (
                  (source_type = 'note' AND source_id = :source_id)
                  OR
                  (target_type = 'note' AND target_id = :target_id)
              )
        ");

        $stmt->execute([
            'user_id' => $user_id,
            'source_id' => $id,
            'target_id' => $id
        ]);

        // Nur Dateien aus files entfernen, die nirgendwo mehr verknuepft sind.
        $paths_to_delete = delete_unlinked_file_records($pdo, $user_id, $files_for_cleanup);
    }

    $pdo->commit();

    // Physische Dateien erst nach erfolgreichem DB-Commit loeschen.
    delete_physical_uploaded_files($paths_to_delete);

    if ($deleted) {
        app_redirect_success('notes.php', 'Notiz wurde geloescht.');
    }

    app_redirect_error('notes.php', 'Notiz nicht gefunden oder kein Zugriff.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Notiz konnte nicht geloescht werden: " . $e->getMessage());
    app_redirect_error('notes.php', 'Notiz konnte nicht geloescht werden. Bitte versuchen Sie es spaeter erneut.');
}
