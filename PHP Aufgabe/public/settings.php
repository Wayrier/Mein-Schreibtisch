<?php

// =======================================
// settings.php
// Zweck: Eigene Einstellungen bearbeiten
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$csrf_token = $_SESSION['csrf_token'];
$user_id = $_SESSION['user_id'];
$error = null;
$success = null;

$settings = [
    'weather_city' => 'Mannheim',
    'use_geolocation' => 0,
    'theme' => 'light',
    'language' => 'de'
];
$weather_city_options = [
    'Mannheim' => 'Mannheim',
    'Berlin' => 'Berlin',
    'Hamburg' => 'Hamburg',
    'Munich' => 'Muenchen',
    'Frankfurt' => 'Frankfurt',
    'Stuttgart' => 'Stuttgart',
    'Cologne' => 'Koeln',
    'Dortmund' => 'Dortmund',
    'Bremen' => 'Bremen',
    'Leipzig' => 'Leipzig',
    'Dresden' => 'Dresden',
    'Hannover' => 'Hannover',
    'Nuremberg' => 'Nuernberg'
];

function load_settings(PDO $pdo, int $user_id, array $defaults): array
{
    $stmt = $pdo->prepare("
        SELECT weather_city, use_geolocation, theme, language
        FROM user_settings
        WHERE user_id = :user_id
    ");

    $stmt->execute([
        'user_id' => $user_id
    ]);

    $stored_settings = $stmt->fetch();

    if (!$stored_settings) {
        $stmt = $pdo->prepare("
            INSERT INTO user_settings (user_id, weather_city, use_geolocation, theme, language)
            VALUES (:user_id, :weather_city, :use_geolocation, :theme, :language)
        ");

        $stmt->execute([
            'user_id' => $user_id,
            'weather_city' => $defaults['weather_city'],
            'use_geolocation' => $defaults['use_geolocation'],
            'theme' => $defaults['theme'],
            'language' => $defaults['language']
        ]);

        return $defaults;
    }

    return [
        'weather_city' => $stored_settings['weather_city'] ?: $defaults['weather_city'],
        'use_geolocation' => (int)$stored_settings['use_geolocation'],
        'theme' => $stored_settings['theme'] ?: $defaults['theme'],
        'language' => $stored_settings['language'] ?: $defaults['language']
    ];
}

try {
    $settings = load_settings($pdo, (int)$user_id, $settings);
} catch (PDOException $e) {
    $error = "Einstellungen konnten nicht geladen werden.";
}

$_SESSION['theme'] = in_array((string)$settings['theme'], ['light', 'dark'], true)
    ? (string)$settings['theme']
    : 'light';
$_SESSION['language'] = in_array((string)$settings['language'], ['de', 'en'], true)
    ? (string)$settings['language']
    : 'de';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $posted_csrf_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($posted_csrf_token) ||
        !hash_equals($_SESSION['csrf_token'], $posted_csrf_token)
    ) {
        $error = "Sicherheitsvalidierung fehlgeschlagen.";
    } else {
        $weather_city = is_string($_POST['weather_city'] ?? null) ? trim($_POST['weather_city']) : '';
        $use_geolocation = isset($_POST['use_geolocation']) ? 1 : 0;
        $theme = is_string($_POST['theme'] ?? null) ? $_POST['theme'] : 'light';
        $language = is_string($_POST['language'] ?? null) ? $_POST['language'] : 'de';

        $allowed_themes = ['light', 'dark'];
        $allowed_languages = ['de', 'en'];
        $allowed_weather_cities = array_keys($weather_city_options);

        if (!in_array($weather_city, $allowed_weather_cities, true)) {
            $error = "Ungueltige Wetterstadt.";
            $weather_city = $settings['weather_city'];
        } elseif (!in_array($theme, $allowed_themes, true)) {
            $error = "Ungueltiges Theme.";
            $theme = 'light';
        } elseif (!in_array($language, $allowed_languages, true)) {
            $error = "Ungueltige Sprache.";
            $language = 'de';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE user_settings
                    SET weather_city = :weather_city,
                        use_geolocation = :use_geolocation,
                        theme = :theme,
                        language = :language
                    WHERE user_id = :user_id
                ");

                $stmt->execute([
                    'weather_city' => $weather_city,
                    'use_geolocation' => $use_geolocation,
                    'theme' => $theme,
                    'language' => $language,
                    'user_id' => $user_id
                ]);

                if ($stmt->rowCount() === 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_settings (user_id, weather_city, use_geolocation, theme, language)
                        VALUES (:user_id, :weather_city, :use_geolocation, :theme, :language)
                        ON DUPLICATE KEY UPDATE
                            weather_city = VALUES(weather_city),
                            use_geolocation = VALUES(use_geolocation),
                            theme = VALUES(theme),
                            language = VALUES(language)
                    ");

                    $stmt->execute([
                        'user_id' => $user_id,
                        'weather_city' => $weather_city,
                        'use_geolocation' => $use_geolocation,
                        'theme' => $theme,
                        'language' => $language
                    ]);
                }

                $settings = [
                    'weather_city' => $weather_city,
                    'use_geolocation' => $use_geolocation,
                    'theme' => $theme,
                    'language' => $language
                ];
                $_SESSION['theme'] = $theme;
                $_SESSION['language'] = $language;
                $success = $language === 'en' ? "Settings saved." : "Einstellungen wurden gespeichert.";
            } catch (PDOException $e) {
                $error = "Einstellungen konnten nicht gespeichert werden.";
            }
        }
    }
}

