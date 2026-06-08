<?php

// =======================================
// upload_note_file.php
// Zweck: Datei zu einer eigenen Notiz hochladen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/file_display.php';
require_once '../src/response.php';
require_once '../src/storage_quota.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$user_id = $_SESSION['user_id'];

// Die Notiz-ID kommt aus der URL, z. B. upload_note_file.php?id=5.
// filter_var stellt sicher, dass daraus wirklich eine positive Zahl wird.
$note_id = $_GET['id'] ?? null;
$note_id = is_scalar($note_id) ? filter_var($note_id, FILTER_VALIDATE_INT) : false;

$error = null;
$success = null;
$note_files = [];


// =======================================
// Pruefen, ob eine Notiz-ID vorhanden ist
// =======================================

if ($note_id === false || $note_id <= 0) {
    app_redirect_error('notes.php', 'Keine oder ungueltige Notiz-ID angegeben.');
}


// =======================================
// Pruefen, ob die Notiz dem eingeloggten User gehoert
// =======================================

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM notes
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $note_id,
        'user_id' => $user_id
    ]);

    $note = $stmt->fetch();

    if (!$note) {
        app_redirect_error('notes.php', 'Notiz nicht gefunden oder kein Zugriff.');
    }
} catch (PDOException $e) {
    error_log("Notiz fuer Upload konnte nicht geladen werden: " . $e->getMessage());
    app_redirect_error('notes.php', 'Notiz konnte nicht geladen werden.');
}


