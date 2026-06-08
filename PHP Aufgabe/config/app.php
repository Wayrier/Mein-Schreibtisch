<?php

// =======================================
// app.php
// Zweck: Allgemeine Anwendungskonfiguration
// =======================================

// Feste oeffentliche Basis-URL fuer Links, die ausserhalb der aktuellen
// Browser-Anfrage funktionieren muessen, z. B. Passwort-Reset-E-Mails.
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', 'http://localhost/PHP%20Aufgabe/public');
}

// Pro Benutzer erlaubter Speicher fuer Dateien aus Notizen/Terminen.
if (!defined('APP_STORAGE_QUOTA_BYTES')) {
    define('APP_STORAGE_QUOTA_BYTES', 10 * 1024 * 1024 * 1024);
}