?>

<?php
$is_english = ($_SESSION['language'] ?? 'de') === 'en';
$labels = $is_english ? [
    'title' => 'Settings',
    'subtitle' => 'Adjust weather, display and language.',
    'weather' => 'Weather',
    'weather_city' => 'Weather city:',
    'use_location' => 'Use browser location for weather',
    'location_help' => 'If location access is not allowed, the dashboard uses the weather city.',
    'display' => 'Display',
    'theme' => 'Theme:',
    'light' => 'Light',
    'dark' => 'Dark',
    'language' => 'Language:',
    'german' => 'German',
    'english' => 'English',
    'save' => 'Save settings'
] : [
    'title' => 'Einstellungen',
    'subtitle' => 'Passe Wetter, Anzeige und Sprache an.',
    'weather' => 'Wetter',
    'weather_city' => 'Wetterstadt:',
    'use_location' => 'Browser-Standort fuer Wetter verwenden',
    'location_help' => 'Falls der Standort nicht erlaubt wird, nutzt das Dashboard die Wetterstadt.',
    'display' => 'Anzeige',
    'theme' => 'Theme:',
    'light' => 'Hell',
    'dark' => 'Dunkel',
    'language' => 'Sprache:',
    'german' => 'Deutsch',
    'english' => 'Englisch',
    'save' => 'Einstellungen speichern'
];

app_render_header($labels['title'], 'settings', [
    'subtitle' => $labels['subtitle']
]);
?>

<?php if ($error): ?>
    <p class="message-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p class="message-success"><?= htmlspecialchars($success) ?></p>
<?php endif; ?>

<form method="POST" class="panel">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <h2><?= e($labels['weather']) ?></h2>

    <label><?= e($labels['weather_city']) ?></label><br>
    <select name="weather_city" required>
        <?php foreach ($weather_city_options as $city_value => $city_label): ?>
            <option value="<?= e($city_value) ?>" <?= $settings['weather_city'] === $city_value ? 'selected' : '' ?>><?= e($city_label) ?></option>
        <?php endforeach; ?>
    </select>
    <br><br>

    <label>
        <input type="checkbox" name="use_geolocation" value="1" <?= (int)$settings['use_geolocation'] === 1 ? 'checked' : '' ?>>
        <?= e($labels['use_location']) ?>
    </label>
    <br><small><?= e($labels['location_help']) ?></small>

    <hr>

    <h2><?= e($labels['display']) ?></h2>

    <label><?= e($labels['theme']) ?></label><br>
    <select name="theme">
        <option value="light" <?= $settings['theme'] === 'light' ? 'selected' : '' ?>><?= e($labels['light']) ?></option>
        <option value="dark" <?= $settings['theme'] === 'dark' ? 'selected' : '' ?>><?= e($labels['dark']) ?></option>
    </select>
    <br><br>

    <label><?= e($labels['language']) ?></label><br>
    <select name="language">
        <option value="de" <?= $settings['language'] === 'de' ? 'selected' : '' ?>><?= e($labels['german']) ?></option>
        <option value="en" <?= $settings['language'] === 'en' ? 'selected' : '' ?>><?= e($labels['english']) ?></option>
    </select>
    <br><br>

    <button type="submit"><?= e($labels['save']) ?></button>
</form>

<?php app_render_footer(); ?>
