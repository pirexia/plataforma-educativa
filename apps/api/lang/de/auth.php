<?php

// REQ-AUTH, Schritt 1.2. Siehe lang/es/auth.php für den maßgeblichen Kommentar.

return [

    'validation' => [
        'password' => [
            'min_length' => 'Das Passwort muss mindestens :min Zeichen lang sein.',
            'max_bytes' => 'Das Passwort ist zu lang.',
            'uppercase' => 'Das Passwort muss mindestens einen Großbuchstaben enthalten.',
            'lowercase' => 'Das Passwort muss mindestens einen Kleinbuchstaben enthalten.',
            'digit' => 'Das Passwort muss mindestens eine Zahl enthalten.',
            'symbol' => 'Das Passwort muss mindestens ein Sonderzeichen enthalten.',
            'same_as_current' => 'Das neue Passwort darf nicht mit dem aktuellen übereinstimmen.',
        ],
        'current_password_incorrect' => 'Das aktuelle Passwort ist falsch.',
        'lockout_already_unlocked' => 'Diese Sperre wurde bereits aufgehoben.',
        'session_already_closed' => 'Diese Sitzung ist bereits geschlossen.',
        'mfa_factor_already_confirmed' => 'Du hast bereits einen bestätigten Faktor für diese Methode.',
        'mfa_method_not_available' => 'Diese Verifizierungsmethode ist an dieser Schule nicht verfügbar.',
        'mfa_code_invalid' => 'Der Code ist nicht korrekt.',
        'mfa_factor_required_by_role' => 'Du kannst deinen letzten Faktor nicht deaktivieren: eine deiner Rollen erfordert die zweistufige Verifizierung.',
        'mfa_exemption_already_live' => 'Dieser Benutzer hat bereits eine gültige MFA-Ausnahme.',
        'mfa_exemption_self' => 'Du kannst dir selbst keine MFA-Ausnahme gewähren.',
        'mfa_reset_self' => 'Du kannst dein eigenes MFA nicht zurücksetzen.',
    ],

    'mail' => [
        'account_locked' => [
            'subject' => 'Dein Konto bei :tenant wurde vorübergehend gesperrt',
            'greeting' => 'Hallo :name.',
            'body' => 'Es wurden :count fehlgeschlagene Anmeldeversuche für dein Konto bei :tenant festgestellt, es wurde aus Sicherheitsgründen vorübergehend gesperrt.',
            'auto_unlock' => 'Die Sperre wird automatisch am :time aufgehoben, wenn du nichts unternimmst.',
            'cta' => 'Konto jetzt entsperren',
        ],
        'password_reset' => [
            'subject' => 'Setze dein Passwort bei :tenant zurück',
            'greeting' => 'Hallo :name.',
            'body' => 'Du hast eine Zurücksetzung deines Passworts bei :tenant angefordert.',
            'cta' => 'Passwort zurücksetzen',
            'expires' => 'Dieser Link läuft in :minutes Minuten ab.',
            'ignore' => 'Wenn du das nicht warst, kannst du diese Nachricht ignorieren: dein aktuelles Passwort bleibt gültig.',
        ],
        'password_changed' => [
            'subject' => 'Dein Passwort bei :tenant wurde geändert',
            'greeting' => 'Hallo :name.',
            'body' => 'Dein Passwort bei :tenant wurde soeben geändert.',
            'warning' => 'Wenn du das nicht warst, wende dich umgehend an die Verwaltung deiner Schule.',
        ],
        'mfa_factor_activated' => [
            'subject' => 'Die zweistufige Verifizierung wurde bei :tenant aktiviert',
            'greeting' => 'Hallo :name.',
            'body' => 'In deinem Konto bei :tenant wurde soeben ein neuer Faktor für die zweistufige Verifizierung aktiviert.',
            'warning' => 'Wenn du das nicht warst, wende dich umgehend an die Verwaltung deiner Schule.',
        ],
        'mfa_factor_removed' => [
            'subject' => 'Die zweistufige Verifizierung wurde bei :tenant deaktiviert',
            'greeting' => 'Hallo :name.',
            'body_by_self' => 'In deinem Konto bei :tenant wurde soeben ein Faktor der zweistufigen Verifizierung entfernt.',
            'body_by_admin' => 'Ein Administrator bei :tenant hat die zweistufige Verifizierung deines Kontos zurückgesetzt: deine bisherigen Faktoren und Wiederherstellungscodes sind nicht mehr gültig.',
            'warning' => 'Wenn du das nicht warst, wende dich umgehend an die Verwaltung deiner Schule.',
        ],
        'recovery_code_used' => [
            'subject' => 'Bei :tenant wurde ein Wiederherstellungscode verwendet',
            'greeting' => 'Hallo :name.',
            'body' => 'Einer deiner Wiederherstellungscodes wurde verwendet, um dich bei deinem Konto bei :tenant anzumelden, anstelle deines üblichen zweiten Faktors.',
            'warning' => 'Wenn du das nicht warst, wende dich umgehend an die Verwaltung deiner Schule und überprüfe deine aktiven Sitzungen.',
        ],
        'mfa_enrollment_code' => [
            'subject' => 'Code zur Aktivierung der zweistufigen Verifizierung bei :tenant',
            'greeting' => 'Hallo :name.',
            'body' => 'Du aktivierst gerade E-Mail als zweistufige Verifizierung für dein Konto bei :tenant. Verwende diesen Code, um es zu bestätigen.',
            'code' => 'Dein Code: :code',
            'expires' => 'Dieser Code läuft in :minutes Minuten ab.',
        ],
        'mfa_challenge_code' => [
            'subject' => 'Dein Anmeldecode für :tenant',
            'greeting' => 'Hallo :name.',
            'body' => 'Du meldest dich bei :tenant an und benötigst diesen Code, um die zweistufige Verifizierung abzuschließen.',
            'code' => 'Dein Code: :code',
            'expires' => 'Dieser Code läuft in :minutes Minuten ab.',
            'warning' => 'Wenn du dich nicht angemeldet hast, ändere dein Passwort so schnell wie möglich.',
        ],
        'new_device_login' => [
            'subject' => 'Neue Anmeldung bei deinem Konto auf :tenant',
            'greeting' => 'Hallo :name.',
            'body' => 'Bei deinem Konto auf :tenant hat sich am :time ein Gerät angemeldet, das wir vorher noch nicht gesehen haben.',
            'detail' => 'Details zur Anmeldung: :client, von der IP-Adresse :ip.',
            'location_line' => 'Ungefährer Standort: :location.',
            'what_to_do' => 'Wenn du das warst, musst du nichts weiter tun. Wenn du diese Anmeldung nicht erkennst, überprüfe deine aktiven Sitzungen und ändere dein Passwort so schnell wie möglich.',
            'cta' => 'Meine aktiven Sitzungen überprüfen',
            'unknown_client' => 'ein unbekanntes Gerät',
        ],
    ],

];
