<?php

// =======================================
// upload_appointment_file.php
// Zweck: Datei zu einem eigenen Termin hochladen
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
$appointment_id = $_GET['id'] ?? null;
$appointment_id = is_scalar($appointment_id) ? filter_var($appointment_id, FILTER_VALIDATE_INT) : false;
$error = null;
$success = null;
$appointment_files = [];

if ($appointment_id === false || $appointment_id <= 0) {
    app_redirect_error('appointments.php', 'Keine oder ungueltige Termin-ID angegeben.');
}

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM appointments
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $appointment_id,
        'user_id' => $user_id
    ]);

    $appointment = $stmt->fetch();

    if (!$appointment) {
        app_redirect_error('appointments.php', 'Termin nicht gefunden oder kein Zugriff.');
    }
} catch (PDOException $e) {
    error_log("Termin fuer Upload konnte nicht geladen werden: " . $e->getMessage());
    app_redirect_error('appointments.php', 'Termin konnte nicht geladen werden.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';
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
                $stmt = $pdo->prepare("
                    SELECT f.id, f.stored_name, f.file_path
                    FROM files f
                    INNER JOIN appointment_files af ON af.file_id = f.id
                    INNER JOIN appointments a ON a.id = af.appointment_id
                    WHERE f.id = :file_id
                      AND af.appointment_id = :appointment_id
                      AND f.user_id = :user_id
                      AND a.user_id = :user_id
                ");

                $stmt->execute([
                    'file_id' => $file_id,
                    'appointment_id' => $appointment_id,
                    'user_id' => $user_id
                ]);

                $file_to_delete = $stmt->fetch();

                if (!$file_to_delete) {
                    $error = "Datei nicht gefunden oder kein Zugriff.";
                } else {
                    $stored_name = basename($file_to_delete['stored_name']);
                    $folder = preg_match('#^uploads/(notes|appointments)/#', $file_to_delete['file_path'], $matches)
                        ? $matches[1]
                        : 'appointments';
                    $target_path = __DIR__ . '/../uploads/' . $folder . '/' . $stored_name;
                    $delete_physical_file = false;

                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        DELETE FROM appointment_files
                        WHERE appointment_id = :appointment_id AND file_id = :file_id
                    ");

                    $stmt->execute([
                        'appointment_id' => $appointment_id,
                        'file_id' => $file_id
                    ]);

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
            $stored_name = bin2hex(random_bytes(16)) . '.' . $extension;
            $upload_dir = __DIR__ . '/../uploads/appointments/';

            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true)) {
                $error = "Upload-Ordner konnte nicht erstellt werden.";
            } elseif (!is_writable($upload_dir)) {
                $error = "Upload-Ordner ist nicht beschreibbar.";
            } else {
                $target_path = $upload_dir . $stored_name;

                if (move_uploaded_file($tmp_name, $target_path)) {
                    $db_path = 'uploads/appointments/' . $stored_name;

                    try {
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
                            INSERT INTO appointment_files (appointment_id, file_id)
                            VALUES (:appointment_id, :file_id)
                        ");

                        $stmt->execute([
                            'appointment_id' => $appointment_id,
                            'file_id' => $file_id
                        ]);

                        $pdo->commit();
                        $success = "Datei wurde erfolgreich hochgeladen.";
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

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

try {
    $stmt = $pdo->prepare("
        SELECT f.id, f.original_name, f.stored_name, f.file_path, f.mime_type, f.file_size, f.uploaded_at
        FROM files f
        INNER JOIN appointment_files af ON af.file_id = f.id
        WHERE af.appointment_id = :appointment_id
          AND f.user_id = :user_id
        ORDER BY f.uploaded_at DESC, f.id DESC
    ");

    $stmt->execute([
        'appointment_id' => $appointment_id,
        'user_id' => $user_id
    ]);

    $appointment_files = $stmt->fetchAll();
} catch (PDOException $e) {
    $appointment_files = [];
    $error = $error ?: "Dateien konnten nicht geladen werden.";
}

?>

<?php
app_render_header('Datei hochladen', 'appointments', [
    'subtitle' => 'Dateien fuer Termin: ' . (string)$appointment['subject'],
    'actions' => '<a class="button button-secondary" href="appointments.php">&larr; Zurueck zu Terminen</a>'
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
<h2>Dateien dieses Termins</h2>

<?php if (empty($appointment_files)): ?>
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
        <?php foreach ($appointment_files as $file): ?>
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
