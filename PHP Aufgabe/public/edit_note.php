<?php

// =======================================
// edit_note.php
// Zweck: Notiz bearbeiten
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/response.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$user_id = (int)$_SESSION['user_id'];
$id = $_GET['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;
$error = null;

if ($id === false || $id <= 0) {
    app_redirect_error('notes.php', 'Keine oder ungueltige Notiz-ID angegeben.');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM notes WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $id, 'user_id' => $user_id]);
    $note = $stmt->fetch();

    if (!$note) {
        app_redirect_error('notes.php', 'Notiz nicht gefunden oder Zugriff verweigert.');
    }
} catch (PDOException $e) {
    error_log("Notiz konnte nicht geladen werden: " . $e->getMessage());
    app_redirect_error('notes.php', 'Fehler beim Laden der Notiz. Bitte versuchen Sie es spaeter erneut.');
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
        $posted_title = $_POST['title'] ?? '';
        $posted_content = $_POST['content'] ?? '';
        $title = is_scalar($posted_title) ? trim((string)$posted_title) : '';
        $content = is_scalar($posted_content) ? trim((string)$posted_content) : '';

        if ($title === '') {
            $error = "Titel darf nicht leer sein.";
        } elseif (strlen($title) > 150) {
            $error = "Titel darf maximal 150 Zeichen lang sein.";
        } elseif (strlen($content) > 10000) {
            $error = "Inhalt darf maximal 10000 Zeichen lang sein.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE notes
                    SET title = :title, content = :content
                    WHERE id = :id AND user_id = :user_id
                ");

                $stmt->execute([
                    'title' => $title,
                    'content' => $content,
                    'id' => $id,
                    'user_id' => $user_id
                ]);

                header("Location: notes.php");
                exit;
            } catch (PDOException $e) {
                $error = "Notiz konnte nicht aktualisiert werden. Bitte versuchen Sie es spaeter erneut.";
            }
        }
    }
}

app_render_header('Notiz bearbeiten', 'notes', [
    'subtitle' => 'Bearbeite Titel und Inhalt deiner Notiz.',
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
        <input type="text" name="title" value="<?= e($note['title']) ?>" required maxlength="150">
    </label>
    <br><br>

    <label>
        Inhalt
        <textarea name="content" rows="8" maxlength="10000"><?= e($note['content']) ?></textarea>
    </label>
    <br><br>

    <button type="submit">Notiz speichern</button>
</form>

<?php app_render_footer(); ?>
