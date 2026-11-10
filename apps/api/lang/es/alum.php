<?php

// Textos del módulo Alum (ADR-056 §3.5, INV-009). Claves a añadir en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad.

return [
    'permissions' => [
        'resources' => [
            'alumno' => 'Alumnado, ficha y fotografía',
            'matricula' => 'Matrícula, grupos y solicitudes de cambio',
        ],
    ],
    'validation' => [
        'required' => 'Este dato es obligatorio.',
        'text_invalid' => 'El texto indicado no es válido: debe tener entre 1 y 255 caracteres, sin caracteres de control.',
        'birth_date_invalid' => 'La fecha de nacimiento debe tener el formato AAAA-MM-DD, ser una fecha real y no futura.',
        'person_exists' => 'Ya existe una persona con ese documento de identidad.',
        'person_already_student' => 'Esta persona ya es alumno del centro.',
        'family_unit_not_found' => 'La unidad familiar indicada no existe.',
        'field_not_editable' => 'Este dato no se puede cambiar por esta vía.',
        'no_changes' => 'No se ha indicado ningún dato que cambiar.',
        'filter_forbidden' => 'No tienes permiso para usar este filtro.',
    ],
    'conflict' => [
        'cannot_restore' => 'No se puede restaurar este alumno: la persona ya no existe o ya tiene otra ficha de alumno.',
    ],
];
