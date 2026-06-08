<?php

// =======================================
// storage_quota.php
// Zweck: Gemeinsame Speicherplatz-Regeln
// =======================================

require_once __DIR__ . '/../config/app.php';

function app_storage_quota_bytes(): int
{
    return defined('APP_STORAGE_QUOTA_BYTES')
        ? (int)APP_STORAGE_QUOTA_BYTES
        : 10 * 1024 * 1024 * 1024;
}

function app_storage_format_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) {
        return round($bytes / 1024 / 1024 / 1024, 1) . ' GB';
    }

    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}

function app_storage_used_bytes(PDO $pdo, int $user_id): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(file_size), 0) AS used_bytes
        FROM files
        WHERE user_id = :user_id
    ");
    $stmt->execute(['user_id' => $user_id]);

    return (int)($stmt->fetch()['used_bytes'] ?? 0);
}

function app_storage_quota_error(PDO $pdo, int $user_id, int $additional_bytes): ?string
{
    $quota_bytes = app_storage_quota_bytes();
    $used_bytes = app_storage_used_bytes($pdo, $user_id);

    if ($quota_bytes <= 0 || $used_bytes + $additional_bytes <= $quota_bytes) {
        return null;
    }

    $remaining_bytes = max(0, $quota_bytes - $used_bytes);

    return 'Speicherlimit erreicht. Frei: '
        . app_storage_format_size($remaining_bytes)
        . ', benoetigt: '
        . app_storage_format_size($additional_bytes)
        . '.';
}
