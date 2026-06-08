<?php

// =======================================
// preview_file.php
// Zweck: Sichere Inline-Vorschau fuer eigene Bilder/PDFs/Texte
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

$preview_mime_types = [
    'image/jpeg',
    'image/png',
    'application/pdf',
    'text/plain'
];

try {
    $stmt = $pdo->prepare("
        SELECT id, stored_name, file_path, mime_type, original_name
        FROM files
        WHERE id = :file_id
          AND user_id = :user_id
    ");

    $stmt->execute([
        'file_id' => $file_id,
        'user_id' => $user_id
    ]);

    $file = $stmt->fetch();

    if (!$file) {
        app_fail('Zugriff verweigert.', 403);
    }

    $mime_type = (string)($file['mime_type'] ?? 'application/octet-stream');

    if (!in_array($mime_type, $preview_mime_types, true)) {
        app_fail('Vorschau fuer diesen Dateityp nicht verfuegbar.', 415);
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

    $original_name = str_replace(["\r", "\n", '"'], '', (string)($file['original_name'] ?? 'vorschau'));

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: inline; filename="' . $original_name . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, no-store');

    readfile($real_path);
    exit;
} catch (PDOException $e) {
    error_log("Vorschau-Fehler: " . $e->getMessage());
    app_fail('Ein Fehler ist aufgetreten.', 500);
}
