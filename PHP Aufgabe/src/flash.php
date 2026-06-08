<?php

// =======================================
// flash.php
// Zweck: Kurze Meldungen nach Redirects in der Session speichern
// =======================================

function app_flash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $allowed_types = ['success', 'error', 'info'];
    $type = in_array($type, $allowed_types, true) ? $type : 'info';

    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

function app_take_flashes(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);

    return is_array($messages) ? $messages : [];
}
