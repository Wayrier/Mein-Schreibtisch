<?php

// =======================================
// password_reset.php
// Zweck: Passwort-Reset-Tokens erzeugen und einloesen
// =======================================

require_once __DIR__ . '/../config/app.php';

function ensure_password_reset_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            requested_ip VARCHAR(45),
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_user (user_id),
            INDEX idx_password_reset_expiry (expires_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
}

function password_reset_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = is_string($ip) ? trim($ip) : 'unknown';

    return substr($ip !== '' ? $ip : 'unknown', 0, 45);
}

function password_reset_find_user(PDO $pdo, string $identifier): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, username, email
        FROM users
        WHERE username = :identifier OR email = :identifier
        LIMIT 1
    ");
    $stmt->execute(['identifier' => $identifier]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function password_reset_create(PDO $pdo, int $user_id, string $ip_address): array
{
    ensure_password_reset_table($pdo);

    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $expires_at = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE password_reset_tokens
        SET used_at = NOW()
        WHERE user_id = :user_id AND used_at IS NULL
    ");
    $stmt->execute(['user_id' => $user_id]);

    $stmt = $pdo->prepare("
        INSERT INTO password_reset_tokens (user_id, token_hash, requested_ip, expires_at)
        VALUES (:user_id, :token_hash, :requested_ip, :expires_at)
    ");
    $stmt->execute([
        'user_id' => $user_id,
        'token_hash' => $token_hash,
        'requested_ip' => $ip_address,
        'expires_at' => $expires_at
    ]);

    $pdo->commit();

    return [
        'token' => $token,
        'expires_at' => $expires_at
    ];
}

function password_reset_url(string $token): string
{
    $base_url = defined('APP_BASE_URL') ? (string)APP_BASE_URL : 'http://localhost/PHP%20Aufgabe/public';
    $base_url = rtrim($base_url, '/');

    return $base_url . '/reset_password.php?token=' . rawurlencode($token);
}

function password_reset_send_mail(string $email, string $reset_url): bool
{
    $subject = 'Passwort zuruecksetzen - MeinSchreibtisch';
    $body = "Hallo,\n\nueber diesen Link kannst du dein Passwort zuruecksetzen:\n" . $reset_url . "\n\nDer Link ist 30 Minuten gueltig.\n";
    $headers = "From: no-reply@meinschreibtisch.local\r\n";

    if (!function_exists('mail')) {
        return false;
    }

    $sent = @mail($email, $subject, $body, $headers);

    if (!$sent) {
        error_log('Passwort-Reset-Mail konnte nicht gesendet werden.');
    }

    return $sent;
}

function password_reset_lookup(PDO $pdo, string $token): ?array
{
    ensure_password_reset_table($pdo);

    if ($token === '' || strlen($token) > 128) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.username, u.email
        FROM password_reset_tokens pr
        INNER JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = :token_hash
        LIMIT 1
    ");
    $stmt->execute(['token_hash' => hash('sha256', $token)]);
    $reset = $stmt->fetch();

    if (!$reset || !empty($reset['used_at'])) {
        return null;
    }

    $expires_at = strtotime((string)$reset['expires_at']);

    if (!$expires_at || $expires_at < time()) {
        return null;
    }

    return $reset;
}

function password_reset_apply(PDO $pdo, string $token, string $new_password_hash): bool
{
    ensure_password_reset_table($pdo);

    if ($token === '' || strlen($token) > 128) {
        return false;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT pr.id, pr.user_id, pr.expires_at
            FROM password_reset_tokens pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = :token_hash
              AND pr.used_at IS NULL
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $reset = $stmt->fetch();

        $expires_at = $reset ? strtotime((string)$reset['expires_at']) : false;

        if (!$reset || !$expires_at || $expires_at < time()) {
            $pdo->rollBack();

            return false;
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET password_hash = :password_hash
            WHERE id = :user_id
        ");
        $stmt->execute([
            'password_hash' => $new_password_hash,
            'user_id' => (int)$reset['user_id']
        ]);

        $stmt = $pdo->prepare("
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE user_id = :user_id AND used_at IS NULL
        ");
        $stmt->execute(['user_id' => (int)$reset['user_id']]);

        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
