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
        'student_not_found' => 'El alumno indicado no existe.',
        'parent_not_found' => 'El curso, el grupo o la estructura indicados no existen.',
        'academic_year_not_found' => 'El curso académico indicado no existe.',
        'course_not_in_academic_year' => 'El curso indicado no pertenece al curso académico.',
        'group_not_in_course' => 'El grupo indicado no pertenece al curso.',
        'already_enrolled' => 'El alumno ya tiene una matrícula activa en este curso académico.',
        'date_outside_academic_year' => 'La fecha debe estar dentro del curso académico.',
        'date_invalid' => 'La fecha debe tener el formato AAAA-MM-DD y ser una fecha real.',
        'group_change_requires_request' => 'Cambiar el grupo de una matrícula que ya lo tiene exige una solicitud de cambio de grupo.',
        'immutable' => 'Este dato de la matrícula no se puede cambiar.',
        'withdrawal_reason_invalid' => 'El motivo de la baja debe ser traslado, baja voluntaria u otro.',
        'withdrawn_before_enrolled' => 'La fecha de baja no puede ser anterior a la fecha de matrícula.',
    ],
    'conflict' => [
        'cannot_restore' => 'No se puede restaurar este alumno: la persona ya no existe o ya tiene otra ficha de alumno.',
        'already_withdrawn' => 'La matrícula ya está dada de baja.',
        'has_active_enrollments' => 'No se puede dar de baja a un alumno con matrículas activas.',
    ],
];