// =======================================
// Formular wurde abgeschickt
// =======================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_csrf_token = $_POST['csrf_token'] ?? '';

    // Ein Formular wird fuer zwei Aktionen genutzt:
    // upload_file = neue Datei hochladen
    // delete_file = vorhandene Datei von dieser Notiz entfernen
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'upload_file';

    if (
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } elseif ($action === 'delete_file') {
        $file_id = $_POST['file_id'] ?? null;
        $file_id = is_scalar($file_id) ? filter_var($file_id, FILTER_VALIDATE_INT) : false;

        if ($file_id === false || $file_id <= 0) {
            $error = "Ungueltige Datei-ID.";
        } else {
            try {
                // Datei nur laden, wenn sie wirklich zu dieser Notiz und diesem User gehoert.
                $stmt = $pdo->prepare("
                    SELECT f.id, f.stored_name, f.file_path
                    FROM files f
                    INNER JOIN note_files nf ON nf.file_id = f.id
                    INNER JOIN notes n ON n.id = nf.note_id
                    WHERE f.id = :file_id
                      AND nf.note_id = :note_id
                      AND f.user_id = :user_id
                      AND n.user_id = :user_id
                ");

                $stmt->execute([
                    'file_id' => $file_id,
                    'note_id' => $note_id,
                    'user_id' => $user_id
                ]);

                $file_to_delete = $stmt->fetch();

                if (!$file_to_delete) {
                    $error = "Datei nicht gefunden oder kein Zugriff.";
                } else {
                    // Pfad wird aus gespeicherten Daten rekonstruiert.
                    // basename schuetzt davor, dass Pfadbestandteile im Dateinamen stecken.
                    $stored_name = basename($file_to_delete['stored_name']);
                    $folder = preg_match('#^uploads/(notes|appointments)/#', $file_to_delete['file_path'], $matches)
                        ? $matches[1]
                        : 'notes';
                    $target_path = __DIR__ . '/../uploads/' . $folder . '/' . $stored_name;
                    $delete_physical_file = false;

                    $pdo->beginTransaction();

                    // Erst nur die Verknuepfung zwischen Notiz und Datei loeschen.
                    $stmt = $pdo->prepare("
                        DELETE FROM note_files
                        WHERE note_id = :note_id AND file_id = :file_id
                    ");

                    $stmt->execute([
                        'note_id' => $note_id,
                        'file_id' => $file_id
                    ]);

                    // Danach pruefen, ob dieselbe Datei noch an anderer Stelle genutzt wird.
                    // Falls ja, bleibt der files-Eintrag und die physische Datei erhalten.
                    $stmt = $pdo->prepare("
                        SELECT
                            (
                                (SELECT COUNT(*) FROM note_files WHERE file_id = :file_id_notes)
                                +
                                (SELECT COUNT(*) FROM appointment_files WHERE file_id = :file_id_appointments)
                            ) AS link_count
                    ");

                    $stmt->execute([
                        'file_id_notes' => $file_id,
                        'file_id_appointments' => $file_id
                    ]);

                    $link_count = (int)$stmt->fetch()['link_count'];

                    if ($link_count === 0) {
                        $stmt = $pdo->prepare("
                            DELETE FROM files
                            WHERE id = :file_id AND user_id = :user_id
                        ");

                        $stmt->execute([
                            'file_id' => $file_id,
                            'user_id' => $user_id
                        ]);

                        $delete_physical_file = $stmt->rowCount() > 0;
                    }

                    $pdo->commit();

                    if ($delete_physical_file && is_file($target_path)) {
                        unlink($target_path);
                    }

                    $success = "Datei wurde geloescht.";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = "Datei konnte nicht geloescht werden.";
            }
        }
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Datei konnte nicht hochgeladen werden.";
    } else {

        $file = $_FILES['file'];

        // Originalname ist nur Anzeige. Gespeichert wird spaeter ein zufaelliger Dateiname.
        $original_name = is_string($file['name'] ?? null) ? basename($file['name']) : 'datei';
        $tmp_name = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $file_size = is_numeric($file['size'] ?? null) ? (int)$file['size'] : 0;

        $allowed_types = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt'
        ];

        $max_size = 5 * 1024 * 1024;

        // MIME-Typ wird serverseitig aus der temporaeren Datei gelesen.
        // Die Browser-Dateiendung allein waere nicht vertrauenswuerdig.
        // Nutzt finfo_file() statt deprecated mime_content_type() (PHP 7.0+)
        $mime_type = false;
        if (is_uploaded_file($tmp_name)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = $finfo ? finfo_file($finfo, $tmp_name) : false;
            if ($finfo) finfo_close($finfo);
        }

        if ($mime_type === false || !array_key_exists($mime_type, $allowed_types)) {
            $error = "Dieser Dateityp ist nicht erlaubt.";
        } elseif ($file_size <= 0) {
            $error = "Die Datei ist leer.";
        } elseif ($file_size > $max_size) {
            $error = "Datei ist zu gross. Maximal 5 MB erlaubt.";
        } elseif (($quota_error = app_storage_quota_error($pdo, (int)$user_id, $file_size)) !== null) {
            $error = $quota_error;
        } else {

            $extension = $allowed_types[$mime_type];

            // Zufallsname verhindert Namenskollisionen und direkte Rueckschluesse auf Originalnamen.
            $stored_name = bin2hex(random_bytes(16)) . '.' . $extension;
            $upload_dir = __DIR__ . '/../uploads/notes/';

            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true)) {
                $error = "Upload-Ordner konnte nicht erstellt werden.";
            } elseif (!is_writable($upload_dir)) {
                $error = "Upload-Ordner ist nicht beschreibbar.";
            } else {
                $target_path = $upload_dir . $stored_name;

                if (move_uploaded_file($tmp_name, $target_path)) {
                    $db_path = 'uploads/notes/' . $stored_name;

                    try {
                        // DB-Insert und Verknuepfung laufen zusammen.
                        // Wenn eins davon fehlschlaegt, wird alles zurueckgerollt.
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("
                            INSERT INTO files (
                                user_id,
                                original_name,
                                stored_name,
                                file_path,
                                mime_type,
                                file_size
                            )
                            VALUES (
                                :user_id,
                                :original_name,
                                :stored_name,
                                :file_path,
                                :mime_type,
                                :file_size
                            )
                        ");

                        $stmt->execute([
                            'user_id' => $user_id,
                            'original_name' => $original_name,
                            'stored_name' => $stored_name,
                            'file_path' => $db_path,
                            'mime_type' => $mime_type,
                            'file_size' => $file_size
                        ]);

                        $file_id = $pdo->lastInsertId();

                        $stmt = $pdo->prepare("
                            INSERT INTO note_files (note_id, file_id)
                            VALUES (:note_id, :file_id)
                        ");

                        $stmt->execute([
                            'note_id' => $note_id,
                            'file_id' => $file_id
                        ]);

                        $pdo->commit();
                        $success = "Datei wurde erfolgreich hochgeladen.";
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        // Falls DB-Speichern scheitert, darf keine Datei ohne DB-Eintrag liegen bleiben.
                        if (is_file($target_path)) {
                            unlink($target_path);
                        }

                        $error = "Datei konnte nicht gespeichert werden.";
                    }
                } else {
                    $error = "Datei konnte nicht gespeichert werden.";
                }
            }
        }
    }
}


// =======================================
// Dateien dieser Notiz laden
// =======================================

try {
    // Alle Dateien laden, die mit dieser Notiz verknuepft sind.
    // Die User-ID bleibt auch hier in der Query, damit keine fremden Dateien sichtbar werden.
    $stmt = $pdo->prepare("
        SELECT f.id, f.original_name, f.stored_name, f.file_path, f.mime_type, f.file_size, f.uploaded_at
        FROM files f
        INNER JOIN note_files nf ON nf.file_id = f.id
        WHERE nf.note_id = :note_id
          AND f.user_id = :user_id
        ORDER BY f.uploaded_at DESC, f.id DESC
    ");

    $stmt->execute([
        'note_id' => $note_id,
        'user_id' => $user_id
    ]);

    $note_files = $stmt->fetchAll();
} catch (PDOException $e) {
    $note_files = [];
    $error = $error ?: "Dateien konnten nicht geladen werden.";
}

?>

<?php
app_render_header('Datei hochladen', 'notes', [
    'subtitle' => 'Dateien fuer Notiz: ' . (string)$note['title'],
    'actions' => '<a class="button button-secondary" href="notes.php">&larr; Zurueck zu Notizen</a>'
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p class="message-success"><?= htmlspecialchars($success) ?></p>
<?php endif; ?>

<section class="panel">
<form method="POST" enctype="multipart/form-data" class="file-upload-form js-dropzone-form">

    <input type="hidden" name="action" value="upload_file">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">

    <label class="dropzone js-dropzone">
        <span class="dropzone-icon">&#8682;</span>
        <strong>Datei hierher ziehen oder auswaehlen</strong>
        <span>JPG, PNG, PDF, TXT bis maximal 5 MB.</span>
        <span class="dropzone-file js-dropzone-file">Noch keine Datei ausgewaehlt.</span>
        <input class="dropzone-input js-dropzone-input" type="file" name="file" accept=".jpg,.jpeg,.png,.pdf,.txt,image/jpeg,image/png,application/pdf,text/plain" required>
    </label>

    <div class="form-actions">
        <button type="submit">Datei hochladen</button>
    </div>

</form>
</section>

<section class="panel">
<h2>Dateien dieser Notiz</h2>

<?php if (empty($note_files)): ?>
    <p class="empty-state">Keine Dateien vorhanden.</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Dateiname</th>
            <th>Typ</th>
            <th>Groesse</th>
            <th>Hochgeladen am</th>
            <th>Aktionen</th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($note_files as $file): ?>
            <tr>
                <td><?= htmlspecialchars($file['original_name']) ?></td>
                <td><?= htmlspecialchars($file['mime_type'] ?? '') ?></td>
                <td><?= htmlspecialchars(app_file_size((int)$file['file_size'])) ?></td>
                <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($file['uploaded_at']))) ?></td>
                <td class="action-cell">
                    <div class="action-group">
                    <?php if (app_file_can_preview($file)): ?>
                        <a class="button-link" href="<?= htmlspecialchars(app_file_preview_href($file)) ?>" target="_blank" rel="noopener">Vorschau</a>
                    <?php endif; ?>
                    <a class="button-link" href="<?= htmlspecialchars(app_file_download_href($file)) ?>">Herunterladen</a>
                    <form method="POST" class="action-inline" onsubmit="return confirm('Datei wirklich loeschen?');">
                        <input type="hidden" name="action" value="delete_file">
                        <input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="button-link button-link-danger">Loeschen</button>
                    </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>

<?php app_render_footer(); ?>
