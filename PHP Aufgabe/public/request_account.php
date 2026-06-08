<?php

// =======================================
// request_account.php
// Zweck: Besucher koennen ein Konto anfragen
// =======================================

session_start();
require_once '../config/database.php';
require_once '../src/access_requests.php';

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
$full_name = '';
$username = '';
$email = '';
$message = '';

function public_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function public_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value) ? trim((string)$value) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';
    $full_name = public_post_string('full_name');
    $username = public_post_string('username');
    $email = public_post_string('email');
    $message = public_post_string('message');

    if (!is_string($posted_csrf_token) || !hash_equals($csrf_token, $posted_csrf_token)) {
        $error = 'Sicherheitsvalidierung fehlgeschlagen.';
    } elseif ($full_name === '' || strlen($full_name) > 150) {
        $error = 'Bitte einen Namen mit maximal 150 Zeichen eingeben.';
    } elseif ($username === '' || strlen($username) > 50 || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
        $error = 'Bitte einen Wunsch-Benutzernamen mit maximal 50 Zeichen eingeben.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $error = 'Bitte eine gueltige E-Mail-Adresse eingeben.';
    } elseif (strlen($message) > 1000) {
        $error = 'Die Nachricht darf maximal 1000 Zeichen lang sein.';
    } else {
        try {
            create_access_request($pdo, 'account', $full_name, $username, $email, $message !== '' ? $message : null);
            $success = 'Konto-Anfrage wurde gespeichert. Ein Admin kann sie im Usermanagement sehen.';
            $full_name = '';
            $username = '';
            $email = '';
            $message = '';
        } catch (Throwable $e) {
            error_log('Konto-Anfrage fehlgeschlagen: ' . $e->getMessage());
            $error = 'Anfrage konnte nicht gespeichert werden. Bitte spaeter erneut versuchen.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konto anfragen - MeinSchreibtisch</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-hero">
            <a class="brand-mark" href="login.php">MS</a>
            <h1>Konto anfragen</h1>
            <p>Schicke deine Daten an die Admin-Ansicht, damit ein Konto fuer dich erstellt werden kann.</p>
        </section>

        <section class="card login-card">
            <p class="eyebrow">Kein Konto</p>
            <h2>Zugang beantragen</h2>

            <?php if ($error): ?>
                <p class="message-error"><?= public_e($error) ?></p>
            <?php endif; ?>

            <?php if ($success): ?>
                <p class="message-success"><?= public_e($success) ?></p>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= public_e($csrf_token) ?>">

                <label>
                    Vollstaendiger Name
                    <input type="text" name="full_name" value="<?= public_e($full_name) ?>" required maxlength="150">
                </label>

                <label>
                    Wunsch-Benutzername
                    <input type="text" name="username" value="<?= public_e($username) ?>" required maxlength="50" pattern="[A-Za-z0-9_.-]+">
                </label>

                <label>
                    E-Mail
                    <input type="email" name="email" value="<?= public_e($email) ?>" required maxlength="150">
                </label>

                <label>
                    Nachricht
                    <textarea name="message" maxlength="1000"><?= public_e($message) ?></textarea>
                </label>

                <button type="submit">Anfrage senden</button>
            </form>

            <div class="login-links">
                <a href="login.php">Zurueck zum Login</a>
                <a href="setup.php">Erstinstallation</a>
            </div>
        </section>
    </main>
</body>
</html>
