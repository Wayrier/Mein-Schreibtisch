<?php

// =======================================
// change_password.php
// Zweck: Eingeloggter Benutzer kann eigenes Passwort aendern
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/password_rules.php';
require_once '../src/avatar.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$error = null;
$success = null;
$avatar_url = '';

ensure_avatar_column($pdo);

try {
    $stmt = $pdo->prepare("SELECT avatar_path FROM users WHERE id = :id");
    $stmt->execute(['id' => (int)$_SESSION['user_id']]);
    $avatar_user = $stmt->fetch();
    $avatar_path = (string)($avatar_user['avatar_path'] ?? '');
    $_SESSION['avatar_path'] = $avatar_path;
    $_SESSION['avatar_loaded'] = true;
    $avatar_real_path = resolve_avatar_path($avatar_path);

    if ($avatar_real_path && is_file($avatar_real_path)) {
        $avatar_url = 'avatar.php?v=' . filemtime($avatar_real_path);
    }
} catch (PDOException $e) {
    error_log('Profilbild konnte nicht geladen werden: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';
    $action = is_scalar($_POST['action'] ?? null) ? (string)$_POST['action'] : 'change_password';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } elseif ($action === 'upload_avatar') {
        $avatar_file = $_FILES['avatar'] ?? null;

        if (!is_array($avatar_file) || ($avatar_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = "Profilbild konnte nicht hochgeladen werden.";
        } else {
            $tmp_name = is_string($avatar_file['tmp_name'] ?? null) ? $avatar_file['tmp_name'] : '';
            $file_size = is_numeric($avatar_file['size'] ?? null) ? (int)$avatar_file['size'] : 0;
            $allowed_types = avatar_allowed_mime_types();
            $max_size = avatar_max_file_size();
            $mime_type = false;

            if (is_uploaded_file($tmp_name)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = $finfo ? finfo_file($finfo, $tmp_name) : false;
                if ($finfo) {
                    finfo_close($finfo);
                }
            }

            if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
                $error = "Profilbild konnte nicht gelesen werden.";
            } elseif ($file_size <= 0 || !is_file($tmp_name)) {
                $error = "Die Bilddatei ist leer.";
            } elseif ($file_size > $max_size) {
                $error = "Profilbild ist zu gross. Maximal " . avatar_max_file_size_label() . " erlaubt.";
            } elseif ($mime_type === false || !array_key_exists($mime_type, $allowed_types)) {
                $error = "Bitte ein JPG- oder PNG-Bild hochladen.";
            } else {
                $upload_dir = avatar_upload_dir();
                $extension = $allowed_types[$mime_type];
                $stored_name = 'avatar_' . (int)$_SESSION['user_id'] . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                $target_path = $upload_dir . $stored_name;

                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true)) {
                    $error = "Avatar-Ordner konnte nicht erstellt werden.";
                } elseif (!is_writable($upload_dir)) {
                    $error = "Avatar-Ordner ist nicht beschreibbar.";
                } elseif (!move_uploaded_file($tmp_name, $target_path)) {
                    $error = "Profilbild konnte nicht gespeichert werden.";
                } else {
                    try {
                        $stmt = $pdo->prepare("SELECT avatar_path FROM users WHERE id = :id");
                        $stmt->execute(['id' => (int)$_SESSION['user_id']]);
                        $old_user = $stmt->fetch();
                        $old_avatar_path = resolve_avatar_path($old_user['avatar_path'] ?? null);

                        $next_avatar_path = avatar_public_path($stored_name);

                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET avatar_path = :avatar_path
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'avatar_path' => $next_avatar_path,
                            'id' => (int)$_SESSION['user_id']
                        ]);

                        if ($old_avatar_path && is_file($old_avatar_path) && realpath($old_avatar_path) !== realpath($target_path)) {
                            unlink($old_avatar_path);
                        }

                        $_SESSION['avatar_path'] = $next_avatar_path;
                        $_SESSION['avatar_loaded'] = true;
                        $avatar_url = avatar_url_from_path($next_avatar_path);
                        $success = "Profilbild wurde aktualisiert.";
                    } catch (PDOException $e) {
                        if (is_file($target_path)) {
                            unlink($target_path);
                        }

                        error_log('Profilbild konnte nicht gespeichert werden: ' . $e->getMessage());
                        $error = "Profilbild konnte nicht gespeichert werden.";
                    }
                }
            }
        }
    } else {
        $posted_current_password = $_POST['current_password'] ?? '';
        $posted_new_password = $_POST['new_password'] ?? '';
        $posted_confirm_password = $_POST['confirm_password'] ?? '';
        $current_password = is_scalar($posted_current_password) ? (string)$posted_current_password : '';
        $new_password = is_scalar($posted_new_password) ? (string)$posted_new_password : '';
        $confirm_password = is_scalar($posted_confirm_password) ? (string)$posted_confirm_password : '';

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $error = "Bitte alle Felder ausfuellen.";
        } elseif (($password_error = password_policy_error($new_password, 'Das neue Passwort')) !== null) {
            $error = $password_error;
        } elseif ($new_password !== $confirm_password) {
            $error = "Die neuen Passwoerter stimmen nicht ueberein.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
                $stmt->execute([
                    'id' => $_SESSION['user_id']
                ]);

                $user = $stmt->fetch();

                if (!$user) {
                    $error = "Benutzer wurde nicht gefunden.";
                } elseif (!password_verify($current_password, $user['password_hash'])) {
                    $error = "Aktuelles Passwort ist falsch.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET password_hash = :password_hash
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        'password_hash' => password_hash($new_password, PASSWORD_DEFAULT),
                        'id' => $_SESSION['user_id']
                    ]);

                    $success = "Passwort wurde erfolgreich geaendert.";
                }
            } catch (PDOException $e) {
                $error = "Fehler beim Aendern des Passworts. Bitte versuchen Sie es spaeter erneut.";
            }
        }
    }
}

