<?php

// Textos del módulo Alum (ADR-056 §3.5, INV-009). Claves a añadir en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad.

return [
    'permissions' => [
        'resources' => [
            'alumno' => 'Students, record and photograph',
        ],
    ],
    'validation' => [
        'required' => 'This field is required.',
        'text_invalid' => 'The text provided is not valid: it must be between 1 and 255 characters long, with no control characters.',
        'birth_date_invalid' => 'The date of birth must use the YYYY-MM-DD format, be a real date and not be in the future.',
        'person_exists' => 'A person with that identity document already exists.',
        'person_already_student' => 'This person is already a student of the school.',
        'family_unit_not_found' => 'The family unit provided does not exist.',
        'field_not_editable' => 'This field cannot be changed this way.',
        'no_changes' => 'No data to change was provided.',
        'filter_forbidden' => 'You do not have permission to use this filter.',
    ],
    'conflict' => [
        'cannot_restore' => 'This student cannot be restored: the person no longer exists or already has another student record.',
    ],
];
