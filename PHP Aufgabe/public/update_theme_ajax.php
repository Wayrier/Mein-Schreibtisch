<?php

// =======================================
// update_theme_ajax.php
// Zweck: Theme direkt aus der Sidebar speichern
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/response.php';

header('Content-Type: application/json; charset=utf-8');

function send_theme_json(array $data, int $status_code = 200): void
{
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    send_theme_json(['success' => false], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_theme_json(['success' => false], 405);
}

if (!app_csrf_is_valid($_POST['csrf_token'] ?? '')) {
    send_theme_json(['success' => false], 403);
}

$posted_theme = $_POST['theme'] ?? '';
$theme = is_scalar($posted_theme) ? (string)$posted_theme : '';

if (!in_array($theme, ['light', 'dark'], true)) {
    send_theme_json(['success' => false], 400);
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO user_settings (user_id, theme)
        VALUES (:user_id, :theme)
        ON DUPLICATE KEY UPDATE theme = VALUES(theme)
    ");
    $stmt->execute([
        'user_id' => (int)$_SESSION['user_id'],
        'theme' => $theme
    ]);

    $_SESSION['theme'] = $theme;

    send_theme_json([
        'success' => true,
        'theme' => $theme
    ]);
} catch (PDOException $e) {
    error_log('Theme konnte nicht gespeichert werden: ' . $e->getMessage());
    send_theme_json(['success' => false], 500);
}
