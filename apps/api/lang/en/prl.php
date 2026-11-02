<?php

// Textos del módulo Prl (ADR-056 §3.5, INV-009), docs/modulos/REQ-PRL/api.md §8.4, §8.6. Claves en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad. El `code` de cada error de api.md §8.6 es a la vez
// la clave de su mensaje. Los enumerados (`direction`, `basis`, `verification_method`, `status`...) viajan como código técnico
// (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'entrega_menor' => 'Release of minors at the door',
            'alerta_entrega' => 'Alerts for an unauthorised person at release',
        ],
    ],

    'movement' => [
        'person_not_authorized' => 'This person is not on this student\'s pickup list: the student cannot be released to them.',
        'verification_requires_document' => 'This person has no photograph on record: the release can only be verified by document.',
        'reason_required' => 'An off-schedule movement needs a reason (and a note if the reason is "other").',
    ],

    'alert' => [
        'closed' => 'The alert is already closed and cannot be changed.',
    ],

    'idempotency_mismatch' => 'That idempotency key was already used with different content.',

    'protocol' => [
        'minimal' => 'No entregues al alumno. Retenlo en el centro, avisa a dirección y llama al tutor principal con el teléfono que figura en esta alerta. Anota después la hora de la llamada y la resolución.',
    ],

    'errors' => [
        'read_rate_limited' => 'Has alcanzado el límite de consultas de alertas y movimientos de la puerta. Inténtalo de nuevo en unos :minutes minutos.',
    ],

    'validation' => [
        'required' => 'This field is required.',
        'reference_invalid' => 'The identifier provided is not valid.',
        'enum_invalid' => 'The value provided is not valid.',
        'text_invalid' => 'The text is not valid or exceeds :max characters.',
        'boolean_invalid' => 'The value must be true or false.',
        'datetime_invalid' => 'The date and time are not valid (ISO 8601 format).',
        'student_not_found' => 'The student provided does not exist.',
        'user_not_found' => 'The user provided does not exist or is not active.',
        'reference_not_found' => 'The movement being corrected does not exist for this student.',
        'entry_without_pickup' => 'An entry must not include a pickup person or a verification method.',
        'off_schedule_reason_unexpected' => 'Only an off-schedule movement carries a reason.',
        'cursor_invalid' => 'The cursor is not valid for this query.',
        'idempotency_key_invalid' => 'The idempotency key must have between 1 and 128 visible characters.',
        'field_not_editable' => 'This data of the alert cannot be changed.',
        'notes_without_resolution' => 'Resolution notes can only be given together with the resolution.',
        'empty_body' => 'Provide at least one item: the call to the main guardian or the resolution.',
        'contact_time_out_of_range' => 'The time of the call cannot be in the future or before the alert was opened.',
    ],
];
