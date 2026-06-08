<?php

// =======================================
// index.php
// Zweck: Startseite - Redirect zum Dashboard oder Login
// =======================================

session_start();

// Wenn eingeloggt, zum Dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Ansonsten zum Login
header("Location: login.php");
exit;

?>