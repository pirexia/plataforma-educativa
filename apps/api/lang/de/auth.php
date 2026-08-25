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
    ],

];
