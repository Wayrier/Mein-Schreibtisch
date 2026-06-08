<?php

// =======================================
// admin_check.php
// Zweck: Pruefen, ob Benutzer eingeloggt UND Admin ist
// =======================================

require_once __DIR__ . '/session_check.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../public/login.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    app_fail('Zugriff verweigert. Nur Admins duerfen diese Seite oeffnen.', 403);
}

try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute(['id' => (int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['role'] !== 'admin') {
        session_unset();
        session_destroy();
        app_fail('Zugriff verweigert. Admin-Rechte erloschen.', 403);
    }
} catch (PDOException $e) {
    error_log("Admin-Pruefung fehlgeschlagen: " . $e->getMessage());
    app_fail('Datenbank-Fehler. Bitte versuchen Sie es spaeter erneut.', 500);
}
