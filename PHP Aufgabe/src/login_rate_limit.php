<?php

// =======================================
// login_rate_limit.php
// Zweck: Persistentes Login-Rate-Limit pro Benutzername/IP
// =======================================

function ensure_login_attempts_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(150) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            first_attempt_at DATETIME NOT NULL,
            last_attempt_at DATETIME NOT NULL,
            locked_until DATETIME NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_login_attempt (username, ip_address),
            INDEX idx_login_attempt_expiry (expires_at),
            INDEX idx_login_attempt_lock (locked_until)
        )
    ");
}

function login_rate_limit_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = is_string($ip) ? trim($ip) : 'unknown';

    return substr($ip !== '' ? $ip : 'unknown', 0, 45);
}

function login_rate_limit_username(string $username): string
{
    $username = strtolower(trim($username));

    return substr($username !== '' ? $username : '__empty__', 0, 150);
}

function login_rate_limit_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function login_rate_limit_datetime(DateTimeImmutable $date): string
{
    return $date->format('Y-m-d H:i:s');
}

function login_rate_limit_status(PDO $pdo, string $username, string $ip_address): array
{
    ensure_login_attempts_table($pdo);

    $now = login_rate_limit_now();
    $pdo->prepare("
        DELETE FROM login_attempts
        WHERE expires_at <= :now_expires
          AND (locked_until IS NULL OR locked_until <= :now_locked)
    ")->execute([
        'now_expires' => login_rate_limit_datetime($now),
        'now_locked' => login_rate_limit_datetime($now)
    ]);

    $stmt = $pdo->prepare("
        SELECT locked_until
        FROM login_attempts
        WHERE username = :username AND ip_address = :ip_address
        LIMIT 1
    ");
    $stmt->execute([
        'username' => login_rate_limit_username($username),
        'ip_address' => $ip_address
    ]);
    $row = $stmt->fetch();

    if (!$row || empty($row['locked_until'])) {
        return ['locked' => false, 'retry_after' => 0];
    }

    $locked_until = strtotime((string)$row['locked_until']);

    if (!$locked_until || $locked_until <= $now->getTimestamp()) {
        return ['locked' => false, 'retry_after' => 0];
    }

    return [
        'locked' => true,
        'retry_after' => max(1, $locked_until - $now->getTimestamp())
    ];
}

function login_rate_limit_register_failure(PDO $pdo, string $username, string $ip_address): void
{
    ensure_login_attempts_table($pdo);

    $max_attempts = 5;
    $window_seconds = 5 * 60;
    $lock_seconds = 5 * 60;
    $now = login_rate_limit_now();
    $normalized_username = login_rate_limit_username($username);

    $stmt = $pdo->prepare("
        SELECT id, attempt_count, expires_at
        FROM login_attempts
        WHERE username = :username AND ip_address = :ip_address
        LIMIT 1
    ");
    $stmt->execute([
        'username' => $normalized_username,
        'ip_address' => $ip_address
    ]);
    $row = $stmt->fetch();

    $attempt_count = 1;
    $first_attempt_at = $now;
    $expires_at = $now->modify('+' . $window_seconds . ' seconds');
    $locked_until = null;

    if ($row && strtotime((string)$row['expires_at']) > $now->getTimestamp()) {
        $attempt_count = (int)$row['attempt_count'] + 1;
    }

    if ($attempt_count >= $max_attempts) {
        $locked_until = $now->modify('+' . $lock_seconds . ' seconds');
        $expires_at = $locked_until;
    }

    if ($row) {
        $stmt = $pdo->prepare("
            UPDATE login_attempts
            SET attempt_count = :attempt_count,
                first_attempt_at = CASE WHEN expires_at <= :now THEN :first_attempt_at ELSE first_attempt_at END,
                last_attempt_at = :last_attempt_at,
                locked_until = :locked_until,
                expires_at = :expires_at
            WHERE id = :id
        ");
        $stmt->execute([
            'attempt_count' => $attempt_count,
            'now' => login_rate_limit_datetime($now),
            'first_attempt_at' => login_rate_limit_datetime($first_attempt_at),
            'last_attempt_at' => login_rate_limit_datetime($now),
            'locked_until' => $locked_until ? login_rate_limit_datetime($locked_until) : null,
            'expires_at' => login_rate_limit_datetime($expires_at),
            'id' => (int)$row['id']
        ]);

        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (
            username,
            ip_address,
            attempt_count,
            first_attempt_at,
            last_attempt_at,
            locked_until,
            expires_at
        )
        VALUES (
            :username,
            :ip_address,
            :attempt_count,
            :first_attempt_at,
            :last_attempt_at,
            :locked_until,
            :expires_at
        )
    ");
    $stmt->execute([
        'username' => $normalized_username,
        'ip_address' => $ip_address,
        'attempt_count' => $attempt_count,
        'first_attempt_at' => login_rate_limit_datetime($first_attempt_at),
        'last_attempt_at' => login_rate_limit_datetime($now),
        'locked_until' => $locked_until ? login_rate_limit_datetime($locked_until) : null,
        'expires_at' => login_rate_limit_datetime($expires_at)
    ]);
}

function login_rate_limit_clear(PDO $pdo, string $username, string $ip_address): void
{
    ensure_login_attempts_table($pdo);

    $stmt = $pdo->prepare("
        DELETE FROM login_attempts
        WHERE username = :username AND ip_address = :ip_address
    ");
    $stmt->execute([
        'username' => login_rate_limit_username($username),
        'ip_address' => $ip_address
    ]);
}
