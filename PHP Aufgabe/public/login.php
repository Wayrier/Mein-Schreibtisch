<?php

// =======================================
// login.php
// Zweck: Login-Formular anzeigen
// =======================================

session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

$timeout = 30 * 60;
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        session_unset();
        session_destroy();
    }
}

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// CSRF-Token generieren
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$error = $_GET['error'] ?? null;

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MeinSchreibtisch</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-hero">
            <a class="brand-mark" href="login.php" aria-label="Zur Startseite">MS</a>
            <h1>MeinSchreibtisch</h1>
            <p>Deine zentrale Oberflaeche fuer Notizen, Termine, Dateien und Backups.</p>
        </section>

        <section class="card login-card">
            <p class="eyebrow">Anmelden</p>
            <h2>Willkommen zurück</h2>

            <?php if ($error): ?>
                <?php if ($error === 'csrf'): ?>
                    <p class="message-error">Sicherheitsvalidierung fehlgeschlagen. Bitte versuchen Sie es erneut.</p>
                <?php elseif ($error === 'timeout'): ?>
                    <p class="message-error">Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.</p>
                <?php elseif ($error === 'locked'): ?>
                    <p class="message-error">Zu viele fehlgeschlagene Login-Versuche. Bitte warten Sie ein paar Minuten.</p>
                <?php elseif ($error === 'server'): ?>
                    <p class="message-error">Login ist gerade nicht verfuegbar. Bitte versuchen Sie es spaeter erneut.</p>
                <?php elseif ($error === 'session'): ?>
                    <p class="message-error">Ihre Sitzung ist nicht mehr gueltig. Bitte melden Sie sich erneut an.</p>
                <?php else: ?>
                    <p class="message-error">Benutzername oder Passwort ist falsch.</p>
                <?php endif; ?>
            <?php endif; ?>

            <form action="../src/auth.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                
                <label for="username">
                    Benutzername
                    <input type="text" id="username" name="username" required autofocus>
                </label>

                <label for="password">
                    Passwort
                    <input type="password" id="password" name="password" required>
                </label>

                <button type="submit">Einloggen</button>
            </form>

            <div class="login-links">
                <a href="request_account.php">Noch kein Konto?</a>
                <a href="forgot_password.php">Passwort vergessen?</a>
                <a href="setup.php">Erstinstallation</a>
            </div>
        </section>
    </main>
</body>
</html>
