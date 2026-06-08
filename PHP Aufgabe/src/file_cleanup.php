<?php

// =======================================
// file_cleanup.php
// Zweck: Verwaiste Upload-Dateien sicher bereinigen
//
// Diese Datei wird von Loesch-Endpunkten benutzt.
// Sie trennt bewusst zwei Dinge:
// 1. Datenbank-Eintraege zu Dateien entfernen
// 2. Physische Dateien aus uploads/ entfernen
// =======================================

// Baut aus einem Datenbank-Dateieintrag den echten Pfad im uploads-Ordner.
// Gibt null zurueck, wenn der gespeicherte Pfad nicht zu einem erlaubten Upload-Ordner passt.
function resolve_uploaded_file_path(array $file): ?string
{
    $file_path = (string)($file['file_path'] ?? '');
    $stored_name = basename((string)($file['stored_name'] ?? ''));

    if ($stored_name === '' || !preg_match('#^uploads/(notes|appointments)/#', $file_path, $matches)) {
        return null;
    }

    return __DIR__ . '/../uploads/' . $matches[1] . '/' . $stored_name;
}

// Sammelt alle Dateien, die aktuell direkt mit einer bestimmten Notiz verknuepft sind.
// Die User-Pruefung verhindert, dass fremde Dateien versehentlich angefasst werden.
function collect_note_files_for_cleanup(PDO $pdo, int $user_id, int $note_id): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.id, f.stored_name, f.file_path
        FROM files f
        INNER JOIN note_files nf ON nf.file_id = f.id
        INNER JOIN notes n ON n.id = nf.note_id
        WHERE nf.note_id = :note_id
          AND f.user_id = :user_id
          AND n.user_id = :user_id
    ");

    $stmt->execute([
        'note_id' => $note_id,
        'user_id' => $user_id
    ]);

    return $stmt->fetchAll();
}

// Sammelt alle Dateien, die aktuell direkt mit einem bestimmten Termin verknuepft sind.
// Auch hier wird der Besitzer des Termins und der Datei geprueft.
function collect_appointment_files_for_cleanup(PDO $pdo, int $user_id, int $appointment_id): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.id, f.stored_name, f.file_path
        FROM files f
        INNER JOIN appointment_files af ON af.file_id = f.id
        INNER JOIN appointments a ON a.id = af.appointment_id
        WHERE af.appointment_id = :appointment_id
          AND f.user_id = :user_id
          AND a.user_id = :user_id
    ");

    $stmt->execute([
        'appointment_id' => $appointment_id,
        'user_id' => $user_id
    ]);

    return $stmt->fetchAll();
}

// Wird beim Loeschen eines Benutzers verwendet.
// Alle Upload-Dateien dieses Benutzers koennen danach physisch entfernt werden.
function collect_user_files_for_cleanup(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare("
        SELECT id, stored_name, file_path
        FROM files
        WHERE user_id = :user_id
    ");

    $stmt->execute([
        'user_id' => $user_id
    ]);

    return $stmt->fetchAll();
}

// Loescht nur Dateidatensaetze, die nach dem Loeschen der Notiz/des Termins
// mit keinem Objekt mehr verknuepft sind. Geteilte Dateien bleiben erhalten.
// Rueckgabe: Dateipfade, die nach erfolgreichem DB-Commit physisch geloescht werden.
function delete_unlinked_file_records(PDO $pdo, int $user_id, array $files): array
{
    $paths_to_delete = [];
    $seen_file_ids = [];

    $count_stmt = $pdo->prepare("
        SELECT
            (
                (SELECT COUNT(*) FROM note_files WHERE file_id = :file_id_notes)
                +
                (SELECT COUNT(*) FROM appointment_files WHERE file_id = :file_id_appointments)
            ) AS link_count
    ");

    $delete_stmt = $pdo->prepare("
        DELETE FROM files
        WHERE id = :file_id AND user_id = :user_id
    ");

    foreach ($files as $file) {
        $file_id = (int)($file['id'] ?? 0);

        if ($file_id <= 0 || isset($seen_file_ids[$file_id])) {
            continue;
        }

        $seen_file_ids[$file_id] = true;

        $count_stmt->execute([
            'file_id_notes' => $file_id,
            'file_id_appointments' => $file_id
        ]);

        $link_count = (int)$count_stmt->fetch()['link_count'];

        if ($link_count !== 0) {
            continue;
        }

        $delete_stmt->execute([
            'file_id' => $file_id,
            'user_id' => $user_id
        ]);

        if ($delete_stmt->rowCount() > 0) {
            $path = resolve_uploaded_file_path($file);

            if ($path) {
                $paths_to_delete[] = $path;
            }
        }
    }

    return $paths_to_delete;
}

// Erstellt nur die physischen Pfade zu Dateien.
// Das wird beim Benutzer-Loeschen genutzt, weil die DB per Foreign Keys alles mitloescht.
function file_cleanup_paths(array $files): array
{
    $paths = [];

    foreach ($files as $file) {
        $path = resolve_uploaded_file_path($file);

        if ($path) {
            $paths[$path] = $path;
        }
    }

    return array_values($paths);
}

// Entfernt die Dateien vom Dateisystem.
// Wird absichtlich erst nach dem Datenbank-Commit aufgerufen.
function delete_physical_uploaded_files(array $paths): void
{
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }

        if (!@unlink($path)) {
            error_log('Upload-Datei konnte nicht geloescht werden: ' . $path);
        }
    }
}
