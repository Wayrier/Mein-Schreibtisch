<?php

// =======================================
// delete_user_ajax.php
// Zweck: Benutzer via AJAX loeschen (Admin only)
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/file_cleanup.php';

// AJAX-Endpunkt fuer users.php.
// Nur Admins duerfen diesen Endpunkt verwenden.
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $admin = $stmt->fetch();

    if (!$admin || $admin['role'] !== 'admin') {
        echo json_encode(['success' => false]);
        exit;
    }
} catch (PDOException $e) {
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
$current_user_id = (int)$_SESSION['user_id'];

// Admin darf das eigene Konto nicht per AJAX loeschen.
if ($id === false || $id <= 0 || $id === $current_user_id) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    // Pfade vor dem Benutzer-Loeschen merken.
    // Die DB-Zeilen verschwinden per ON DELETE CASCADE, die Dateien nicht.
    $files_for_cleanup = collect_user_files_for_cleanup($pdo, $id);
    $paths_to_delete = file_cleanup_paths($files_for_cleanup);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute(['id' => $id]);

    $deleted = $stmt->rowCount() > 0;
    $pdo->commit();

    if ($deleted) {
        // Dateien erst nach erfolgreichem DB-Commit vom Dateisystem entfernen.
        delete_physical_uploaded_files($paths_to_delete);
    }

    echo json_encode(['success' => $deleted]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode(['success' => false]);
}
