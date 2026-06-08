<?php

// =======================================
// search_ajax.php
// Zweck: Live-Suche fuer Notizen, Termine und Dateien
// =======================================

require_once '../src/session_check.php';

require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');
function send_search_json(array $data, int $status_code = 200): void
{
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function search_trim_text(?string $value, int $limit = 84): string
{
    $text = trim((string)$value);

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function search_format_datetime(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('d.m.Y H:i', $timestamp) : $value;
}

if (!isset($_SESSION['user_id'])) {
    send_search_json([
        'success' => false,
        'message' => 'Bitte zuerst einloggen.'
    ], 401);
}

$posted_query = $_GET['q'] ?? '';
$query = is_scalar($posted_query) ? trim((string)$posted_query) : '';

if (function_exists('mb_substr')) {
    $query = mb_substr($query, 0, 80);
} else {
    $query = substr($query, 0, 80);
}

if (strlen($query) < 2) {
    send_search_json([
        'success' => true,
        'results' => []
    ]);
}

$user_id = (int)$_SESSION['user_id'];
$like_query = '%' . $query . '%';
$results = [];

try {
    $stmt = $pdo->prepare("
        SELECT id, title, content, created_at
        FROM notes
        WHERE user_id = :user_id
          AND (title LIKE :title_query OR content LIKE :content_query)
        ORDER BY updated_at DESC, id DESC
        LIMIT 5
    ");
    $stmt->execute([
        'user_id' => $user_id,
        'title_query' => $like_query,
        'content_query' => $like_query
    ]);

    foreach ($stmt->fetchAll() as $note) {
        $results[] = [
            'type' => 'Notiz',
            'title' => (string)$note['title'],
            'subtitle' => trim(search_trim_text($note['content']) . ' ' . search_format_datetime($note['created_at'])),
            'url' => 'edit_note.php?id=' . (int)$note['id']
        ];
    }

    $stmt = $pdo->prepare("
        SELECT id, subject, content, due_date, status
        FROM appointments
        WHERE user_id = :user_id
          AND (subject LIKE :subject_query OR content LIKE :content_query)
        ORDER BY due_date ASC, id DESC
        LIMIT 5
    ");
    $stmt->execute([
        'user_id' => $user_id,
        'subject_query' => $like_query,
        'content_query' => $like_query
    ]);

    foreach ($stmt->fetchAll() as $appointment) {
        $status = (string)($appointment['status'] ?? 'open');
        $results[] = [
            'type' => 'Termin',
            'title' => (string)$appointment['subject'],
            'subtitle' => trim(search_format_datetime($appointment['due_date']) . ' ' . search_trim_text($appointment['content']) . ' ' . $status),
            'url' => 'edit_appointment.php?id=' . (int)$appointment['id']
        ];
    }

    $stmt = $pdo->prepare("
        SELECT id, original_name, mime_type, uploaded_at
        FROM files
        WHERE user_id = :user_id
          AND (original_name LIKE :name_query OR mime_type LIKE :mime_query)
        ORDER BY uploaded_at DESC, id DESC
        LIMIT 5
    ");
    $stmt->execute([
        'user_id' => $user_id,
        'name_query' => $like_query,
        'mime_query' => $like_query
    ]);

    foreach ($stmt->fetchAll() as $file) {
        $results[] = [
            'type' => 'Datei',
            'title' => (string)$file['original_name'],
            'subtitle' => trim((string)($file['mime_type'] ?? '') . ' ' . search_format_datetime($file['uploaded_at'])),
            'url' => 'download_file.php?id=' . (int)$file['id']
        ];
    }

    send_search_json([
        'success' => true,
        'results' => $results
    ]);
} catch (PDOException $e) {
    error_log('Live-Suche fehlgeschlagen: ' . $e->getMessage());

    send_search_json([
        'success' => false,
        'message' => 'Suche ist gerade nicht verfuegbar.'
    ], 500);
}
