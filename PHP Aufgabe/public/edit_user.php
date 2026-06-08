<?php

// =======================================
// edit_user.php
// Zweck: Benutzer bearbeiten
// =======================================

require_once '../src/admin_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/password_rules.php';
require_once '../src/response.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$id = $_GET['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;
$error = null;

if ($id === false || $id <= 0) {
    app_redirect_error('users.php', 'Keine oder ungueltige Benutzer-ID angegeben.');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();

    if (!$user) {
        app_redirect_error('users.php', 'Benutzer nicht gefunden.');
    }
} catch (PDOException $e) {
    error_log("Benutzer konnte nicht geladen werden: " . $e->getMessage());
    app_redirect_error('users.php', 'Fehler beim Laden des Benutzers. Bitte versuchen Sie es spaeter erneut.');
}

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
        $posted_role = $_POST['role'] ?? '';
        $posted_new_password = $_POST['new_password'] ?? '';
        $posted_password_confirm = $_POST['password_confirm'] ?? '';
        $username = is_scalar($posted_username) ? trim((string)$posted_username) : '';
        $email = is_scalar($posted_email) ? trim((string)$posted_email) : '';
        $role = is_scalar($posted_role) ? (string)$posted_role : '';
        $new_password = is_scalar($posted_new_password) ? (string)$posted_new_password : '';
        $password_confirm = is_scalar($posted_password_confirm) ? (string)$posted_password_confirm : '';

        if ($username === '' || $email === '') {
            $error = "Bitte alle Pflichtfelder ausfuellen.";
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $error = "Benutzername muss zwischen 3 und 50 Zeichen lang sein.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Gueltige Email-Adresse erforderlich.";
        } elseif (!in_array($role, ['admin', 'standard'], true)) {
            $error = "Ungueltige Rolle.";
        } elseif ($new_password !== '' && ($password_error = password_policy_error($new_password, 'Das neue Passwort')) !== null) {
            $error = $password_error;
        } elseif ($new_password !== $password_confirm) {
            $error = "Die neuen Passwoerter stimmen nicht ueberein.";
        } else {
            try {
                $is_admin_downgrade = (string)$user['role'] === 'admin' && $role === 'standard';

                if ($is_admin_downgrade && (int)$id === (int)$_SESSION['user_id']) {
                    $error = "Du kannst deine eigene Admin-Rolle nicht entfernen.";
                } elseif ($is_admin_downgrade) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS admin_count
                        FROM users
                        WHERE role = 'admin' AND id <> :id
                    ");
                    $stmt->execute(['id' => $id]);

                    if ((int)($stmt->fetch()['admin_count'] ?? 0) === 0) {
                        $error = "Der letzte Admin darf nicht auf Standard gesetzt werden.";
                    }
                }

                if ($error === null) {
                    $stmt = $pdo->prepare("
                    SELECT username, email
                    FROM users
                    WHERE (username = :username OR email = :email)
                      AND id <> :id
                    LIMIT 1
                    ");
                    $stmt->execute([
                        'username' => $username,
                        'email' => $email,
                        'id' => $id
                    ]);
                    $existing_user = $stmt->fetch();

                    if ($existing_user) {
                        if (strcasecmp((string)$existing_user['username'], $username) === 0) {
                            $error = "Benutzername ist bereits vorhanden.";
                        } else {
                            $error = "Email-Adresse ist bereits vorhanden.";
                        }
                    } else {
                        $params = [
                            'username' => $username,
                            'email' => $email,
                            'role' => $role,
                            'id' => $id
                        ];

                        $password_sql = '';

                        if ($new_password !== '') {
                            $password_sql = ', password_hash = :password_hash';
                            $params['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
                        }

                        $stmt = $pdo->prepare("
                        UPDATE users
                        SET username = :username,
                            email = :email,
                            role = :role
                            {$password_sql}
                        WHERE id = :id
                        ");

                        $stmt->execute($params);

                        header("Location: users.php");
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = "Benutzername oder Email-Adresse bereits vorhanden.";
                } else {
                    error_log('Benutzer konnte nicht aktualisiert werden: ' . $e->getMessage());
                    $error = "Benutzer konnte nicht aktualisiert werden. Bitte versuchen Sie es spaeter erneut.";
                }
            }
        }
    }
}

app_render_header('Benutzer bearbeiten', 'users', [
    'subtitle' => 'Aendere Kontodaten und Rolle.',
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
            Benutzername
            <input type="text" name="username" value="<?= e($user['username']) ?>" required minlength="3" maxlength="50">
        </label>

        <label>
            Email
            <input type="email" name="email" value="<?= e($user['email']) ?>" required>
        </label>

        <label>
            Rolle
            <select name="role">
                <option value="standard" <?= $user['role'] === 'standard' ? 'selected' : '' ?>>Standard</option>
                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </label>

        <label>
            Neues Passwort
            <input type="password" name="new_password" minlength="<?= password_policy_min_length() ?>" pattern="<?= e(password_policy_pattern()) ?>" title="<?= e(password_policy_hint()) ?>" autocomplete="new-password">
            <small>Leer lassen, wenn das Passwort gleich bleiben soll. <?= e(password_policy_hint()) ?></small>
        </label>

        <label>
            Neues Passwort wiederholen
            <input type="password" name="password_confirm" minlength="<?= password_policy_min_length() ?>" pattern="<?= e(password_policy_pattern()) ?>" title="<?= e(password_policy_hint()) ?>" autocomplete="new-password">
        </label>
    </div>

    <div class="form-actions">
        <button type="submit">Speichern</button>
    </div>
</form>

<?php app_render_footer(); ?>
