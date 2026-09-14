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
    'tenant' => [
        'slug_taken' => 'Es existiert bereits eine Schule mit dieser Kennung.',
        'slug_reserved' => 'Diese Kennung ist von der Plattform reserviert.',
        'invalid_transition' => 'Dieser Statuswechsel ist nicht zulässig.',
        'name_mismatch' => 'Der eingegebene Name stimmt nicht genau mit dem Namen der Schule überein.',
        'clone_source_invalid' => 'Eine Schule in diesem Status kann nicht geklont werden.',
    ],
    'dual_auth' => [
        'same_actor' => 'Wer eine Anfrage genehmigt, kann nicht dieselbe Person sein, die sie gestellt hat.',
        'expired' => 'Diese Doppelautorisierungsanfrage ist abgelaufen.',
        'already_resolved' => 'Diese Doppelautorisierungsanfrage wurde bereits entschieden.',
        'payload_mismatch' => 'Dieser Vorgang kann nicht mehr ausgeführt werden: Die Bedingungen haben sich seit der Anfrage geändert.',
    ],
    'tenant_lifecycle' => [
        'reason' => [
            'provisioned' => 'Erstbereitstellung der Schule abgeschlossen.',
        ],
    ],
    'module_subscription' => [
        'reason' => [
            'cloned' => 'Beim Klonen der Ursprungsschule kopiert.',
        ],
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
