<?php

// =======================================
// download_file.php
// Zweck: Sichere Datei-Downloads mit Zugriffskontrolle
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/response.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$file_id = $_GET['id'] ?? null;
$file_id = is_scalar($file_id) ? filter_var($file_id, FILTER_VALIDATE_INT) : false;

if ($file_id === false || $file_id <= 0) {
    app_fail('Ungueltige Datei-ID.', 400);
}

try {
    $stmt = $pdo->prepare("
        SELECT f.id, f.stored_name, f.file_path, f.mime_type, f.original_name
        FROM files f
        WHERE f.id = :file_id
          AND f.user_id = :user_id
    ");

    $stmt->execute([
        'file_id' => $file_id,
        'user_id' => $user_id
    ]);

    $file = $stmt->fetch();

    if (!$file) {
        app_fail('Zugriff verweigert.', 403);
    }

    $file_path = (string)($file['file_path'] ?? '');
    $stored_name = basename((string)($file['stored_name'] ?? ''));

    if ($stored_name === '' || !preg_match('#^uploads/(notes|appointments)/#', $file_path, $matches)) {
        app_fail('Ungueltiger Dateipfad.', 400);
    }

    $physical_path = __DIR__ . '/../uploads/' . $matches[1] . '/' . $stored_name;

    if (!is_file($physical_path) || !is_readable($physical_path)) {
        app_fail('Datei nicht gefunden.', 404);
    }

    $real_path = realpath($physical_path);
    $allowed_base = realpath(__DIR__ . '/../uploads/');

    if (!$real_path || !$allowed_base || strpos($real_path, $allowed_base) !== 0) {
        app_fail('Zugriff verweigert.', 403);
    }

    if (!is_file($real_path) || !is_readable($real_path)) {
        app_fail('Datei konnte nicht gelesen werden.', 404);
    }

    $file_size = filesize($real_path);

    if ($file_size === false) {
        app_fail('Datei konnte nicht gelesen werden.', 500);
    }

    $mime_type = (string)($file['mime_type'] ?? 'application/octet-stream');
    $original_name = str_replace(["\r", "\n", '"'], '', (string)($file['original_name'] ?? 'download'));

    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . $original_name . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($real_path);
    exit;
} catch (PDOException $e) {
    error_log("Download-Fehler: " . $e->getMessage());
    app_fail('Ein Fehler ist aufgetreten.', 500);
}
