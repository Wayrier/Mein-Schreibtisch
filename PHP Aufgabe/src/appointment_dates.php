<?php

// =======================================
// appointment_dates.php
// Zweck: Einheitliche Vorschlaege fuer Termin-Datum/Uhrzeit
// =======================================

function appointment_datetime_value(DateTimeInterface $date): string
{
    return $date->format('Y-m-d\TH:i');
}

function appointment_storage_datetime_value(DateTimeInterface $date): string
{
    return $date->format('Y-m-d H:i:s');
}

function appointment_ensure_start_date_column(PDO $pdo): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'start_date'");

        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN start_date DATETIME NULL AFTER subject");
        }
    } catch (PDOException $e) {
        error_log('Startdatum-Spalte konnte nicht geprueft werden: ' . $e->getMessage());
    }
}

function appointment_parse_datetime_local(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    $date_errors = DateTimeImmutable::getLastErrors();
    $date_has_errors = $date_errors !== false && (
        $date_errors['warning_count'] > 0 ||
        $date_errors['error_count'] > 0
    );

    if (!$date || $date_has_errors) {
        return null;
    }

    return $date;
}

function appointment_default_start_date_value(): string
{
    return appointment_datetime_value(new DateTimeImmutable());
}

function appointment_default_due_date_value(): string
{
    $now = new DateTimeImmutable();
    $today_late_afternoon = $now->setTime(17, 0);

    if ($today_late_afternoon > $now) {
        return appointment_datetime_value($today_late_afternoon);
    }

    return appointment_datetime_value($now->modify('+1 day')->setTime(9, 0));
}

function appointment_start_date_input_value(?string $start_date, ?string $due_date): string
{
    $timestamp = strtotime((string)($start_date ?: $due_date));

    if ($timestamp) {
        return date('Y-m-d\TH:i', $timestamp);
    }

    return appointment_default_start_date_value();
}

function appointment_due_date_presets(): array
{
    $now = new DateTimeImmutable();
    $presets = [];
    $today_late_afternoon = $now->setTime(17, 0);

    if ($today_late_afternoon > $now) {
        $presets[] = [
            'label' => 'Heute 17:00',
            'value' => appointment_datetime_value($today_late_afternoon)
        ];
    }

    $presets[] = [
        'label' => 'Morgen 09:00',
        'value' => appointment_datetime_value($now->modify('+1 day')->setTime(9, 0))
    ];

    $presets[] = [
        'label' => 'Naechste Woche 09:00',
        'value' => appointment_datetime_value($now->modify('+7 days')->setTime(9, 0))
    ];

    return $presets;
}
