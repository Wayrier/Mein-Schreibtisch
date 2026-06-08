# MeinSchreibtisch

MeinSchreibtisch ist eine lokale PHP/XAMPP-Anwendung fuer Notizen, Termine, Dateien, Konvertierungen und Backup/Restore.

## Voraussetzungen

- XAMPP mit Apache, MySQL und PHP
- Projektordner unter `C:\xampp\htdocs\PHP Aufgabe`
- Browser-URL: `http://localhost/PHP%20Aufgabe/public/`

Optional fuer Debugging: Xdebug in der verwendeten XAMPP-PHP-Version aktivieren.
Pruefen geht mit `C:\xampp\php\php.exe -m | findstr xdebug`.

## Erstinstallation

1. XAMPP starten: Apache und MySQL aktivieren.
2. `http://localhost/PHP%20Aufgabe/public/setup.php` oeffnen.
3. Admin-Benutzer anlegen.
4. Danach ueber `http://localhost/PHP%20Aufgabe/public/login.php` anmelden.

Das Setup nutzt die Zugangsdaten aus `config/database.php`, legt die Datenbankstruktur aus `database/schema.sql` an und sperrt sich automatisch, sobald ein Benutzer existiert.

## Wichtige Funktionen

- Login mit Passwort-Hashing
- Persistentes Login-Rate-Limit pro Benutzername/IP
- Passwort-Reset per zeitlich begrenztem Token-Link
- Konto-Anfragen direkt vom Login aus
- Adminbereich fuer Benutzer erstellen, bearbeiten und loeschen
- Admins sehen offene Konto-Anfragen im Usermanagement
- Admins koennen Benutzerpasswoerter im Bearbeiten-Dialog neu setzen
- Standardbenutzer ohne Zugriff auf die Benutzerverwaltung
- Notizen und Termine mit Erstellen, Bearbeiten, Loeschen und AJAX-Aktualisierung
- Gantt-Diagramm fuer Termine mit Start- und Enddatum
- Datei-Uploads fuer Notizen und Termine
- Konvertierung von Notizen zu Terminen und Terminen zu Notizen
- Backup-Export und Backup-Import mit Duplikatpruefung
- Wetter-/Uhrzeit-Anzeige und grafische Oberflaeche

## Abgabe-Check

Vor der Abgabe:

1. PHP-Syntax pruefen:
   `C:\xampp\php\php.exe -l pfad\zur\datei.php`
2. Login mit Admin und Standardbenutzer testen.
3. Notiz mit Datei erstellen und loeschen.
4. Termin mit Datei erstellen und loeschen.
5. Notiz zu Termin und Termin zu Notiz konvertieren.
6. Backup exportieren und auf einem leeren System importieren.
7. Pruefen, dass keine doppelten Daten beim erneuten Import entstehen.
