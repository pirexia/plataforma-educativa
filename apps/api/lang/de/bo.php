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
];
