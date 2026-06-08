<?php

// =======================================
// file_display.php
// Zweck: Gemeinsame Anzeige-Helfer fuer Dateien
// =======================================

function app_file_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }

    return $bytes . ' B';
}

function app_file_datetime(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('d.m.Y H:i', $timestamp) : $value;
}

function app_file_download_href(array $file): string
{
    return 'download_file.php?id=' . (int)$file['id'];
}

function app_file_preview_href(array $file): string
{
    return 'preview_file.php?id=' . (int)$file['id'];
}

function app_file_can_preview(array $file): bool
{
    return in_array((string)($file['mime_type'] ?? ''), [
        'image/jpeg',
        'image/png',
        'application/pdf',
        'text/plain'
    ], true);
}

function app_file_type_label(array $file): string
{
    $mime_type = (string)($file['mime_type'] ?? '');

    if ($mime_type === 'application/pdf') {
        return 'PDF';
    }

    if (strpos($mime_type, 'image/') === 0) {
        return 'Bild';
    }

    if ($mime_type === 'text/plain') {
        return 'Text';
    }

    return $mime_type ?: 'Datei';
}

function app_file_type_icon(array $file): string
{
    $mime_type = (string)($file['mime_type'] ?? '');

    if ($mime_type === 'application/pdf') {
        return 'PDF';
    }

    if (strpos($mime_type, 'image/') === 0) {
        return 'IMG';
    }

    if ($mime_type === 'text/plain') {
        return 'TXT';
    }

    return 'FILE';
}
