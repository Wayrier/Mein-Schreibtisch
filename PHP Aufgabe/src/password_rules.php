<?php

// =======================================
// password_rules.php
// Zweck: Gemeinsame Passwort-Regeln fuer Kontoanlage und Passwortwechsel
// =======================================

function password_policy_error(string $password, string $label = 'Passwort'): ?string
{
    if (strlen($password) < password_policy_min_length()) {
        return $label . ' muss mindestens ' . password_policy_min_length() . ' Zeichen lang sein.';
    }

    if (preg_match('/[a-z]/', $password) !== 1) {
        return $label . ' muss mindestens einen Kleinbuchstaben enthalten.';
    }

    if (preg_match('/[A-Z]/', $password) !== 1) {
        return $label . ' muss mindestens einen Grossbuchstaben enthalten.';
    }

    if (preg_match('/[0-9]/', $password) !== 1) {
        return $label . ' muss mindestens eine Zahl enthalten.';
    }

    if (preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
        return $label . ' muss mindestens ein Sonderzeichen enthalten.';
    }

    return null;
}

function password_policy_min_length(): int
{
    return 10;
}

function password_policy_pattern(): string
{
    return '(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{10,}';
}

function password_policy_hint(): string
{
    return 'Mindestens 10 Zeichen, Gross- und Kleinbuchstaben, eine Zahl und ein Sonderzeichen.';
}
