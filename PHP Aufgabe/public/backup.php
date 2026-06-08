<?php

// =======================================
// backup.php
// Zweck: Eigene Daten exportieren und wiederherstellen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/response.php';
require_once '../src/backup_service.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$user_id = $_SESSION['user_id'];
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } elseif ($action === 'export') {
        export_backup($pdo, (int)$user_id);
    } elseif ($action === 'import') {
        try {
            $stats = restore_backup($pdo, (int)$user_id, $_FILES['backup_file'] ?? []);
            $success = sprintf(
                'Backup eingespielt. Notizen neu: %d, vorhanden: %d. Termine neu: %d, vorhanden: %d. Dateien neu: %d, vorhanden: %d.',
                $stats['notes_created'],
                $stats['notes_existing'],
                $stats['appointments_created'],
                $stats['appointments_existing'],
                $stats['files_created'],
                $stats['files_existing']
            );
        } catch (Throwable $e) {
            error_log("Backup-Import fehlgeschlagen: " . $e->getMessage());
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : "Backup konnte nicht eingespielt werden.";
        }
    }
}

?>

<?php
app_render_header('Backup', 'backup', [
    'subtitle' => 'Exportiere und importiere deine Notizen, Termine, Dateien und Verknuepfungen.'
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p class="message-success"><?= htmlspecialchars($success) ?></p>
<?php endif; ?>

<section class="dashboard-grid backup-grid">
<article class="card span-6 backup-card backup-export-card">
    <div class="backup-card-head">
        <span class="backup-icon">&#8681;</span>
        <div>
            <h2>Export</h2>
            <p>Enthaelt Notizen, Termine, Dateien und Verknuepfungen.</p>
        </div>
    </div>

    <p>Erstellt ein TAR-Backup deiner aktuellen Daten. Ideal vor groesseren Aenderungen oder fuer die Abgabe.</p>

    <form method="POST">
        <input type="hidden" name="action" value="export">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <button type="submit">Backup herunterladen</button>
    </form>
</article>

<article class="card span-6 backup-card backup-import-card">
    <div class="backup-card-head">
        <span class="backup-icon">&#8679;</span>
        <div>
            <h2>Import</h2>
            <p>Erkennt bestehende Inhalte wieder und verhindert doppelte Daten.</p>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="backup-import-form">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <label class="dropzone backup-dropzone">
            <span class="dropzone-icon">&#8682;</span>
            <strong>Backup-Datei hierher ziehen oder auswaehlen</strong>
            <span>TAR-Datei aus MeinSchreibtisch.</span>
            <span class="dropzone-file js-dropzone-file">Noch keine Datei ausgewaehlt.</span>
            <input class="dropzone-input js-dropzone-input" type="file" name="backup_file" accept=".tar,application/x-tar" required>
        </label>

        <div class="form-actions">
            <button type="submit" class="button-green">Backup einspielen</button>
        </div>
    </form>
</article>
</section>

<script>
document.querySelectorAll('.backup-dropzone').forEach((dropzone) => {
    const input = dropzone.querySelector('.js-dropzone-input');
    const label = dropzone.querySelector('.js-dropzone-file');

    if (!input || !label) {
        return;
    }

    input.addEventListener('change', () => {
        label.textContent = input.files && input.files[0]
            ? input.files[0].name
            : 'Noch keine Datei ausgewaehlt.';
    });
});
</script>

<?php app_render_footer(); ?>
