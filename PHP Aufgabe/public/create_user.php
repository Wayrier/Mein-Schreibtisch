<?php

// =======================================
// create_user.php
// Zweck: Neuer Benutzer wird vom Admin angelegt
// =======================================

require_once '../src/admin_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/password_rules.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } else {
        $posted_username = $_POST['username'] ?? '';
        $posted_email = $_POST['email'] ?? '';
        $posted_password = $_POST['password'] ?? '';
        $posted_password_confirm = $_POST['password_confirm'] ?? '';
        $posted_role = $_POST['role'] ?? 'standard';
        $posted_full_name = $_POST['full_name'] ?? '';

        $username = is_scalar($posted_username) ? trim((string)$posted_username) : '';
        $email = is_scalar($posted_email) ? trim((string)$posted_email) : '';
        $password = is_scalar($posted_password) ? (string)$posted_password : '';
        $password_confirm = is_scalar($posted_password_confirm) ? (string)$posted_password_confirm : '';
        $role = is_scalar($posted_role) ? (string)$posted_role : 'standard';
        $full_name = is_scalar($posted_full_name) ? trim((string)$posted_full_name) : '';

        if ($username === '' || $email === '' || $password === '') {
            $error = "Bitte Benutzername, Email und Passwort ausfuellen.";
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $error = "Benutzername muss zwischen 3 und 50 Zeichen lang sein.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Gueltige Email-Adresse erforderlich.";
        } elseif (($password_error = password_policy_error($password)) !== null) {
            $error = $password_error;
        } elseif ($password !== $password_confirm) {
            $error = "Passwoerter stimmen nicht ueberein.";
        } elseif (!in_array($role, ['admin', 'standard'], true)) {
            $error = "Ungueltige Rolle.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT username, email
                    FROM users
                    WHERE username = :username OR email = :email
                    LIMIT 1
                ");
                $stmt->execute([
                    'username' => $username,
                    'email' => $email
                ]);
                $existing_user = $stmt->fetch();

                if ($existing_user) {
                    if (strcasecmp((string)$existing_user['username'], $username) === 0) {
                        $error = "Benutzername ist bereits vorhanden.";
                    } else {
                        $error = "Email-Adresse ist bereits vorhanden.";
                    }
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, email, password_hash, role, full_name)
                        VALUES (:username, :email, :password_hash, :role, :full_name)
                    ");

                    $stmt->execute([
                        'username' => $username,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role' => $role,
                        'full_name' => $full_name
                    ]);

                    header("Location: users.php");
                    exit;
                }
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = "Benutzername oder Email-Adresse bereits vorhanden.";
                } else {
                    error_log('Benutzer konnte nicht erstellt werden: ' . $e->getMessage());
                    $error = "Benutzer konnte nicht erstellt werden. Bitte versuchen Sie es spaeter erneut.";
                }
            }
        }
    }
}

app_render_header('Neuer Benutzer', 'users', [
    'subtitle' => 'Lege ein neues Konto fuer die Anwendung an.',
    'actions' => '<a class="button button-secondary" href="users.php">&larr; Zurueck</a>'
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= e($error) ?></p>
<?php endif; ?>

<form method="POST" class="panel">
    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

    <div class="form-grid">
        <label>
            Vollstaendiger Name
            <input type="text" name="full_name">
        </label>

        <label>
            Benutzername
            <input type="text" name="username" required minlength="3" maxlength="50">
        </label>

        <label>
            Email
            <input type="email" name="email" required>
        </label>

        <label>
            Rolle
            <select name="role">
                <option value="standard">Standard</option>
                <option value="admin">Admin</option>
            </select>
        </label>

        <label>
            Passwort
            <input type="password" name="password" required minlength="<?= password_policy_min_length() ?>" pattern="<?= e(password_policy_pattern()) ?>" title="<?= e(password_policy_hint()) ?>">
            <small><?= e(password_policy_hint()) ?></small>
        </label>

        <label>
            Passwort wiederholen
            <input type="password" name="password_confirm" required minlength="<?= password_policy_min_length() ?>" pattern="<?= e(password_policy_pattern()) ?>" title="<?= e(password_policy_hint()) ?>">
        </label>
    </div>

    <div class="form-actions">
        <button type="submit">Benutzer erstellen</button>
    </div>
</form>

<?php app_render_footer(); ?>
