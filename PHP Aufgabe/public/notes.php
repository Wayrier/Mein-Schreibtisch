<?php

// =======================================
// notes.php
// Zweck: Eigene Notizen anzeigen
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
$user_id = $_SESSION['user_id'];

function note_relative_time(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return (string)$value;
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'gerade eben';
    }

    if ($diff < 3600) {
        return 'vor ' . floor($diff / 60) . ' Min.';
    }

    if ($diff < 86400) {
        return 'vor ' . floor($diff / 3600) . ' Std.';
    }

    if ($diff < 172800) {
        return 'gestern';
    }

    return date('d.m.Y', $timestamp);
}

function note_accent_class(int $id): string
{
    $classes = ['note-accent-blue', 'note-accent-green', 'note-accent-yellow', 'note-accent-red'];

    return $classes[$id % count($classes)];
}


// =======================================
// Notizen laden (nur eigene!)
// =======================================

try {
    $stmt = $pdo->prepare("
        SELECT id, title, content, created_at
        FROM notes
        WHERE user_id = :user_id
        ORDER BY created_at DESC
    ");

    $stmt->execute([
        'user_id' => $user_id
    ]);

    $notes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Notizen konnten nicht geladen werden: " . $e->getMessage());
    app_fail('Fehler beim Laden der Notizen. Bitte versuchen Sie es spaeter erneut.', 500);
}

?>

<?php
app_render_header('Notizen', 'notes', [
    'subtitle' => 'Erstelle, bearbeite und verwalte deine Notizen.',
    'actions' => '<a class="button" href="create_note.php" data-note-modal-target="note-create-modal">+ Neue Notiz</a>'
]);
?>

<noscript>
    <p><a href="create_note.php">Neue Notiz auf eigener Seite erstellen</a></p>
</noscript>

<section class="panel notes-board-panel">
    <div class="notes-board-header">
        <div>
            <h2>Gedankenboard</h2>
            <p class="muted"><span id="notes-count"><?= count($notes) ?></span> Notizen gesammelt</p>
        </div>
        <input id="notes-filter" class="notes-filter" type="search" placeholder="Notizen durchsuchen..." autocomplete="off">
    </div>

    <p id="empty-notes-message" class="empty-state <?= empty($notes) ? '' : 'is-hidden' ?>">Keine Notizen vorhanden.</p>
    <p id="no-filtered-notes-message" class="empty-state is-hidden">Keine passende Notiz gefunden.</p>

    <div id="notes-board" class="notes-board <?= empty($notes) ? 'is-hidden' : '' ?>">
        <?php foreach ($notes as $note): ?>
            <?php
            $note_id = (int)$note['id'];
            $created_timestamp = strtotime((string)$note['created_at']);
            $created_display = $created_timestamp ? date('d.m.Y H:i', $created_timestamp) : (string)$note['created_at'];
            ?>
            <article
                class="note-card <?= e(note_accent_class($note_id)) ?>"
                data-note-id="<?= $note_id ?>"
                data-title="<?= e($note['title']) ?>"
                data-content="<?= e($note['content']) ?>"
            >
                <div class="note-card-top">
                    <span class="note-card-dot" aria-hidden="true"></span>
                    <span class="note-created-cell" title="<?= e($created_display) ?>"><?= e(note_relative_time($note['created_at'])) ?></span>
                </div>

                <h3 class="note-title-cell"><?= e($note['title']) ?></h3>
                <p class="note-content-cell"><?= e($note['content'] !== '' ? $note['content'] : 'Noch kein Inhalt.') ?></p>

                <div class="note-card-actions action-group">
                    <a href="edit_note.php?id=<?= $note_id ?>" class="button-link ajax-edit-note-link">&#9998; Bearbeiten</a>
                    <a href="upload_note_file.php?id=<?= $note_id ?>" class="button-link">&#128206; Datei anh&auml;ngen</a>
                    <a href="convert_note_to_appointment.php?id=<?= $note_id ?>" class="button-link">&#8618; Als Termin</a>
                    <form method="POST" action="delete_note.php" class="action-inline ajax-delete-form" data-ajax-url="delete_note_ajax.php">
                        <input type="hidden" name="id" value="<?= $note_id ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                        <button type="submit" class="button-link button-link-danger">&#128465; L&ouml;schen</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="modal-backdrop note-modal" id="note-create-modal" hidden>
    <section class="app-modal app-modal-wide" role="dialog" aria-modal="true" aria-labelledby="note-create-modal-title">
        <div class="modal-header">
            <div>
                <p class="eyebrow">Gedanke festhalten</p>
                <h2 id="note-create-modal-title">Neue Notiz</h2>
            </div>
            <button type="button" class="icon-button modal-close-button" data-note-modal-close aria-label="Schliessen">&times;</button>
        </div>

        <form method="POST" action="create_note.php" id="create-note-form" data-ajax-url="create_note_ajax.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

            <label>
                Titel
                <input type="text" name="title" required maxlength="150" placeholder="Kurzer Gedanke, Idee oder Stichwort">
                <small>Max. 150 Zeichen</small>
            </label>

            <label>
                Inhalt
                <textarea name="content" maxlength="10000" placeholder="Was geht dir gerade durch den Kopf?"></textarea>
                <small>Max. 10000 Zeichen</small>
            </label>

            <div class="form-actions">
                <span id="create-note-message" class="status-message" role="status"></span>
                <button type="button" class="button-secondary" data-note-modal-close>Abbrechen</button>
                <button type="submit">Notiz speichern</button>
            </div>
        </form>
    </section>
</div>

<div class="modal-backdrop note-modal" id="note-edit-modal" hidden>
    <section class="app-modal app-modal-wide" role="dialog" aria-modal="true" aria-labelledby="note-edit-modal-title">
        <div class="modal-header">
            <div>
                <p class="eyebrow">Gedanke bearbeiten</p>
                <h2 id="note-edit-modal-title">Notiz bearbeiten</h2>
            </div>
            <button type="button" class="icon-button modal-close-button" data-note-modal-close aria-label="Schliessen">&times;</button>
        </div>

        <form method="POST" id="edit-note-form" data-ajax-url="edit_note_ajax.php">
            <input type="hidden" name="id">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

            <label>
                Titel
                <input type="text" name="title" required maxlength="150">
                <small>Max. 150 Zeichen</small>
            </label>

            <label>
                Inhalt
                <textarea name="content" maxlength="10000"></textarea>
                <small>Max. 10000 Zeichen</small>
            </label>

            <div class="form-actions">
                <span id="edit-note-message" class="status-message" role="status"></span>
                <button type="button" class="button-secondary" id="cancel-note-edit" data-note-modal-close>Abbrechen</button>
                <button type="submit">Aenderungen speichern</button>
            </div>
        </form>
    </section>
</div>

<script src="<?= e(app_asset_url('assets/notes.js')) ?>"></script>

<?php app_render_footer(); ?>
