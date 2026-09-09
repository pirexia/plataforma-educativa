<?php

// REQ-BO/api.md §5 (1.6). See lang/es/bo.php for the authoritative comment.

return [
    'mfa' => [
        'invalid_code' => 'The code you entered is not valid.',
    ],
    'admin' => [
        'self_modification' => 'You cannot perform this operation on your own account.',
        'last_superadministrator' => 'At least one live, active superadministrator must always remain.',
    ],
    'validation' => [
        'cursor_invalid' => 'The pagination cursor is not valid.',
    ],
    // Issue #173. Platform admin invitation email.
    'mail' => [
        'invitation' => [
            'subject' => 'Activate your platform administrator account',
            'greeting' => 'Hello, :name.',
            'body' => 'You have been invited as an administrator of the school management platform backoffice.',
            'cta' => 'Activate my account',
            'expires' => 'This link expires in :days days. If you were not expecting this, you can ignore it.',
        ],
    ],
];
