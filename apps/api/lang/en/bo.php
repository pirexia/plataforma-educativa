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
];