app_render_header('Profil', 'profile', [
    'subtitle' => 'Profilbild und Passwort verwalten.'
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p class="message-success"><?= e($success) ?></p>
<?php endif; ?>

<section class="panel profile-panel profile-card">
    <div class="profile-avatar-preview">
        <span class="avatar avatar-large">
            <?php if ($avatar_url !== ''): ?>
                <img src="<?= e($avatar_url) ?>" alt="">
            <?php else: ?>
                <?= e(strtoupper(substr(trim((string)($_SESSION['username'] ?? 'U')), 0, 1) ?: 'U')) ?>
            <?php endif; ?>
        </span>
        <div>
            <h2>Profilbild</h2>
            <p class="muted">JPG oder PNG bis maximal 2 MB.</p>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="file-upload-form js-dropzone-form">
        <input type="hidden" name="action" value="upload_avatar">
        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="2097152">

        <label class="dropzone js-dropzone">
            <span class="dropzone-icon">&#8682;</span>
            <strong>Profilbild hierher ziehen oder auswaehlen</strong>
            <span>JPG oder PNG bis maximal 2 MB.</span>
            <span class="dropzone-file js-dropzone-file">Noch keine Datei ausgewaehlt.</span>
            <input class="dropzone-input js-dropzone-input" type="file" name="avatar" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
        </label>

        <div class="form-actions">
            <button type="submit">Profilbild speichern</button>
        </div>
    </form>
</section>

<section class="panel profile-card profile-password-card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Passwort aendern</h2>
            <p class="muted"><?= e(password_policy_hint()) ?></p>
        </div>
    </div>

    <form method="POST" class="profile-password-form">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

        <div class="form-grid profile-password-grid">
            <label class="full">
                Aktuelles Passwort
                <input type="password" name="current_password" required>
            </label>

            <label>
                Neues Passwort
                <input type="password" name="new_password" required minlength="<?= password_policy_min_length() ?>" pattern="<?= e(password_policy_pattern()) ?>" title="<?= e(password_policy_hint()) ?>">
            </label>

            <label>
                Neues Passwort wiederholen
                <input type="password" name="confirm_password" required minlength="<?= password_policy_min_length() ?>" pattern="<?= e(password_policy_pattern()) ?>" title="<?= e(password_policy_hint()) ?>">
            </label>
        </div>

        <div class="form-actions form-actions-left">
            <button type="submit">Passwort aendern</button>
        </div>
    </form>
</section>

<?php app_render_footer(); ?>
