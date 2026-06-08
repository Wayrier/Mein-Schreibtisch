<?php

// =======================================
// response.php
// Zweck: Einheitliche Redirects, Fehlerseiten und Formular-Schutz
// =======================================

require_once __DIR__ . '/flash.php';

function app_fail(string $message, int $status = 400, ?string $redirect_url = null): void
{
    if ($redirect_url !== null) {
        app_flash('error', $message);
        header('Location: ' . $redirect_url, true, 303);
        exit;
    }

    http_response_code($status);
    $safe_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Fehler</title></head><body>';
    echo '<h1>Fehler</h1><p>' . $safe_message . '</p>';
    echo '</body></html>';
    exit;
}

function app_redirect(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

function app_redirect_success(string $url, string $message): void
{
    app_flash('success', $message);
    app_redirect($url);
}

function app_redirect_info(string $url, string $message): void
{
    app_flash('info', $message);
    app_redirect($url);
}

function app_redirect_error(string $url, string $message): void
{
    app_flash('error', $message);
    app_redirect($url);
}

function app_require_post(?string $redirect_url = null): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        app_fail('Nur POST-Anfragen sind erlaubt.', 405, $redirect_url);
    }
}

function app_csrf_is_valid($posted_token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($posted_token)
        && hash_equals((string)$_SESSION['csrf_token'], $posted_token);
}

function app_require_csrf(?string $redirect_url = null): void
{
    $posted_token = $_POST['csrf_token'] ?? '';

    if (!app_csrf_is_valid($posted_token)) {
        app_fail('Sicherheitsvalidierung fehlgeschlagen.', 403, $redirect_url);
    }
}
