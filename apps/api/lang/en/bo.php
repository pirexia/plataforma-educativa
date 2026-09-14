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
    'tenant' => [
        'slug_taken' => 'A school already exists with that identifier.',
        'slug_reserved' => 'That identifier is reserved by the platform.',
        'invalid_transition' => 'That status transition is not allowed.',
        'name_mismatch' => 'The name you entered does not exactly match the school\'s name.',
        'clone_source_invalid' => 'A school in this state cannot be cloned.',
    ],
    'dual_auth' => [
        'same_actor' => 'Whoever approves a request cannot be the one who requested it.',
        'expired' => 'This dual-authorization request has expired.',
        'already_resolved' => 'This dual-authorization request has already been resolved.',
        'payload_mismatch' => 'This operation can no longer be executed: its conditions changed since it was requested.',
    ],
    'tenant_lifecycle' => [
        'reason' => [
            'provisioned' => 'Initial provisioning of the school completed.',
        ],
    ],
    'module_subscription' => [
        'reason' => [
            'cloned' => 'Copied when cloning the source school.',
        ],
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
