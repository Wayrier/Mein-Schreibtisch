<?php

// =======================================
// avatar.php
// Zweck: Hilfsfunktionen fuer Profilbilder
// =======================================

function ensure_avatar_column(PDO $pdo): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_path'");

        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER full_name");
        }
    } catch (PDOException $e) {
        error_log('Avatar-Spalte konnte nicht geprueft werden: ' . $e->getMessage());
    }
}

function avatar_upload_dir(): string
{
    return __DIR__ . '/../uploads/avatars/';
}

function avatar_max_file_size(): int
{
    return 2 * 1024 * 1024;
}

function avatar_max_file_size_label(): string
{
    return '2 MB';
}

function avatar_allowed_mime_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];
}

function avatar_public_path(string $stored_name): string
{
    return 'uploads/avatars/' . basename($stored_name);
}

function avatar_url_from_path(?string $avatar_path): string
{
    $real_path = resolve_avatar_path($avatar_path);

    if (!$real_path || !is_file($real_path) || !is_readable($real_path)) {
        return '';
    }

    return 'avatar.php?v=' . filemtime($real_path);
}

function resolve_avatar_path(?string $avatar_path): ?string
{
    $avatar_path = (string)$avatar_path;
    $stored_name = basename($avatar_path);

    if ($stored_name === '' || !preg_match('#^uploads/avatars/[A-Za-z0-9_.-]+$#', $avatar_path)) {
        return null;
    }

    $path = avatar_upload_dir() . $stored_name;
    $real_path = realpath($path);
    $allowed_base = realpath(avatar_upload_dir());

    if (!$real_path || !$allowed_base || strpos($real_path, $allowed_base) !== 0) {
        return null;
    }

    return $real_path;
}
