<?php

// =======================================
// setup.php
// Zweck: Erstinstallation fuer Datenbank und ersten Admin
// =======================================

session_start();

define('MEIN_SCHREIBTISCH_SKIP_DB_CONNECT', true);
require_once '../config/database.php';
require_once '../src/password_rules.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

if (empty($_SESSION['setup_csrf_token'])) {
    $_SESSION['setup_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['setup_csrf_token'];
$error = null;
$success = null;
$locked = false;

function setup_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function setup_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value) ? trim((string)$value) : '';
}

function setup_server_connection(string $host, string $charset, string $user, string $pass, array $options): PDO
{
    return new PDO("mysql:host=$host;charset=$charset", $user, $pass, $options);
}

function setup_select_database(PDO $pdo, string $db): void
{
    $safe_db = str_replace('`', '``', $db);
    $pdo->exec("USE `{$safe_db}`");
}

function setup_execute_schema(PDO $pdo, string $schema_path): void
{
    $sql = file_get_contents($schema_path);

    if ($sql === false) {
        throw new RuntimeException('schema.sql konnte nicht gelesen werden.');
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);

    foreach ($statements as $statement) {
        $statement = trim($statement);

        if ($statement === '') {
            continue;
        }

        $pdo->exec($statement);
    }
}

function setup_user_count(PDO $pdo): int
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) AS user_count FROM users");
        $row = $stmt->fetch();

        return (int)($row['user_count'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function setup_is_locked(string $host, string $db, string $charset, string $user, string $pass, array $options): bool
{
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, $options);

        return setup_user_count($pdo) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$locked = setup_is_locked($host, $db, $charset, $user, $pass, $options);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';
    $username = setup_post_string('username');
    $email = setup_post_string('email');
    $full_name = setup_post_string('full_name');
    $password = $_POST['password'] ?? '';
    $password = is_scalar($password) ? (string)$password : '';

    if (!is_string($posted_csrf_token) || !hash_equals($csrf_token, $posted_csrf_token)) {
        $error = 'Sicherheitsvalidierung fehlgeschlagen.';
    } elseif ($username === '' || strlen($username) > 50 || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
        $error = 'Bitte einen Benutzernamen mit maximal 50 Zeichen eingeben. Erlaubt sind Buchstaben, Zahlen, Punkt, Bindestrich und Unterstrich.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $error = 'Bitte eine gueltige E-Mail-Adresse eingeben.';
    } elseif (($password_error = password_policy_error($password, 'Das Passwort')) !== null) {
        $error = $password_error;
    } elseif ($locked) {
        $error = 'Setup ist gesperrt, weil bereits Benutzer vorhanden sind.';
    } else {
        try {
            $pdo = setup_server_connection($host, $charset, $user, $pass, $options);
            setup_execute_schema($pdo, __DIR__ . '/../database/schema.sql');
            setup_select_database($pdo, $db);

            if (setup_user_count($pdo) > 0) {
                $locked = true;
                $error = 'Setup ist gesperrt, weil bereits Benutzer vorhanden sind.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, role, full_name)
                    VALUES (:username, :email, :password_hash, 'admin', :full_name)
                ");

                $stmt->execute([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $full_name !== '' ? $full_name : null
                ]);

                $admin_id = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO user_settings (user_id) VALUES (:user_id)");
                $stmt->execute(['user_id' => $admin_id]);

                unset($_SESSION['setup_csrf_token']);
                $csrf_token = bin2hex(random_bytes(32));
                $_SESSION['setup_csrf_token'] = $csrf_token;
                $locked = true;
                $success = 'Setup abgeschlossen. Der erste Admin wurde erstellt.';
            }
        } catch (Throwable $e) {
            error_log('Setup fehlgeschlagen: ' . $e->getMessage());
            $error = 'Setup konnte nicht abgeschlossen werden. Bitte MySQL/XAMPP und die Zugangsdaten pruefen.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - MeinSchreibtisch</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-hero">
            <a class="brand-mark" href="login.php">MS</a>
            <h1>Setup</h1>
            <p>Datenbankstruktur anlegen und den ersten Admin-Benutzer erstellen.</p>
        </section>

        <section class="card login-card">
            <p class="eyebrow">Erstinstallation</p>
            <h2>MeinSchreibtisch einrichten</h2>

            <?php if ($error): ?>
                <p class="message-error"><?= setup_e($error) ?></p>
            <?php endif; ?>

            <?php if ($success): ?>
                <p class="message-success"><?= setup_e($success) ?></p>
                <a class="button" href="login.php">Zum Login</a>
            <?php elseif ($locked): ?>
                <p class="message-success">Setup ist bereits abgeschlossen, weil mindestens ein Benutzer existiert.</p>
                <a class="button" href="login.php">Zum Login</a>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= setup_e($csrf_token) ?>">

                    <label>
                        Benutzername
                        <input type="text" name="username" required maxlength="50" pattern="[A-Za-z0-9_.-]+">
                    </label>
                    <br><br>

                    <label>
                        E-Mail
                        <input type="email" name="email" required maxlength="150">
                    </label>
                    <br><br>

                    <label>
                        Voller Name
                        <input type="text" name="full_name" maxlength="150">
                    </label>
                    <br><br>

                    <label>
                        Passwort
                        <input type="password" name="password" required minlength="<?= password_policy_min_length() ?>" pattern="<?= setup_e(password_policy_pattern()) ?>" title="<?= setup_e(password_policy_hint()) ?>">
                        <small><?= setup_e(password_policy_hint()) ?></small>
                    </label>
                    <br><br>

                    <button type="submit">Setup ausfuehren</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
