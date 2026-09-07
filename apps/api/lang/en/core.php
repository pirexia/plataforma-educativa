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
        'mfa_allowed_methods_requires_totp' => 'The TOTP method cannot be disabled: it is the only one that does not depend on an external provider.',
        'mfa_allowed_methods_sms_unavailable' => 'The SMS method is not available yet: no provider is configured.',
        'role_patch_field_not_allowed' => 'This endpoint only accepts the "mfa_required" field.',
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
        'cursor_invalid' => 'The pagination cursor is not valid for this query.',
        'export_range_too_large' => 'The requested range exceeds the allowed row limit; narrow it and try again.',
        'pdf_export_not_available' => 'PDF export is not available yet; use CSV.',
        'export_not_ready' => 'The export is still being generated; try again in a few minutes.',
        'export_failed' => 'The generation of this export has failed.',
        'import_not_validated' => 'The batch must be validated before it can be executed.',
        'import_already_executed' => 'This batch has already been executed or is being executed and cannot be discarded.',

        'role_code_taken' => 'A living role in this school already uses this code.',
        'role_code_immutable' => 'A role\'s code cannot be changed: it is its stable reference.',
        'role_name_system' => 'A predefined role does not accept a literal name: use its translation.',
        'clone_source_not_found' => 'The given source role does not exist in this school.',
        'clone_requires_special_data_access' => 'You cannot clone this role: it grants access to special category data and you cannot activate it.',
        'clone_and_permissions_exclusive' => 'You cannot provide both "clone_from" and "permissions".',
        'scope_not_applicable' => 'The scope ":scope" cannot be granted for this permission.',
        'scope_resolver_missing' => 'The scope ":scope" cannot be granted yet: its resolver is not registered.',
        'permission_not_found' => 'The permission code ":code" does not exist in the catalog.',
        'permission_retired' => 'The permission code ":code" is no longer available.',
        'permission_duplicated' => 'The permission code ":code" appears more than once.',
        'role_is_system' => 'A role from the school\'s provisioning cannot be deleted.',
        'role_has_assignments' => 'This role has :users_count assigned user(s) and cannot be deleted.',
    ],

    'authorization' => [
        'cannot_grant_unheld_permission' => 'You cannot grant permission ":code" with scope ":scope": you do not have it yourself.',
        'special_data_access_not_held' => 'You cannot activate access to special category data: you do not have it yourself.',
    ],

    'inert_reasons' => [
        'inerte_permiso_retirado' => 'No module declares this permission anymore.',
        'inerte_modulo' => 'This permission\'s module is not enabled for this school.',
        'inerte_datos_especiales' => 'The grant comes from a role without access to special category data.',
        'inerte_sin_resolutor' => 'This grant\'s scope does not have a resolver yet.',
    ],

    'idempotency' => [
        'missing' => 'The Idempotency-Key header is missing and required for this operation.',
        'malformed' => 'The Idempotency-Key header must be a ULID.',
        'body_mismatch' => 'The same idempotency key was used with a different body.',
        'in_progress' => 'The same idempotency key is still being processed.',
    ],

    'import' => [
        'campo_obligatorio_vacio' => 'The ":column" column is required and is empty.',
        'formato_invalido' => 'The value of the ":column" column has an invalid format.',
        'duplicado_en_fichero' => 'The value of the ":column" column appears more than once in the file.',
        'duplicado_en_base_de_datos' => 'The value of the ":column" column already belongs to another person or user of the school.',
        'idioma_no_activo' => 'The given language is not active for this school.',
        'rol_no_encontrado' => 'One of the given roles does not exist in this school.',
    ],
];
