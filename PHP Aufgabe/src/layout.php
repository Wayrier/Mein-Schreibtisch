<?php

// =======================================
// layout.php
// Zweck: Gemeinsames App-Layout fuer alle Seiten
// =======================================

require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/storage_quota.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function app_language(): string
{
    return ($_SESSION['language'] ?? 'de') === 'en' ? 'en' : 'de';
}

function app_text(string $key): string
{
    $language = app_language();
    $texts = [
        'de' => [
            'overview' => 'Uebersicht',
            'notes' => 'Notizen',
            'appointments' => 'Termine',
            'gantt' => 'Gantt',
            'files' => 'Dateien',
            'backup' => 'Backup',
            'settings' => 'Einstellungen',
            'profile' => 'Profil',
            'users' => 'Usermanagement',
            'logout' => 'Abmelden',
            'search' => 'Suchen in Notizen, Terminen, Dateien...',
            'weather' => 'Teilweise bewoelkt',
            'superuser' => 'Superuser',
            'standard' => 'Standard',
            'storage' => 'Speicherplatz',
            'used_of' => 'von %s verwendet',
            'design' => 'Design',
            'light_design' => 'Helles Design',
            'dark_design' => 'Dunkles Design',
            'nav_toggle' => 'Navigation umschalten',
            'to_overview' => 'Zur Uebersicht'
        ],
        'en' => [
            'overview' => 'Overview',
            'notes' => 'Notes',
            'appointments' => 'Appointments',
            'gantt' => 'Gantt',
            'files' => 'Files',
            'backup' => 'Backup',
            'settings' => 'Settings',
            'profile' => 'Profile',
            'users' => 'User management',
            'logout' => 'Sign out',
            'search' => 'Search notes, appointments, files...',
            'weather' => 'Partly cloudy',
            'superuser' => 'Superuser',
            'standard' => 'Standard',
            'storage' => 'Storage',
            'used_of' => 'of %s used',
            'design' => 'Theme',
            'light_design' => 'Light theme',
            'dark_design' => 'Dark theme',
            'nav_toggle' => 'Toggle navigation',
            'to_overview' => 'Go to overview'
        ]
    ];

    return $texts[$language][$key] ?? $texts['de'][$key] ?? $key;
}

function app_asset_url(string $path): string
{
    $public_path = dirname(__DIR__) . '/public/' . ltrim($path, '/');
    $version = is_file($public_path) ? '?v=' . filemtime($public_path) : '';

    return $path . $version;
}

function app_nav_items(string $role): array
{
    $items = [
        'dashboard' => ['href' => 'dashboard.php', 'label' => app_text('overview'), 'icon' => '&#8962;'],
        'notes' => ['href' => 'notes.php', 'label' => app_text('notes'), 'icon' => '&#9998;'],
        'appointments' => ['href' => 'appointments.php', 'label' => app_text('appointments'), 'icon' => '&#9633;'],
        'gantt' => ['href' => 'gantt.php', 'label' => app_text('gantt'), 'icon' => '&#9636;'],
        'files' => ['href' => 'files.php', 'label' => app_text('files'), 'icon' => '&#128193;'],
        'backup' => ['href' => 'backup.php', 'label' => app_text('backup'), 'icon' => '&#8635;'],
        'settings' => ['href' => 'settings.php', 'label' => app_text('settings'), 'icon' => '&#9881;'],
        'profile' => ['href' => 'change_password.php', 'label' => app_text('profile'), 'icon' => '&#9675;']
    ];

    if ($role === 'admin') {
        $items['users'] = ['href' => 'users.php', 'label' => app_text('users'), 'icon' => '&#9783;'];
    }

    $items['logout'] = ['href' => 'logout.php', 'label' => app_text('logout'), 'icon' => '&#8594;'];

    return $items;
}

function app_format_storage_size(int $bytes): string
{
    return app_storage_format_size($bytes);
}

