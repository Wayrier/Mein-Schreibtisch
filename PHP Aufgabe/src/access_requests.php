<?php

// =======================================
// access_requests.php
// Zweck: Konto- und Passwort-Anfragen verwalten
// =======================================

function ensure_access_requests_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS access_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_type ENUM('account', 'password') NOT NULL,
            full_name VARCHAR(150),
            username VARCHAR(50),
            email VARCHAR(150),
            message TEXT,
            status ENUM('open', 'done') NOT NULL DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function create_access_request(PDO $pdo, string $type, ?string $full_name, ?string $username, ?string $email, ?string $message): void
{
    ensure_access_requests_table($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO access_requests (request_type, full_name, username, email, message)
        VALUES (:request_type, :full_name, :username, :email, :message)
    ");

    $stmt->execute([
        'request_type' => $type,
        'full_name' => $full_name,
        'username' => $username,
        'email' => $email,
        'message' => $message
    ]);
}

function fetch_open_access_requests(PDO $pdo): array
{
    ensure_access_requests_table($pdo);

    $stmt = $pdo->query("
        SELECT id, request_type, full_name, username, email, message, created_at
        FROM access_requests
        WHERE status = 'open'
        ORDER BY created_at DESC, id DESC
    ");

    return $stmt->fetchAll();
}

function mark_access_request_done(PDO $pdo, int $id): void
{
    ensure_access_requests_table($pdo);

    $stmt = $pdo->prepare("
        UPDATE access_requests
        SET status = 'done'
        WHERE id = :id
    ");

    $stmt->execute(['id' => $id]);
}
