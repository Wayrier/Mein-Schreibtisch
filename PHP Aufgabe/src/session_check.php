<?php

// =======================================
// session_check.php
// Zweck: Session-Validierung und Security Headers
//        Zentrale Stelle statt Code-Duplikation
// =======================================

// Session starten (falls nicht bereits gestartet)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function app_session_destroy_and_redirect(string $error): void
{
    session_unset();
    session_destroy();
    header('Location: ../public/login.php?error=' . rawurlencode($error));
    exit;
}

// Sicherheits-Header setzen
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// Session-Timeout (30 Minuten)
$session_timeout = 30 * 60;

if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $session_timeout) {
        app_session_destroy_and_redirect('timeout');
    }
}

$_SESSION['last_activity'] = time();

// CSRF-Token generieren (falls nicht vorhanden)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/database.php';

    try {
        $stmt = $pdo->prepare("
            SELECT id, username, role, avatar_path
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int)$_SESSION['user_id']]);
        $session_user = $stmt->fetch();

        if (!$session_user) {
            app_session_destroy_and_redirect('session');
        }

        $_SESSION['username'] = (string)$session_user['username'];
        $_SESSION['role'] = (string)$session_user['role'];
        $_SESSION['avatar_path'] = (string)($session_user['avatar_path'] ?? '');
        $_SESSION['avatar_loaded'] = true;
    } catch (PDOException $e) {
        error_log('Session-Benutzer konnte nicht validiert werden: ' . $e->getMessage());
        app_session_destroy_and_redirect('server');
    }
}
