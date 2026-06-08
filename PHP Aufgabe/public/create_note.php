<?php

// =======================================
// create_note.php
// Zweck: Neue Notiz fuer eingeloggten Benutzer erstellen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$error = null;
$title = '';
$content = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } else {
        $posted_title = $_POST['title'] ?? '';
        $posted_content = $_POST['content'] ?? '';
        $title = is_scalar($posted_title) ? trim((string)$posted_title) : '';
        $content = is_scalar($posted_content) ? trim((string)$posted_content) : '';
        $user_id = (int)$_SESSION['user_id'];

        if ($title === '') {
            $error = "Titel darf nicht leer sein.";
        } elseif (strlen($title) > 150) {
            $error = "Titel darf maximal 150 Zeichen lang sein.";
        } elseif (strlen($content) > 10000) {
            $error = "Inhalt darf maximal 10000 Zeichen lang sein.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO notes (user_id, title, content)
                    VALUES (:user_id, :title, :content)
                ");

                $stmt->execute([
                    'user_id' => $user_id,
                    'title' => $title,
                    'content' => $content
                ]);

                header("Location: notes.php");
                exit;
            } catch (PDOException $e) {
                $error = "Notiz konnte nicht gespeichert werden. Bitte versuchen Sie es spaeter erneut.";
            }
        }
    }
}

app_render_header('Neue Notiz', 'notes', [
    'subtitle' => 'Schreibe eine neue Notiz.',
    'actions' => '<a class="button button-secondary" href="notes.php">&larr; Zurueck</a>'
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= e($error) ?></p>
<?php endif; ?>

<form method="POST" class="panel">
    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

    <label>
        Titel
        <input type="text" name="title" value="<?= e($title) ?>" required maxlength="150">
    </label>
    <br><br>

    <label>
        Inhalt
        <textarea name="content" rows="8" maxlength="10000"><?= e($content) ?></textarea>
    </label>
    <br><br>

    <button type="submit">Notiz speichern</button>
</form>

<?php app_render_footer(); ?>
