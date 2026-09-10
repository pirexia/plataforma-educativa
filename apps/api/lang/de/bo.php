<?php

// REQ-BO/api.md §5 (1.6). Siehe lang/es/bo.php für den maßgeblichen Kommentar.

return [
    'mfa' => [
        'invalid_code' => 'Der eingegebene Code ist ungültig.',
    ],
    'admin' => [
        'self_modification' => 'Du kannst diesen Vorgang nicht für dein eigenes Konto ausführen.',
        'last_superadministrator' => 'Es muss immer mindestens ein aktiver Superadministrator vorhanden sein.',
    ],
    'validation' => [
        'cursor_invalid' => 'Der Paginierungs-Cursor ist ungültig.',
    ],
    // Issue #173. Einladungs-E-Mail für einen Plattform-Administrator.
    'mail' => [
        'invitation' => [
            'subject' => 'Aktiviere dein Konto als Plattform-Administrator',
            'greeting' => 'Hallo, :name.',
            'body' => 'Du wurdest als Administrator des Backoffice der Bildungsverwaltungsplattform eingeladen.',
            'cta' => 'Mein Konto aktivieren',
            'expires' => 'Dieser Link läuft in :days Tagen ab. Falls du dies nicht erwartet hast, kannst du die Nachricht ignorieren.',
        ],
    ],
];
