<?php

// =======================================
// dashboard_service.php
// Zweck: Daten und Anzeige-Helfer fuer das Dashboard
// =======================================

function dashboard_datetime(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('d.m.Y H:i', $timestamp) : $value;
}

function dashboard_relative_time(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return dashboard_datetime($value);
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'gerade eben';
    }

    if ($diff < 3600) {
        return 'vor ' . floor($diff / 60) . ' Min.';
    }

    if ($diff < 86400) {
        return 'vor ' . floor($diff / 3600) . ' Std.';
    }

    if ($diff < 172800) {
        return 'gestern';
    }

    return date('d.m.Y', $timestamp);
}

function dashboard_excerpt(?string $value, int $limit = 58): string
{
    $text = trim((string)$value);

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function dashboard_appointment_state(array $appointment): array
{
    $status = (string)($appointment['status'] ?? 'open');

    if ($status === 'done') {
        return [
            'row_class' => 'appointment-state-done',
            'dot_class' => 'dot-green',
            'pill_class' => 'status-pill-done',
            'label' => 'Erledigt'
        ];
    }

    if ($status === 'cancelled') {
        return [
            'row_class' => 'appointment-state-muted',
            'dot_class' => 'dot-blue',
            'pill_class' => 'status-pill-muted',
            'label' => 'Abgebrochen'
        ];
    }

    $timestamp = strtotime((string)($appointment['due_date'] ?? ''));
    $today_start = strtotime('today');
    $tomorrow_start = strtotime('tomorrow');

    if ($timestamp && $timestamp < $today_start) {
        return [
            'row_class' => 'appointment-state-overdue',
            'dot_class' => 'dot-red',
            'pill_class' => 'status-pill-overdue',
            'label' => 'Ueberfaellig'
        ];
    }

    if ($timestamp && $timestamp < $tomorrow_start) {
        return [
            'row_class' => 'appointment-state-today',
            'dot_class' => 'dot-yellow',
            'pill_class' => 'status-pill-today',
            'label' => 'Heute'
        ];
    }

    return [
        'row_class' => 'appointment-state-upcoming',
        'dot_class' => 'dot-green',
        'pill_class' => 'status-pill-upcoming',
        'label' => 'Kommend'
    ];
}

function dashboard_default_data(): array
{
    return [
        'weather_city' => 'Mannheim',
        'use_geolocation' => false,
        'notes' => [],
        'appointments' => [],
        'stats' => [
            'notes' => 0,
            'appointments' => 0
        ]
    ];
}

function dashboard_load_data(PDO $pdo, int $user_id): array
{
    $data = dashboard_default_data();

    try {
        $stmt = $pdo->prepare("
            SELECT weather_city, use_geolocation
            FROM user_settings
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $user_id]);
        $settings = $stmt->fetch();

        if ($settings) {
            $data['weather_city'] = trim((string)($settings['weather_city'] ?? '')) ?: $data['weather_city'];
            $data['use_geolocation'] = (bool)$settings['use_geolocation'];
        }

        $stmt = $pdo->prepare("
            SELECT id, title, content, created_at
            FROM notes
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute(['user_id' => $user_id]);
        $data['notes'] = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT id, subject, due_date, status
            FROM appointments
            WHERE user_id = :user_id
            ORDER BY due_date ASC
            LIMIT 5
        ");
        $stmt->execute(['user_id' => $user_id]);
        $data['appointments'] = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM notes WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $data['stats']['notes'] = (int)$stmt->fetch()['total'];

        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM appointments WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $data['stats']['appointments'] = (int)$stmt->fetch()['total'];
    } catch (PDOException $e) {
        error_log("Dashboard konnte nicht geladen werden: " . $e->getMessage());
    }

    return $data;
}
