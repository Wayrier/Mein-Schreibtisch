<?php

// =======================================
// delete_user.php
// Zweck: Benutzer loeschen (Admin only)
// =======================================

require_once '../src/admin_check.php';
require_once '../config/database.php';
require_once '../src/file_cleanup.php';
require_once '../src/response.php';

// Admin-only POST-Endpunkt zum Loeschen eines Benutzers.
// Der Admin-Schutz in admin_check.php prueft Login und Rolle.
app_require_post('users.php');
app_require_csrf('users.php');

$id = $_POST['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;

if ($id === false || $id <= 0) {
    app_redirect_error('users.php', 'Keine oder ungueltige Benutzer-ID angegeben.');
}

$current_user_id = (int)$_SESSION['user_id'];

// Schutz davor, dass ein Admin seine eigene Session/sein eigenes Konto entfernt.
if ($id === $current_user_id) {
    app_redirect_error('users.php', 'Du kannst dich nicht selbst loeschen.');
}

try {
    // Dateipfade vorher merken. Durch ON DELETE CASCADE verschwinden die DB-Zeilen
    // beim Benutzer-Loeschen automatisch, aber die Dateien auf der Festplatte nicht.
    $files_for_cleanup = collect_user_files_for_cleanup($pdo, $id);
    $paths_to_delete = file_cleanup_paths($files_for_cleanup);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute([
        'id' => $id
    ]);

    $deleted = $stmt->rowCount() > 0;
    $pdo->commit();

    if ($deleted) {
        // Dateien erst nach erfolgreichem Loeschen des Benutzers physisch entfernen.
        delete_physical_uploaded_files($paths_to_delete);
    }

    if ($deleted) {
        app_redirect_success('users.php', 'Benutzer wurde geloescht.');
    }

    app_redirect_error('users.php', 'Benutzer nicht gefunden.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Benutzer konnte nicht geloescht werden: " . $e->getMessage());
    app_redirect_error('users.php', 'Benutzer konnte nicht geloescht werden. Bitte versuchen Sie es spaeter erneut.');
}
