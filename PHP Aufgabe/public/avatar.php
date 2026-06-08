<?php

// =======================================
// avatar.php
// Zweck: Eigenes Profilbild sicher ausliefern
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/avatar.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    ensure_avatar_column($pdo);

    $stmt = $pdo->prepare("SELECT avatar_path FROM users WHERE id = :id");
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch();

    $real_path = resolve_avatar_path($user['avatar_path'] ?? null);

    if (!$real_path || !is_file($real_path) || !is_readable($real_path)) {
        http_response_code(404);
        exit;
    }

    $file_size = filesize($real_path);

    if ($file_size === false || $file_size <= 0) {
        http_response_code(404);
        exit;
    }

    if ($file_size > avatar_max_file_size()) {
        http_response_code(413);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = $finfo ? finfo_file($finfo, $real_path) : false;

    if ($finfo) {
        finfo_close($finfo);
    }

    if (!array_key_exists((string)$mime_type, avatar_allowed_mime_types())) {
        http_response_code(415);
        exit;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, max-age=300');

    readfile($real_path);
    exit;
} catch (PDOException $e) {
    error_log('Avatar konnte nicht geladen werden: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
