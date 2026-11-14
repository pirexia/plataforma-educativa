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
    // REQ-BO-002 (1.6c): module contracting error catalog.
    'module' => [
        'essential' => 'Module ":module_code" is essential: it cannot be contracted or decontracted.',
        'retired' => 'Module ":module_code" no longer exists in the catalog: it cannot be contracted.',
        'reason_required' => 'The reason is required and cannot be empty.',
        'tenant_state_invalid' => 'The school is in a state that does not allow module writes.',
        'missing_dependencies' => 'Dependencies still need to be contracted: :modules. Confirm the cascade to continue.',
        'dependent_modules' => 'Other modules depend on this one: :modules. Confirm the cascade to continue.',
    ],
    // REQ-BO-004 (1.6d): health / job retry error catalog.
    'job' => [
        'reason_required' => 'The reason is required and cannot be empty.',
        'tenant_state_invalid' => 'The school is in a state that does not allow retrying jobs.',
        'reason' => [
            'retried_via_console' => 'Re-queued via bo:retry-provisioning.',
        ],
    ],
    // REQ-BO-006 (1.6d): platform metrics error catalog.
    'metrics' => [
        'invalid_period' => 'The start of the period cannot be after its end.',
    ],
    // REQ-BO-005 points 1-2 (1.6e): feature flag engine error catalog.
    'flag' => [
        'retired' => 'This flag has been retired and no longer accepts writes.',
        'invalid_rule' => 'The rule is not coherent: check that it carries the field matching its axis and, if it names a school, that it exists.',
        'duplicate_rule' => 'A rule of this type already exists for the same target in the submitted set.',
        'tenant_state_invalid' => 'The school is in a state that does not allow this operation.',
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
