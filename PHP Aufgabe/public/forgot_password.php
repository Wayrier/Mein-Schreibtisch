<?php

// =======================================
// forgot_password.php
// Zweck: Passwort-Reset-Link erzeugen
// =======================================

session_start();
require_once '../config/database.php';
require_once '../src/password_reset.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$error = null;
$success = null;
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';
    $posted_identifier = $_POST['identifier'] ?? '';
    $identifier = is_scalar($posted_identifier) ? trim((string)$posted_identifier) : '';

    if (!is_string($posted_csrf_token) || !hash_equals($csrf_token, $posted_csrf_token)) {
        $error = 'Sicherheitsvalidierung fehlgeschlagen.';
    } elseif ($identifier === '' || strlen($identifier) > 150) {
        $error = 'Bitte Benutzername oder E-Mail eingeben.';
    } else {
        try {
            $user = password_reset_find_user($pdo, $identifier);

            if ($user) {
                $reset = password_reset_create($pdo, (int)$user['id'], password_reset_ip());
                $reset_url = password_reset_url($reset['token']);
                password_reset_send_mail((string)$user['email'], $reset_url);
            }

            $success = 'Wenn ein Konto mit diesen Daten existiert, wurde ein Passwort-Reset-Link erstellt.';
            $identifier = '';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Passwort-Reset fehlgeschlagen: ' . $e->getMessage());
            $error = 'Reset-Link konnte nicht erstellt werden. Bitte spaeter erneut versuchen.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort vergessen - MeinSchreibtisch</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-hero">
            <a class="brand-mark" href="login.php">MS</a>
            <h1>Passwort vergessen</h1>
            <p>Erzeuge einen zeitlich begrenzten Reset-Link fuer dein Konto.</p>
        </section>

        <section class="card login-card">
            <p class="eyebrow">Reset</p>
            <h2>Passwort zuruecksetzen</h2>

            <?php if ($error): ?>
                <p class="message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if ($success): ?>
                <p class="message-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                <label>
                    Benutzername oder E-Mail
                    <input type="text" name="identifier" value="<?= htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8') ?>" required maxlength="150">
                </label>

                <button type="submit">Reset-Link senden</button>
            </form>

            <div class="login-links">
                <a href="login.php">Zurueck zum Login</a>
                <a href="request_account.php">Kein Konto?</a>
            </div>
        </section>
    </main>
</body>
</html>
