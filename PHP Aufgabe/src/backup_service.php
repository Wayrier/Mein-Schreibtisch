<?php

// =======================================
// backup_service.php
// Zweck: Backup-Export und Restore-Logik
// =======================================

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/storage_quota.php';
require_once __DIR__ . '/appointment_dates.php';

function ensure_directory(string $path): bool
{
    return is_dir($path) || mkdir($path, 0775, true);
}

function remove_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

function resolve_stored_file_path(array $file): ?string
{
    $file_path = (string)($file['file_path'] ?? '');
    $stored_name = basename((string)($file['stored_name'] ?? ''));

    if ($stored_name === '' || !preg_match('#^uploads/(notes|appointments)/#', $file_path, $matches)) {
        return null;
    }

    return __DIR__ . '/../uploads/' . $matches[1] . '/' . $stored_name;
}

function clean_datetime(?string $value): string
{
    if (!$value) {
        return date('Y-m-d H:i:s');
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function safe_extension(string $name): string
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!preg_match('/^[a-z0-9]{1,12}$/', $extension)) {
        return '';
    }

    return $extension;
}

function fetch_all_for_backup(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function export_backup(PDO $pdo, int $user_id): void
{
    appointment_ensure_start_date_column($pdo);

    if (!class_exists('PharData')) {
        app_fail('TAR-Export ist nicht verfuegbar, weil PharData fehlt.', 500);
    }

    $work_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mein_schreibtisch_backup_' . bin2hex(random_bytes(8));
    $payload_dir = $work_dir . DIRECTORY_SEPARATOR . 'payload';
    $payload_files_dir = $payload_dir . DIRECTORY_SEPARATOR . 'files';
    $archive_path = $work_dir . DIRECTORY_SEPARATOR . 'mein_schreibtisch_backup.tar';

    if (!ensure_directory($payload_files_dir)) {
        remove_directory($work_dir);
        app_fail('Backup-Ordner konnte nicht erstellt werden.', 500);
    }

    $manifest = [
        'version' => 1,
        'exported_at' => date('c'),
        'notes' => fetch_all_for_backup($pdo, "
            SELECT id, title, content, created_at, updated_at
            FROM notes
            WHERE user_id = :user_id
            ORDER BY id ASC
        ", ['user_id' => $user_id]),
        'appointments' => fetch_all_for_backup($pdo, "
            SELECT id, subject, start_date, due_date, content, status, created_at, updated_at
            FROM appointments
            WHERE user_id = :user_id
            ORDER BY id ASC
        ", ['user_id' => $user_id]),
        'files' => fetch_all_for_backup($pdo, "
            SELECT id, original_name, stored_name, file_path, mime_type, file_size, uploaded_at
            FROM files
            WHERE user_id = :user_id
            ORDER BY id ASC
        ", ['user_id' => $user_id]),
        'note_files' => fetch_all_for_backup($pdo, "
            SELECT nf.note_id, nf.file_id
            FROM note_files nf
            INNER JOIN notes n ON n.id = nf.note_id
            INNER JOIN files f ON f.id = nf.file_id
            WHERE n.user_id = :user_id AND f.user_id = :user_id
            ORDER BY nf.note_id ASC, nf.file_id ASC
        ", ['user_id' => $user_id]),
        'appointment_files' => fetch_all_for_backup($pdo, "
            SELECT af.appointment_id, af.file_id
            FROM appointment_files af
            INNER JOIN appointments a ON a.id = af.appointment_id
            INNER JOIN files f ON f.id = af.file_id
            WHERE a.user_id = :user_id AND f.user_id = :user_id
            ORDER BY af.appointment_id ASC, af.file_id ASC
        ", ['user_id' => $user_id]),
        'conversions' => fetch_all_for_backup($pdo, "
            SELECT source_type, source_id, target_type, target_id, created_at
            FROM conversions
            WHERE user_id = :user_id
            ORDER BY id ASC
        ", ['user_id' => $user_id])
    ];

    foreach ($manifest['files'] as $index => $file) {
        $source_path = resolve_stored_file_path($file);
        $backup_name = (int)$file['id'] . '_' . basename((string)$file['stored_name']);
        $backup_path = 'files/' . $backup_name;

        $manifest['files'][$index]['backup_path'] = null;
        $manifest['files'][$index]['sha256'] = null;

        if ($source_path && is_file($source_path)) {
            copy($source_path, $payload_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $backup_path));
            $manifest['files'][$index]['backup_path'] = $backup_path;
            $manifest['files'][$index]['sha256'] = hash_file('sha256', $source_path);
        }
    }

    file_put_contents(
        $payload_dir . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $archive = new PharData($archive_path);
    $archive->buildFromDirectory($payload_dir);

    register_shutdown_function(function () use ($work_dir) {
        remove_directory($work_dir);
    });

    $download_name = 'mein_schreibtisch_backup_' . date('Y-m-d_H-i-s') . '.tar';

    header('Content-Type: application/x-tar');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($archive_path));
    readfile($archive_path);
    exit;
}

function find_existing_note(PDO $pdo, int $user_id, array $note): ?int
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM notes
        WHERE user_id = :user_id
          AND title = :title
          AND content = :content
          AND created_at = :created_at
        LIMIT 1
    ");

    $stmt->execute([
        'user_id' => $user_id,
        'title' => (string)$note['title'],
        'content' => (string)$note['content'],
        'created_at' => clean_datetime($note['created_at'] ?? null)
    ]);

    $existing = $stmt->fetch();

    return $existing ? (int)$existing['id'] : null;
}

function find_existing_appointment(PDO $pdo, int $user_id, array $appointment): ?int
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM appointments
        WHERE user_id = :user_id
          AND subject = :subject
          AND COALESCE(start_date, due_date) = :start_date
          AND due_date = :due_date
          AND content = :content
          AND status = :status
          AND created_at = :created_at
        LIMIT 1
    ");

    $stmt->execute([
        'user_id' => $user_id,
        'subject' => (string)$appointment['subject'],
        'start_date' => clean_datetime($appointment['start_date'] ?? ($appointment['due_date'] ?? null)),
        'due_date' => clean_datetime($appointment['due_date'] ?? null),
        'content' => (string)($appointment['content'] ?? ''),
        'status' => (string)($appointment['status'] ?? 'open'),
        'created_at' => clean_datetime($appointment['created_at'] ?? null)
    ]);

    $existing = $stmt->fetch();

    return $existing ? (int)$existing['id'] : null;
}

function find_existing_file(PDO $pdo, int $user_id, array $file, ?string $sha256): ?int
{
    if (!$sha256) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, stored_name, file_path
        FROM files
        WHERE user_id = :user_id
          AND original_name = :original_name
          AND file_size = :file_size
    ");

    $stmt->execute([
        'user_id' => $user_id,
        'original_name' => (string)$file['original_name'],
        'file_size' => (int)$file['file_size']
    ]);

    foreach ($stmt->fetchAll() as $existing_file) {
        $existing_path = resolve_stored_file_path($existing_file);

        if ($existing_path && is_file($existing_path) && hash_file('sha256', $existing_path) === $sha256) {
            return (int)$existing_file['id'];
        }
    }

    return null;
}

function backup_extract_file_path(string $extract_dir, string $backup_path): ?string
{
    $backup_path = str_replace('\\', '/', $backup_path);

    if (!preg_match('#^files/[A-Za-z0-9_.-]+$#', $backup_path)) {
        return null;
    }

    $path = realpath($extract_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $backup_path));
    $files_dir = realpath($extract_dir . DIRECTORY_SEPARATOR . 'files');

    if (!$path || !$files_dir || strpos($path, $files_dir) !== 0) {
        return null;
    }

    return $path;
}

function insert_link_if_missing(PDO $pdo, string $table, string $left_column, int $left_id, int $file_id): void
{
    $allowed = [
        'note_files' => 'note_id',
        'appointment_files' => 'appointment_id'
    ];

    if (!isset($allowed[$table]) || $allowed[$table] !== $left_column) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS count_links
        FROM {$table}
        WHERE {$left_column} = :left_id AND file_id = :file_id
    ");

    $stmt->execute([
        'left_id' => $left_id,
        'file_id' => $file_id
    ]);

    if ((int)$stmt->fetch()['count_links'] > 0) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO {$table} ({$left_column}, file_id)
        VALUES (:left_id, :file_id)
    ");

    $stmt->execute([
        'left_id' => $left_id,
        'file_id' => $file_id
    ]);
}

function restore_backup(PDO $pdo, int $user_id, array $uploaded_file): array
{
    appointment_ensure_start_date_column($pdo);

    if (!class_exists('PharData')) {
        throw new RuntimeException('TAR-Import ist nicht verfuegbar, weil PharData fehlt.');
    }

    if (($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Backup-Datei konnte nicht hochgeladen werden.');
    }

    $work_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mein_schreibtisch_restore_' . bin2hex(random_bytes(8));
    $archive_path = $work_dir . DIRECTORY_SEPARATOR . 'backup.tar';
    $extract_dir = $work_dir . DIRECTORY_SEPARATOR . 'extract';
    $new_file_paths = [];

    if (!ensure_directory($extract_dir)) {
        throw new RuntimeException('Restore-Ordner konnte nicht erstellt werden.');
    }

    if (!move_uploaded_file($uploaded_file['tmp_name'], $archive_path)) {
        remove_directory($work_dir);
        throw new RuntimeException('Backup-Datei konnte nicht vorbereitet werden.');
    }

    try {
        $archive = new PharData($archive_path);
        $archive->extractTo($extract_dir, null, true);

        $manifest_path = $extract_dir . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifest_path)) {
            throw new RuntimeException('manifest.json fehlt im Backup.');
        }

        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (!is_array($manifest) || (int)($manifest['version'] ?? 0) !== 1) {
            throw new RuntimeException('Backup-Format ist ungueltig.');
        }

        $note_map = [];
        $appointment_map = [];
        $file_map = [];
        $stats = [
            'notes_created' => 0,
            'notes_existing' => 0,
            'appointments_created' => 0,
            'appointments_existing' => 0,
            'files_created' => 0,
            'files_existing' => 0,
            'links_created' => 0
        ];

        $pdo->beginTransaction();

        foreach (($manifest['notes'] ?? []) as $note) {
            $old_id = (int)$note['id'];
            $existing_id = find_existing_note($pdo, $user_id, $note);

            if ($existing_id) {
                $note_map[$old_id] = $existing_id;
                $stats['notes_existing']++;
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO notes (user_id, title, content, created_at, updated_at)
                VALUES (:user_id, :title, :content, :created_at, :updated_at)
            ");

            $stmt->execute([
                'user_id' => $user_id,
                'title' => (string)$note['title'],
                'content' => (string)$note['content'],
                'created_at' => clean_datetime($note['created_at'] ?? null),
                'updated_at' => clean_datetime($note['updated_at'] ?? ($note['created_at'] ?? null))
            ]);

            $note_map[$old_id] = (int)$pdo->lastInsertId();
            $stats['notes_created']++;
        }

        foreach (($manifest['appointments'] ?? []) as $appointment) {
            $old_id = (int)$appointment['id'];
            $existing_id = find_existing_appointment($pdo, $user_id, $appointment);

            if ($existing_id) {
                $appointment_map[$old_id] = $existing_id;
                $stats['appointments_existing']++;
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO appointments (user_id, subject, start_date, due_date, content, status, created_at, updated_at)
                VALUES (:user_id, :subject, :start_date, :due_date, :content, :status, :created_at, :updated_at)
            ");

            $stmt->execute([
                'user_id' => $user_id,
                'subject' => (string)$appointment['subject'],
                'start_date' => clean_datetime($appointment['start_date'] ?? ($appointment['due_date'] ?? null)),
                'due_date' => clean_datetime($appointment['due_date'] ?? null),
                'content' => (string)($appointment['content'] ?? ''),
                'status' => (string)($appointment['status'] ?? 'open'),
                'created_at' => clean_datetime($appointment['created_at'] ?? null),
                'updated_at' => clean_datetime($appointment['updated_at'] ?? ($appointment['created_at'] ?? null))
            ]);

            $appointment_map[$old_id] = (int)$pdo->lastInsertId();
            $stats['appointments_created']++;
        }

        foreach (($manifest['files'] ?? []) as $file) {
            $old_id = (int)$file['id'];
            $backup_path = (string)($file['backup_path'] ?? '');
            $source_path = $backup_path ? backup_extract_file_path($extract_dir, $backup_path) : null;
            $sha256 = $source_path && is_file($source_path) ? hash_file('sha256', $source_path) : null;
            $existing_id = find_existing_file($pdo, $user_id, $file, $sha256);

            if ($existing_id) {
                $file_map[$old_id] = $existing_id;
                $stats['files_existing']++;
                continue;
            }

            if (!$source_path || !is_file($source_path)) {
                continue;
            }

            $source_size = filesize($source_path);

            if ($source_size === false || $source_size <= 0) {
                continue;
            }

            $quota_error = app_storage_quota_error($pdo, $user_id, (int)$source_size);

            if ($quota_error !== null) {
                throw new RuntimeException($quota_error);
            }

            $file_path = (string)($file['file_path'] ?? '');
            $folder = preg_match('#^uploads/appointments/#', $file_path) ? 'appointments' : 'notes';
            $upload_dir = __DIR__ . '/../uploads/' . $folder . '/';

            if (!ensure_directory($upload_dir)) {
                throw new RuntimeException('Upload-Ordner konnte nicht erstellt werden.');
            }

            $extension = safe_extension((string)($file['stored_name'] ?? ''));
            if ($extension === '') {
                $extension = safe_extension((string)($file['original_name'] ?? ''));
            }

            $stored_name = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
            $target_path = $upload_dir . $stored_name;

            if (!copy($source_path, $target_path)) {
                throw new RuntimeException('Datei konnte nicht wiederhergestellt werden.');
            }

            $new_file_paths[] = $target_path;

            $stmt = $pdo->prepare("
                INSERT INTO files (user_id, original_name, stored_name, file_path, mime_type, file_size, uploaded_at)
                VALUES (:user_id, :original_name, :stored_name, :file_path, :mime_type, :file_size, :uploaded_at)
            ");

            $stmt->execute([
                'user_id' => $user_id,
                'original_name' => (string)$file['original_name'],
                'stored_name' => $stored_name,
                'file_path' => 'uploads/' . $folder . '/' . $stored_name,
                'mime_type' => (string)($file['mime_type'] ?? ''),
                'file_size' => (int)$source_size,
                'uploaded_at' => clean_datetime($file['uploaded_at'] ?? null)
            ]);

            $file_map[$old_id] = (int)$pdo->lastInsertId();
            $stats['files_created']++;
        }

        foreach (($manifest['note_files'] ?? []) as $link) {
            $note_id = $note_map[(int)$link['note_id']] ?? null;
            $file_id = $file_map[(int)$link['file_id']] ?? null;

            if ($note_id && $file_id) {
                insert_link_if_missing($pdo, 'note_files', 'note_id', $note_id, $file_id);
                $stats['links_created']++;
            }
        }

        foreach (($manifest['appointment_files'] ?? []) as $link) {
            $appointment_id = $appointment_map[(int)$link['appointment_id']] ?? null;
            $file_id = $file_map[(int)$link['file_id']] ?? null;

            if ($appointment_id && $file_id) {
                insert_link_if_missing($pdo, 'appointment_files', 'appointment_id', $appointment_id, $file_id);
                $stats['links_created']++;
            }
        }

        foreach (($manifest['conversions'] ?? []) as $conversion) {
            $source_type = (string)($conversion['source_type'] ?? '');
            $target_type = (string)($conversion['target_type'] ?? '');
            $source_id = $source_type === 'note'
                ? ($note_map[(int)$conversion['source_id']] ?? null)
                : ($appointment_map[(int)$conversion['source_id']] ?? null);
            $target_id = $target_type === 'note'
                ? ($note_map[(int)$conversion['target_id']] ?? null)
                : ($appointment_map[(int)$conversion['target_id']] ?? null);

            if (!$source_id || !$target_id || !in_array($source_type, ['note', 'appointment'], true) || !in_array($target_type, ['note', 'appointment'], true)) {
                continue;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS count_conversions
                FROM conversions
                WHERE user_id = :user_id
                  AND source_type = :source_type
                  AND source_id = :source_id
                  AND target_type = :target_type
                  AND target_id = :target_id
            ");

            $stmt->execute([
                'user_id' => $user_id,
                'source_type' => $source_type,
                'source_id' => $source_id,
                'target_type' => $target_type,
                'target_id' => $target_id
            ]);

            if ((int)$stmt->fetch()['count_conversions'] > 0) {
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO conversions (user_id, source_type, source_id, target_type, target_id, created_at)
                VALUES (:user_id, :source_type, :source_id, :target_type, :target_id, :created_at)
            ");

            $stmt->execute([
                'user_id' => $user_id,
                'source_type' => $source_type,
                'source_id' => $source_id,
                'target_type' => $target_type,
                'target_id' => $target_id,
                'created_at' => clean_datetime($conversion['created_at'] ?? null)
            ]);
        }

        $pdo->commit();
        remove_directory($work_dir);

        return $stats;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($new_file_paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        remove_directory($work_dir);
        throw $e;
    }
}

