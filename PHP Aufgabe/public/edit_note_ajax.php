<?php

// =======================================
// edit_note_ajax.php
// Zweck: Notiz via AJAX bearbeiten
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';

header('Content-Type: application/json');

function send_json(array $data): void
{
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    send_json([
        'success' => false,
        'message' => 'Bitte zuerst einloggen.'
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json([
        'success' => false,
        'message' => 'Ungueltige Anfrage.'
    ]);
}

$posted_csrf_token = $_POST['csrf_token'] ?? '';
if (
    empty($_SESSION['csrf_token']) ||
    !is_string($posted_csrf_token) ||
    !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
) {
    send_json([
        'success' => false,
        'message' => 'Sicherheitsvalidierung fehlgeschlagen.'
    ]);
}

$id = $_POST['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;

if ($id === false || $id <= 0) {
    send_json([
        'success' => false,
        'message' => 'Ungueltige Notiz-ID.'
    ]);
}

$posted_title = $_POST['title'] ?? '';
$posted_content = $_POST['content'] ?? '';

$title = is_scalar($posted_title) ? trim((string)$posted_title) : '';
$content = is_scalar($posted_content) ? trim((string)$posted_content) : '';

if ($title === '') {
    send_json([
        'success' => false,
        'message' => 'Titel darf nicht leer sein.'
    ]);
}

if (strlen($title) > 150) {
    send_json([
        'success' => false,
        'message' => 'Titel darf maximal 150 Zeichen lang sein.'
    ]);
}

if (strlen($content) > 10000) {
    send_json([
        'success' => false,
        'message' => 'Inhalt darf maximal 10000 Zeichen lang sein.'
    ]);
}

$user_id = $_SESSION['user_id'];

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

    $stmt = $pdo->prepare("
        SELECT id, title, content, created_at
        FROM notes
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $id,
        'user_id' => $user_id
    ]);

    $note = $stmt->fetch();

    if (!$note) {
        send_json([
            'success' => false,
            'message' => 'Notiz nicht gefunden oder Zugriff verweigert.'
        ]);
    }

    send_json([
        'success' => true,
        'note' => [
            'id' => (int)$note['id'],
            'title' => $note['title'],
            'content' => $note['content'],
            'created_at' => date('d.m.Y H:i', strtotime($note['created_at']))
        ]
    ]);
} catch (PDOException $e) {
    send_json([
        'success' => false,
        'message' => 'Notiz konnte nicht aktualisiert werden. Bitte versuchen Sie es spaeter erneut.'
    ]);
}
