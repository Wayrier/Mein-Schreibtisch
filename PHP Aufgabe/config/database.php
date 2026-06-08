<?php

// ===============================
//  Datenbank-Verbindungsdaten
// ===============================

// Server-Adresse (bei XAMPP immer localhost)
$host = 'localhost';

// Name deiner Datenbank (muss in phpMyAdmin existieren)
$db = 'mein_schreibtisch';

// Benutzername (Standard bei XAMPP: root)
$user = 'root';

// Passwort (bei XAMPP meistens leer)
$pass = '';

// Zeichensatz (sehr wichtig für Umlaute & Sicherheit)
$charset = 'utf8mb4';


// ===============================
//  DSN (Data Source Name)
// ===============================
// Beschreibt die Verbindung zur Datenbank:
// Format: mysql:host=HOST;dbname=DBNAME;charset=CHARSET

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";


// ===============================
//  PDO Optionen
// ===============================

$options = [

    // Fehler werden als Exception geworfen (sehr wichtig für Debugging)
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

    // Ergebnisse werden als assoziatives Array zurückgegeben:
    // Beispiel: $user['username'] statt $user[0]
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Setup-Seiten brauchen die Zugangsdaten, duerfen aber noch keine Verbindung
// zur Datenbank erzwingen, weil diese beim Erststart eventuell noch fehlt.
if (defined('MEIN_SCHREIBTISCH_SKIP_DB_CONNECT') && MEIN_SCHREIBTISCH_SKIP_DB_CONNECT) {
    return;
}


// ===============================
//  Verbindung herstellen
// ===============================

try {
    // Neue PDO-Verbindung erstellen
    $pdo = new PDO($dsn, $user, $pass, $options);

} catch (PDOException $e) {

    // Falls Fehler → Script stoppen
    // (In Produktion NICHT direkt anzeigen → Sicherheitsrisiko!)
    error_log("Datenbankverbindungsfehler: " . $e->getMessage());
    die("Datenbankverbindung konnte nicht hergestellt werden. Bitte versuchen Sie es später erneut.");
}
