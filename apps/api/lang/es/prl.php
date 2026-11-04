<?php

// Textos del módulo Prl (ADR-056 §3.5, INV-009), docs/modulos/REQ-PRL/api.md §8.4, §8.6. Claves en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad. El `code` de cada error de api.md §8.6 es a la vez
// la clave de su mensaje. Los enumerados (`direction`, `basis`, `verification_method`, `status`...) viajan como código técnico
// (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'entrega_menor' => 'Entrega de menores en la puerta',
            'alerta_entrega' => 'Alertas por persona no autorizada en la entrega',
        ],
    ],

    'movement' => [
        'person_not_authorized' => 'Esa persona no figura en la lista de recogida de este alumno: no se le puede entregar.',
        'verification_requires_document' => 'Esta persona no tiene fotografía registrada: la entrega solo puede verificarse por documento.',
        'reason_required' => 'Un movimiento fuera de horario necesita un motivo (y una nota si el motivo es «otro»).',
    ],

    'alert' => [
        'closed' => 'La alerta ya está cerrada y no se puede modificar.',
    ],

    'idempotency_mismatch' => 'Esa clave de idempotencia ya se usó con un contenido distinto.',

    'protocol' => [
        'minimal' => 'No entregues al alumno. Retenlo en el centro, avisa a dirección y llama al tutor principal con el teléfono que figura en esta alerta. Anota después la hora de la llamada y la resolución.',
    ],

    'errors' => [
        'busy' => 'La puerta está ocupada registrando otro cambio de esta persona o de este alumno. Inténtalo de nuevo en un segundo.',
        'read_rate_limited' => 'Has alcanzado el límite de consultas de alertas y movimientos de la puerta. Inténtalo de nuevo en unos :minutes minutos.',
    ],

    'validation' => [
        'required' => 'Este campo es obligatorio.',
        'reference_invalid' => 'El identificador indicado no es válido.',
        'enum_invalid' => 'El valor indicado no es válido.',
        'text_invalid' => 'El texto no es válido o supera los :max caracteres.',
        'boolean_invalid' => 'El valor debe ser verdadero o falso.',
        'datetime_invalid' => 'La fecha y hora no son válidas (formato ISO 8601).',
        'student_not_found' => 'El alumno indicado no existe.',
        'user_not_found' => 'El usuario indicado no existe o no está activo.',
        'reference_not_found' => 'El movimiento que se corrige no existe para este alumno.',
        'entry_without_pickup' => 'Una entrada no lleva persona que recoge ni método de verificación.',
        'off_schedule_reason_unexpected' => 'Solo un movimiento fuera de horario lleva motivo.',
        'cursor_invalid' => 'El cursor no es válido para esta consulta.',
        'idempotency_key_invalid' => 'La clave de idempotencia debe tener entre 1 y 128 caracteres visibles.',
        'field_not_editable' => 'Este dato de la alerta no se puede cambiar.',
        'notes_without_resolution' => 'Las notas de resolución solo se indican junto con la resolución.',
        'empty_body' => 'Indica al menos un dato: la llamada al tutor principal o la resolución.',
        'contact_time_out_of_range' => 'La hora de la llamada no puede ser futura ni anterior a la apertura de la alerta.',
    ],
];
