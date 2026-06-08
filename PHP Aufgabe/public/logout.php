<?php

// =======================================
// logout.php
// Zweck: Benutzer abmelden
// =======================================


// Session starten, damit wir sie löschen können.
session_start();

// Sicherheits-Header
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

// Alle Session-Daten löschen.
session_unset();

// Session cookie löschen
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Session komplett zerstören.
session_destroy();

// Zur Login-Seite zurückleiten.
header("Location: login.php");
exit;