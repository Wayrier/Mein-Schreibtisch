<?php

// =======================================
// process_access_request.php
// Zweck: Admin markiert Zugriffsanfragen als erledigt
// =======================================

require_once '../src/admin_check.php';
require_once '../config/database.php';
require_once '../src/access_requests.php';
require_once '../src/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('users.php');
}

app_require_csrf('users.php');

$id = $_POST['id'] ?? null;
$id = is_scalar($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;

if ($id === false || $id <= 0) {
    app_redirect_error('users.php', 'Keine oder ungueltige Anfrage-ID angegeben.');
}

try {
    mark_access_request_done($pdo, (int)$id);
} catch (Throwable $e) {
    error_log("Anfrage konnte nicht erledigt werden: " . $e->getMessage());
    app_redirect_error('users.php', 'Anfrage konnte nicht bearbeitet werden.');
}

app_redirect_success('users.php', 'Anfrage wurde als erledigt markiert.');
