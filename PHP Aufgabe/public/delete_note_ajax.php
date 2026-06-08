<?php

// =======================================
// delete_note_ajax.php
// Zweck: Notiz via AJAX loeschen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/file_cleanup.php';

// AJAX-Endpunkt fuer notes.php.
// Antwortet immer mit JSON, damit JavaScript die Tabellenzeile entfernen kann.
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$posted_csrf_token = $_POST['csrf_token'] ?? '';
if (
    empty($_SESSION['csrf_token']) ||
    !is_string($posted_csrf_token) ||
    !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
) {
    echo json_encode(['success' => false]);
    exit;
}

$id = $_POST['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;

if ($id === false || $id <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    // Vor dem Loeschen merken, welche Dateien an der Notiz haengen.
    $files_for_cleanup = collect_note_files_for_cleanup($pdo, $user_id, $id);

    $pdo->beginTransaction();

    // Loescht nur eigene Notizen.
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
        // Nur die Conversion-Eintraege zur geloeschten Notiz entfernen.
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

        // Dateien nur dann aus files entfernen, wenn sie nicht mehr verknuepft sind.
        $paths_to_delete = delete_unlinked_file_records($pdo, $user_id, $files_for_cleanup);
    }

    $pdo->commit();

    // Physische Uploads erst nach erfolgreicher DB-Transaktion loeschen.
    delete_physical_uploaded_files($paths_to_delete);

    echo json_encode(['success' => $deleted]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode(['success' => false]);
}
