<?php

// =======================================
// reset_password.php
// Zweck: Passwort per Reset-Token neu setzen
// =======================================

session_start();
require_once '../config/database.php';
require_once '../src/password_reset.php';
require_once '../src/password_rules.php';

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
$token_value = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = is_scalar($token_value) ? trim((string)$token_value) : '';
$error = null;
$success = null;
$reset = null;

try {
    $reset = password_reset_lookup($pdo, $token);
} catch (Throwable $e) {
    error_log('Reset-Token konnte nicht geladen werden: ' . $e->getMessage());
}

if ($token === '' || !$reset) {
    $error = 'Der Reset-Link ist ungueltig oder abgelaufen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';
    $posted_password = $_POST['password'] ?? '';
    $posted_password_confirm = $_POST['password_confirm'] ?? '';
    $password = is_scalar($posted_password) ? (string)$posted_password : '';
    $password_confirm = is_scalar($posted_password_confirm) ? (string)$posted_password_confirm : '';

    if (!is_string($posted_csrf_token) || !hash_equals($csrf_token, $posted_csrf_token)) {
        $error = 'Sicherheitsvalidierung fehlgeschlagen.';
    } elseif (($password_error = password_policy_error($password, 'Das neue Passwort')) !== null) {
        $error = $password_error;
    } elseif ($password !== $password_confirm) {
        $error = 'Die Passwoerter stimmen nicht ueberein.';
    } else {
        try {
            if (password_reset_apply($pdo, $token, password_hash($password, PASSWORD_DEFAULT))) {
                $success = 'Passwort wurde geaendert. Du kannst dich jetzt anmelden.';
                $reset = null;
                unset($_SESSION['csrf_token']);
            } else {
                $error = 'Der Reset-Link ist ungueltig oder abgelaufen.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Passwort konnte per Reset nicht geaendert werden: ' . $e->getMessage());
            $error = 'Passwort konnte nicht geaendert werden. Bitte spaeter erneut versuchen.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neues Passwort - MeinSchreibtisch</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-hero">
            <a class="brand-mark" href="login.php">MS</a>
            <h1>Neues Passwort</h1>
            <p>Der Link ist zeitlich begrenzt und kann nur einmal verwendet werden.</p>
        </section>

        <section class="card login-card">
            <p class="eyebrow">Reset</p>
            <h2>Passwort setzen</h2>

            <?php if ($error): ?>
                <p class="message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if ($success): ?>
                <p class="message-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if ($reset): ?>
                <p class="message-info">Konto: <?= htmlspecialchars((string)$reset['username'], ENT_QUOTES, 'UTF-8') ?></p>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                    <label>
                        Neues Passwort
                        <input type="password" name="password" required minlength="<?= password_policy_min_length() ?>" pattern="<?= htmlspecialchars(password_policy_pattern(), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(password_policy_hint(), ENT_QUOTES, 'UTF-8') ?>" autocomplete="new-password">
                        <small><?= htmlspecialchars(password_policy_hint(), ENT_QUOTES, 'UTF-8') ?></small>
                    </label>

                    <label>
                        Neues Passwort wiederholen
                        <input type="password" name="password_confirm" required minlength="<?= password_policy_min_length() ?>" pattern="<?= htmlspecialchars(password_policy_pattern(), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(password_policy_hint(), ENT_QUOTES, 'UTF-8') ?>" autocomplete="new-password">
                    </label>

                    <button type="submit">Passwort speichern</button>
                </form>
            <?php endif; ?>

            <div class="login-links">
                <a href="login.php">Zurueck zum Login</a>
                <a href="forgot_password.php">Neuen Link anfordern</a>
            </div>
        </section>
    </main>
</body>
</html>
