<?php

// See lang/es/core.php for the authoritative comment.

return [
    'mail' => [
        'invitation' => [
            'subject' => 'Activate your account at :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'You have been invited to join :tenant on the school management platform.',
            'cta' => 'Activate my account',
            'expires' => 'This link expires in :days days. If you were not expecting this, you can ignore it.',
        ],
    ],

    'validation' => [
        'locale_not_active' => 'The language ":locale" is not active for this school.',
        'default_locale_not_active' => 'The default language must be among the active languages.',
        'active_locales_empty' => 'At least one language must be active.',
        'contrast_insufficient' => 'The palette contrast (:ratio:1) does not reach the required minimum (:required:1, WCAG 2.2 AA).',
        'document_number_invalid' => 'The document number is not valid for the given type.',
        'document_duplicate' => 'A living person with the same document type and number already exists in this school.',
        'email_duplicate' => 'A living user with this access email already exists in this school.',
        'role_not_found' => 'One of the given roles does not exist in this school.',
        'role_permission_exceeds_own' => 'You cannot assign a role that grants permissions you do not have yourself.',
        'file_type_mismatch' => 'The file\'s real type does not match the declared one.',
        'svg_unrepairable' => 'The SVG cannot be safely sanitized.',
        'empty_string_not_allowed' => 'To clear this field, send null instead of an empty string.',
        'query_boolean_invalid' => 'The value must be "true" or "false".',
        'cannot_modify_self' => 'You cannot perform this action on your own account.',
        'last_school_administrator' => 'There must always be at least one active School Administrator.',
        'invitation_requires_pending_user' => 'Only a user in pending status can be invited.',
        'invitation_already_accepted' => 'This invitation has already been accepted and cannot be revoked.',
        'enabled_not_editable' => 'The module\'s enabled state cannot be changed through this endpoint.',
        'import_unknown_header' => 'The file header does not match the expected format.',
    ],

    'idempotency' => [
        'missing' => 'The Idempotency-Key header is missing and required for this operation.',
        'malformed' => 'The Idempotency-Key header must be a ULID.',
        'body_mismatch' => 'The same idempotency key was used with a different body.',
        'in_progress' => 'The same idempotency key is still being processed.',
    ],
];
