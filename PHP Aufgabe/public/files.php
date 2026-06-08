<?php

// =======================================
// files.php
// Zweck: Eigene Dateien anzeigen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/file_display.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$files = [];
$error = null;

try {
    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.original_name,
            f.stored_name,
            f.file_path,
            f.mime_type,
            f.file_size,
            f.uploaded_at,
            GROUP_CONCAT(DISTINCT n.id ORDER BY n.id SEPARATOR ',') AS note_ids,
            GROUP_CONCAT(DISTINCT n.title ORDER BY n.id SEPARATOR ' | ') AS note_titles,
            GROUP_CONCAT(DISTINCT a.id ORDER BY a.id SEPARATOR ',') AS appointment_ids,
            GROUP_CONCAT(DISTINCT a.subject ORDER BY a.id SEPARATOR ' | ') AS appointment_subjects
        FROM files f
        LEFT JOIN note_files nf ON nf.file_id = f.id
        LEFT JOIN notes n ON n.id = nf.note_id AND n.user_id = f.user_id
        LEFT JOIN appointment_files af ON af.file_id = f.id
        LEFT JOIN appointments a ON a.id = af.appointment_id AND a.user_id = f.user_id
        WHERE f.user_id = :user_id
        GROUP BY
            f.id,
            f.original_name,
            f.stored_name,
            f.file_path,
            f.mime_type,
            f.file_size,
            f.uploaded_at
        ORDER BY f.uploaded_at DESC, f.id DESC
    ");

    $stmt->execute([
        'user_id' => $user_id
    ]);

    $files = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Dateien konnten nicht geladen werden.";
}

?>

<?php
app_render_header('Dateien', 'files', [
    'subtitle' => 'Alle hochgeladenen Dateien und ihre Verknuepfungen.'
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if (!$error && empty($files)): ?>
    <section class="panel files-empty-panel">
        <div class="empty-hero">
            <span class="empty-hero-icon">&#128193;</span>
            <h2>Noch keine Dateien hochgeladen</h2>
            <p>Fuege Dateien direkt an eine Notiz oder einen Termin an. Danach erscheinen sie hier mit Vorschau, Download und Verknuepfung.</p>
            <div class="empty-hero-actions">
                <a class="button" href="notes.php">Datei zu Notiz hochladen</a>
                <a class="button button-green" href="appointments.php">Datei zu Termin hochladen</a>
            </div>
        </div>
    </section>
<?php elseif (!$error): ?>
    <section class="panel">
    <table>
        <thead>
        <tr>
            <th>Dateiname</th>
            <th>Typ</th>
            <th>Groesse</th>
            <th>Verknuepft mit</th>
            <th>Hochgeladen am</th>
            <th>Aktionen</th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($files as $file): ?>
            <?php
            $href = app_file_download_href($file);
            $note_ids = $file['note_ids'] ? explode(',', $file['note_ids']) : [];
            $note_title = $file['note_titles'] ?: '';
            $appointment_ids = $file['appointment_ids'] ? explode(',', $file['appointment_ids']) : [];
            $appointment_subject = $file['appointment_subjects'] ?: '';
            ?>
            <tr>
                <td>
                    <span class="file-name-cell">
                        <span class="file-icon"><?= htmlspecialchars(app_file_type_icon($file)) ?></span>
                        <span>
                            <strong><?= htmlspecialchars($file['original_name']) ?></strong><br>
                            <small><?= htmlspecialchars($file['mime_type'] ?? '') ?></small>
                        </span>
                    </span>
                </td>
                <td><?= htmlspecialchars(app_file_type_label($file)) ?></td>
                <td><?= htmlspecialchars(app_file_size((int)$file['file_size'])) ?></td>
                <td>
                    <?php if (!empty($note_ids)): ?>
                        <a href="upload_note_file.php?id=<?= (int)$note_ids[0] ?>">
                            Notiz: <?= htmlspecialchars($note_title) ?>
                        </a>
                    <?php elseif (!empty($appointment_ids)): ?>
                        <a href="edit_appointment.php?id=<?= (int)$appointment_ids[0] ?>">
                            Termin: <?= htmlspecialchars($appointment_subject) ?>
                        </a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars(app_file_datetime($file['uploaded_at'] ?? null)) ?></td>
                <td class="action-cell">
                    <?php if ($href): ?>
                        <?php if (app_file_can_preview($file)): ?>
                            <a class="button-link file-preview-link" href="<?= htmlspecialchars(app_file_preview_href($file)) ?>" data-file-name="<?= htmlspecialchars($file['original_name']) ?>">Vorschau</a>
                        <?php endif; ?>
                        <a class="button-link" href="<?= htmlspecialchars($href) ?>">Herunterladen</a>
                    <?php else: ?>
                        Nicht verfuegbar
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </section>
<?php endif; ?>

<div class="modal-backdrop file-preview-modal" id="file-preview-modal" hidden>
    <section class="app-modal file-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="file-preview-title">
        <div class="modal-header">
            <div>
                <p class="eyebrow">Dateivorschau</p>
                <h2 id="file-preview-title">Vorschau</h2>
            </div>
            <button type="button" class="icon-button modal-close-button" id="file-preview-close" aria-label="Schliessen">&times;</button>
        </div>

        <iframe id="file-preview-frame" class="file-preview-frame" title="Dateivorschau"></iframe>
    </section>
</div>

<script>
const filePreviewModal = document.getElementById('file-preview-modal');
const filePreviewFrame = document.getElementById('file-preview-frame');
const filePreviewTitle = document.getElementById('file-preview-title');
const filePreviewClose = document.getElementById('file-preview-close');

function closeFilePreview() {
    if (!filePreviewModal || !filePreviewFrame) {
        return;
    }

    filePreviewModal.hidden = true;
    filePreviewFrame.src = 'about:blank';
    document.body.classList.remove('modal-open');
}

document.querySelectorAll('.file-preview-link').forEach((link) => {
    link.addEventListener('click', (event) => {
        event.preventDefault();

        if (!filePreviewModal || !filePreviewFrame || !filePreviewTitle) {
            window.location.href = link.href;
            return;
        }

        filePreviewTitle.textContent = link.dataset.fileName || 'Vorschau';
        filePreviewFrame.src = link.href;
        filePreviewModal.hidden = false;
        document.body.classList.add('modal-open');
    });
});

if (filePreviewClose) {
    filePreviewClose.addEventListener('click', closeFilePreview);
}

if (filePreviewModal) {
    filePreviewModal.addEventListener('click', (event) => {
        if (event.target === filePreviewModal) {
            closeFilePreview();
        }
    });
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeFilePreview();
    }
});
</script>

<?php app_render_footer(); ?>
