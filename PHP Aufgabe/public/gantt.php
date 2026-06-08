<?php

// =======================================
// gantt.php
// Zweck: Termine als Gantt-Diagramm anzeigen
// =======================================

require_once '../src/session_check.php';
require_once '../config/database.php';
require_once '../src/layout.php';
require_once '../src/appointment_dates.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

appointment_ensure_start_date_column($pdo);

function gantt_parse_date_param($value): ?DateTimeImmutable
{
    if (!is_scalar($value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', trim((string)$value));
    $date_errors = DateTimeImmutable::getLastErrors();
    $date_has_errors = $date_errors !== false && (
        $date_errors['warning_count'] > 0 ||
        $date_errors['error_count'] > 0
    );

    if (!$date || $date_has_errors) {
        return null;
    }

    return $date->setTime(0, 0);
}

function gantt_day_from_datetime(?string $value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return null;
    }

    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
}

function gantt_days_between(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    return (int)$start->diff($end)->format('%a');
}

function gantt_datetime_display(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('d.m.Y H:i', $timestamp) : $value;
}

function gantt_weekday_label(DateTimeImmutable $date): string
{
    $labels = [
        1 => 'Mo',
        2 => 'Di',
        3 => 'Mi',
        4 => 'Do',
        5 => 'Fr',
        6 => 'Sa',
        7 => 'So'
    ];

    return $labels[(int)$date->format('N')] ?? '';
}

function gantt_status_label(string $status): string
{
    $labels = [
        'open' => 'Offen',
        'done' => 'Erledigt',
        'cancelled' => 'Abgebrochen'
    ];

    return $labels[$status] ?? $status;
}

function gantt_status_class(string $status): string
{
    $classes = [
        'open' => 'gantt-bar-open',
        'done' => 'gantt-bar-done',
        'cancelled' => 'gantt-bar-cancelled'
    ];

    return $classes[$status] ?? 'gantt-bar-open';
}

$stmt = $pdo->prepare("
    SELECT id, subject, start_date, due_date, content, status
    FROM appointments
    WHERE user_id = :user_id
    ORDER BY COALESCE(start_date, due_date) ASC, due_date ASC, id ASC
");

$stmt->execute([
    'user_id' => $user_id
]);

$appointments = [];

foreach ($stmt->fetchAll() as $appointment) {
    $start_value = (string)($appointment['start_date'] ?: $appointment['due_date']);
    $due_value = (string)$appointment['due_date'];
    $start_day = gantt_day_from_datetime($start_value);
    $end_day = gantt_day_from_datetime($due_value);

    if (!$start_day || !$end_day) {
        continue;
    }

    if ($end_day < $start_day) {
        $swap_day = $start_day;
        $start_day = $end_day;
        $end_day = $swap_day;
    }

    $status = (string)($appointment['status'] ?? 'open');

    $appointments[] = [
        'id' => (int)$appointment['id'],
        'subject' => (string)$appointment['subject'],
        'content' => (string)($appointment['content'] ?? ''),
        'start_value' => $start_value,
        'due_value' => $due_value,
        'start_day' => $start_day,
        'end_day' => $end_day,
        'status' => $status
    ];
}

$today = (new DateTimeImmutable('today'))->setTime(0, 0);
$range_start = $today;
$range_end = $today->modify('+30 days');

if (!empty($appointments)) {
    $range_start = $appointments[0]['start_day'];
    $range_end = $appointments[0]['end_day'];

    foreach ($appointments as $appointment) {
        if ($appointment['start_day'] < $range_start) {
            $range_start = $appointment['start_day'];
        }

        if ($appointment['end_day'] > $range_end) {
            $range_end = $appointment['end_day'];
        }
    }

    $range_start = $range_start->modify('-1 day');
    $range_end = $range_end->modify('+1 day');
}

$requested_start = gantt_parse_date_param($_GET['from'] ?? null);
$requested_end = gantt_parse_date_param($_GET['to'] ?? null);

if ($requested_start) {
    $range_start = $requested_start;
}

if ($requested_end) {
    $range_end = $requested_end;
}

if ($range_end < $range_start) {
    $swap_range = $range_start;
    $range_start = $range_end;
    $range_end = $swap_range;
}

$visible_appointments = array_values(array_filter($appointments, function (array $appointment) use ($range_start, $range_end): bool {
    return $appointment['start_day'] <= $range_end && $appointment['end_day'] >= $range_start;
}));

$visible_status_counts = [
    'open' => 0,
    'done' => 0,
    'cancelled' => 0
];

foreach ($visible_appointments as $appointment) {
    if (isset($visible_status_counts[$appointment['status']])) {
        $visible_status_counts[$appointment['status']]++;
    }
}

$days = [];
$cursor = $range_start;

while ($cursor <= $range_end) {
    $days[] = $cursor;
    $cursor = $cursor->modify('+1 day');
}

$day_count = count($days);
$today_offset = null;

if ($today >= $range_start && $today <= $range_end) {
    $today_offset = gantt_days_between($range_start, $today);
}

app_render_header('Gantt', 'gantt', [
    'subtitle' => 'Zeitleiste fuer Termine und Aufgaben.',
    'actions' => '<a class="button button-secondary" href="appointments.php">Termine verwalten</a><a class="button button-green" href="create_appointment.php">+ Neuer Termin</a>',
    'wide' => true
]);
?>

<section class="panel gantt-toolbar">
    <form method="GET" class="gantt-filter-form">
        <label>
            Von
            <input type="date" name="from" value="<?= e($range_start->format('Y-m-d')) ?>">
        </label>

        <label>
            Bis
            <input type="date" name="to" value="<?= e($range_end->format('Y-m-d')) ?>">
        </label>

        <div class="form-actions form-actions-left">
            <button type="submit">Ansicht aktualisieren</button>
            <a class="button button-secondary" href="gantt.php">Zuruecksetzen</a>
        </div>
    </form>

    <div class="gantt-summary" aria-label="Gantt-Zusammenfassung">
        <span><strong><?= count($visible_appointments) ?></strong> sichtbar</span>
        <span><strong><?= (int)$visible_status_counts['open'] ?></strong> offen</span>
        <span><strong><?= (int)$visible_status_counts['done'] ?></strong> erledigt</span>
        <span><strong><?= (int)$visible_status_counts['cancelled'] ?></strong> abgebrochen</span>
    </div>
</section>

<?php if (empty($appointments)): ?>
    <section class="panel files-empty-panel">
        <div class="empty-hero">
            <span class="empty-hero-icon">&#9636;</span>
            <h2>Noch keine Termine vorhanden.</h2>
            <p>Erstelle einen Termin mit Start- und Enddatum, dann erscheint hier automatisch die Gantt-Zeitleiste.</p>
            <div class="empty-hero-actions">
                <a class="button button-green" href="create_appointment.php">Termin erstellen</a>
            </div>
        </div>
    </section>
<?php elseif (empty($visible_appointments)): ?>
    <p class="empty-state">Keine Termine im ausgewaehlten Zeitraum.</p>
<?php else: ?>
    <section class="panel gantt-panel">
        <div class="gantt-legend" aria-label="Status-Legende">
            <span><i class="gantt-legend-dot gantt-legend-open"></i>Offen</span>
            <span><i class="gantt-legend-dot gantt-legend-done"></i>Erledigt</span>
            <span><i class="gantt-legend-dot gantt-legend-cancelled"></i>Abgebrochen</span>
            <?php if ($today_offset !== null): ?>
                <span><i class="gantt-legend-line"></i>Heute</span>
            <?php endif; ?>
        </div>

        <div class="gantt-scroll" aria-label="Gantt-Diagramm">
            <div class="gantt-grid" style="--gantt-days: <?= (int)$day_count ?>; --gantt-width: <?= (int)$day_count * 46 ?>px;">
                <div class="gantt-task-head">Aufgabe</div>
                <div class="gantt-days-head">
                    <?php foreach ($days as $day): ?>
                        <?php
                        $is_weekend = (int)$day->format('N') >= 6;
                        $is_today = $day->format('Y-m-d') === $today->format('Y-m-d');
                        ?>
                        <div class="gantt-day <?= $is_weekend ? 'is-weekend' : '' ?> <?= $is_today ? 'is-today' : '' ?>">
                            <span><?= e(gantt_weekday_label($day)) ?></span>
                            <strong><?= e($day->format('d')) ?></strong>
                            <small><?= e($day->format('m.Y')) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($visible_appointments as $appointment): ?>
                    <?php
                    $bar_start = $appointment['start_day'] < $range_start ? $range_start : $appointment['start_day'];
                    $bar_end = $appointment['end_day'] > $range_end ? $range_end : $appointment['end_day'];
                    $offset = gantt_days_between($range_start, $bar_start);
                    $duration = max(1, gantt_days_between($bar_start, $bar_end) + 1);
                    $duration_label = $duration === 1 ? '1 Tag' : $duration . ' Tage';
                    ?>
                    <a class="gantt-task-cell" href="edit_appointment.php?id=<?= (int)$appointment['id'] ?>">
                        <strong><?= e($appointment['subject']) ?></strong>
                        <span><?= e(gantt_datetime_display($appointment['start_value'])) ?> - <?= e(gantt_datetime_display($appointment['due_value'])) ?></span>
                        <small><?= e(gantt_status_label($appointment['status'])) ?></small>
                    </a>
                    <div class="gantt-track">
                        <?php if ($today_offset !== null): ?>
                            <span class="gantt-today-line" style="grid-column: <?= (int)$today_offset + 1 ?>;"></span>
                        <?php endif; ?>
                        <a
                            class="gantt-bar <?= e(gantt_status_class($appointment['status'])) ?>"
                            href="edit_appointment.php?id=<?= (int)$appointment['id'] ?>"
                            style="grid-column: <?= (int)$offset + 1 ?> / span <?= (int)$duration ?>;"
                            title="<?= e($appointment['subject'] . ' | ' . gantt_datetime_display($appointment['start_value']) . ' - ' . gantt_datetime_display($appointment['due_value'])) ?>"
                        >
                            <span><?= e($duration_label) ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php app_render_footer(); ?>
