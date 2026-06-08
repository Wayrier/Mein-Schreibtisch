<?php

// =======================================
// auth.php
// Zweck: Login-Daten pruefen und Session starten
// =======================================

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/login_rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../public/login.php");
    exit;
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log('Login fehlgeschlagen: Datenbankverbindung fehlt.');
    header("Location: ../public/login.php?error=server");
    exit;
}

$posted_csrf_token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($posted_csrf_token) ||
    !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
) {
    header("Location: ../public/login.php?error=csrf");
    exit;
}

$posted_username = $_POST['username'] ?? '';
$posted_password = $_POST['password'] ?? '';
$username = is_scalar($posted_username) ? trim((string)$posted_username) : '';
$password = is_scalar($posted_password) ? (string)$posted_password : '';
$ip_address = login_rate_limit_ip();

try {
    $rate_status = login_rate_limit_status($pdo, $username, $ip_address);

    if ($rate_status['locked']) {
        header("Location: ../public/login.php?error=locked");
        exit;
    }
} catch (PDOException $e) {
    error_log('Login-Rate-Limit konnte nicht geprueft werden: ' . $e->getMessage());
    header("Location: ../public/login.php?error=server");
    exit;
}

function register_failed_login(PDO $pdo, string $username, string $ip_address): void
{
    try {
        login_rate_limit_register_failure($pdo, $username, $ip_address);
    } catch (PDOException $e) {
        error_log('Login-Fehlversuch konnte nicht gespeichert werden: ' . $e->getMessage());
    }
}

if ($username === '' || $password === '') {
    register_failed_login($pdo, $username, $ip_address);
    header("Location: ../public/login.php?error=1");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Login-Benutzer konnte nicht geladen werden: ' . $e->getMessage());
    header("Location: ../public/login.php?error=server");
    exit;
}

if (!$user || !password_verify($password, $user['password_hash'])) {
    register_failed_login($pdo, $username, $ip_address);
    header("Location: ../public/login.php?error=1");
    exit;
}

try {
    login_rate_limit_clear($pdo, $username, $ip_address);
} catch (PDOException $e) {
    error_log('Login-Rate-Limit konnte nicht geloescht werden: ' . $e->getMessage());
}

session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];
$_SESSION['avatar_path'] = (string)($user['avatar_path'] ?? '');
$_SESSION['avatar_loaded'] = true;
$_SESSION['last_activity'] = time();

try {
    $theme_stmt = $pdo->prepare("SELECT theme, language FROM user_settings WHERE user_id = :user_id");
    $theme_stmt->execute(['user_id' => $user['id']]);
    $theme_settings = $theme_stmt->fetch();
    $theme = (string)($theme_settings['theme'] ?? 'light');
    $language = (string)($theme_settings['language'] ?? 'de');
    $_SESSION['theme'] = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
    $_SESSION['language'] = in_array($language, ['de', 'en'], true) ? $language : 'de';
} catch (PDOException $e) {
    $_SESSION['theme'] = 'light';
    $_SESSION['language'] = 'de';
}

header("Location: ../public/dashboard.php");
exit;