function app_storage_stats(): array
{
    global $pdo;

    $quota_bytes = app_storage_quota_bytes();
    $used_bytes = 0;

    if (isset($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
        try {
            $used_bytes = app_storage_used_bytes($pdo, (int)$_SESSION['user_id']);
        } catch (PDOException $e) {
            error_log("Speicherplatz konnte nicht geladen werden: " . $e->getMessage());
        }
    }

    $percent = $quota_bytes > 0 ? min(100, round(($used_bytes / $quota_bytes) * 100, 1)) : 0;

    return [
        'used_label' => app_format_storage_size($used_bytes),
        'quota_label' => app_format_storage_size($quota_bytes),
        'percent' => $percent
    ];
}

function app_current_avatar_url(): string
{
    global $pdo;

    $avatar_path = (string)($_SESSION['avatar_path'] ?? '');

    if (
        $avatar_path === '' &&
        isset($_SESSION['user_id']) &&
        empty($_SESSION['avatar_loaded']) &&
        isset($pdo) &&
        $pdo instanceof PDO
    ) {
        try {
            ensure_avatar_column($pdo);
            $stmt = $pdo->prepare("SELECT avatar_path FROM users WHERE id = :id");
            $stmt->execute(['id' => (int)$_SESSION['user_id']]);
            $avatar_user = $stmt->fetch();
            $avatar_path = (string)($avatar_user['avatar_path'] ?? '');
            $_SESSION['avatar_path'] = $avatar_path;
            $_SESSION['avatar_loaded'] = true;
        } catch (PDOException $e) {
            $_SESSION['avatar_loaded'] = true;
            error_log('Avatar konnte nicht geladen werden: ' . $e->getMessage());
        }
    }

    return avatar_url_from_path($avatar_path);
}

function app_render_header(string $title, string $active = 'dashboard', array $options = []): void
{
    $username = (string)($_SESSION['username'] ?? 'Benutzer');
    $role = (string)($_SESSION['role'] ?? 'standard');
    $language = app_language();
    $role_label = $role === 'admin' ? app_text('superuser') : app_text('standard');
    $initials = strtoupper(substr(trim($username), 0, 1) ?: 'U');
    $subtitle = (string)($options['subtitle'] ?? '');
    $actions = (string)($options['actions'] ?? '');
    $wide = !empty($options['wide']);
    $theme = ($_SESSION['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
    $storage = app_storage_stats();
    $show_heading = !array_key_exists('show_heading', $options) || (bool)$options['show_heading'];
    $avatar_url = app_current_avatar_url();
    ?>
<!DOCTYPE html>
<html lang="<?= e($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - MeinSchreibtisch</title>
    <meta name="csrf-token" content="<?= e((string)($_SESSION['csrf_token'] ?? '')) ?>">
    <link rel="stylesheet" href="<?= e(app_asset_url('assets/app.css')) ?>">
</head>
<body class="app-body <?= $theme === 'dark' ? 'dark-mode' : '' ?>" data-theme="<?= e($theme) ?>" data-language="<?= e($language) ?>">
<header class="app-topbar">
    <div class="brand">
        <a class="brand-mark" href="dashboard.php" aria-label="<?= e(app_text('to_overview')) ?>">MS</a>
        <a class="brand-name" href="dashboard.php">MeinSchreibtisch</a>
        <button class="icon-button js-sidebar-toggle" type="button" aria-label="<?= e(app_text('nav_toggle')) ?>" aria-expanded="false" aria-controls="app-sidebar">&#9776;</button>
    </div>

    <div class="topbar-search js-global-search" aria-label="<?= e(app_text('search')) ?>">
        <span class="search-icon">&#9906;</span>
        <input class="js-global-search-input" type="search" placeholder="<?= e(app_text('search')) ?>" autocomplete="off">
        <span class="search-shortcut">Ctrl K</span>
        <div class="search-results js-global-search-results" hidden></div>
    </div>

    <div class="topbar-status">
        <div class="weather-pill">
            <span class="weather-symbol">22&deg;C</span>
            <span><?= e(app_text('weather')) ?></span>
        </div>
        <div class="clock-pill">
            <strong class="js-topbar-time"><?= e(date('H:i:s')) ?></strong>
            <span class="js-topbar-date"><?= e(date('d.m.Y')) ?></span>
        </div>
        <details class="user-menu js-user-menu">
            <summary class="user-pill js-user-menu-toggle" aria-expanded="false">
                <span class="avatar">
                    <?php if ($avatar_url !== ''): ?>
                        <img src="<?= e($avatar_url) ?>" alt="">
                    <?php else: ?>
                        <?= e($initials) ?>
                    <?php endif; ?>
                </span>
                <span>
                    <strong><?= e($username) ?></strong>
                </span>
            </summary>

            <div class="user-dropdown js-user-dropdown">
                <div class="user-dropdown-head">
                    <span class="avatar avatar-menu">
                        <?php if ($avatar_url !== ''): ?>
                            <img src="<?= e($avatar_url) ?>" alt="">
                        <?php else: ?>
                            <?= e($initials) ?>
                        <?php endif; ?>
                    </span>
                    <span>
                        <strong><?= e($username) ?></strong>
                        <small><?= e($role_label) ?></small>
                    </span>
                </div>
                <a href="change_password.php"><span class="dropdown-icon">&#9675;</span><?= e(app_text('profile')) ?></a>
                <a href="settings.php"><span class="dropdown-icon">&#9881;</span><?= e(app_text('settings')) ?></a>
                <?php if ($role === 'admin'): ?>
                    <a href="users.php"><span class="dropdown-icon">&#9783;</span><?= e(app_text('users')) ?></a>
                <?php endif; ?>
                <span class="user-dropdown-divider"></span>
                <a class="user-dropdown-logout" href="logout.php"><span class="dropdown-icon">&#8594;</span><?= e(app_text('logout')) ?></a>
            </div>
        </details>
    </div>
</header>

<div class="sidebar-overlay js-sidebar-overlay" hidden></div>

<aside class="app-sidebar" id="app-sidebar">
    <nav class="side-nav" aria-label="Hauptnavigation">
        <?php foreach (app_nav_items($role) as $key => $item): ?>
            <a class="side-nav-link <?= $active === $key ? 'is-active' : '' ?>" href="<?= e($item['href']) ?>">
                <span class="side-nav-icon"><?= $item['icon'] ?></span>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <a class="sidebar-card sidebar-card-link" href="files.php">
        <span class="sidebar-card-label"><?= e(app_text('storage')) ?></span>
        <strong><?= e($storage['used_label']) ?></strong>
        <span><?= e(sprintf(app_text('used_of'), $storage['quota_label'])) ?></span>
        <div class="progress" data-progress-percent="<?= e((string)$storage['percent']) ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string)$storage['percent']) ?>">
            <span class="progress-fill"></span>
        </div>
        <span><?= e((string)$storage['percent']) ?>%</span>
    </a>

    <div class="sidebar-theme">
        <span>Design</span>
        <button type="button" class="theme-dot <?= $theme === 'light' ? 'is-active' : '' ?>" data-theme="light" aria-label="Helles Design">&#9788;</button>
        <button type="button" class="theme-dot <?= $theme === 'dark' ? 'is-active' : '' ?>" data-theme="dark" aria-label="Dunkles Design">&#9790;</button>
    </div>
</aside>

<main class="app-main <?= $wide ? 'app-main-wide' : '' ?>">
    <?php if ($show_heading): ?>
        <section class="page-heading">
            <div>
                <p class="eyebrow">MeinSchreibtisch</p>
                <h1><?= e($title) ?></h1>
                <?php if ($subtitle !== ''): ?>
                    <p><?= e($subtitle) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($actions !== ''): ?>
                <div class="page-actions"><?= $actions ?></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php foreach (app_take_flashes() as $flash): ?>
        <?php
        $flash_type = is_array($flash) ? (string)($flash['type'] ?? 'info') : 'info';
        $flash_message = is_array($flash) ? (string)($flash['message'] ?? '') : '';
        $flash_class = $flash_type === 'success' ? 'message-success' : ($flash_type === 'error' ? 'message-error' : 'message-info');
        ?>
        <?php if ($flash_message !== ''): ?>
            <p class="<?= e($flash_class) ?>"><?= e($flash_message) ?></p>
        <?php endif; ?>
    <?php endforeach; ?>
<?php
}

function app_render_footer(): void
{
    ?>
</main>
<script src="<?= e(app_asset_url('assets/app.js')) ?>"></script>
</body>
</html>
<?php
}
