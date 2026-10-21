<?php

// REQ-CURSO (paso 1.10), api.md §4, §5, permisos.md §8. INV-009: ningún
// literal de error ni de estado vive fuera de aquí. Los `code` de error de
// api.md §5 son a la vez la clave de su mensaje (`curso.validation.code_taken`…).
// El código del curso es contenido del centro y no se traduce.

return [
    'status' => [
        'planificacion' => 'Planificación',
        'activo' => 'Activo',
        'cerrado' => 'Cerrado',
        'archivado' => 'Archivado',
    ],

    'permissions' => [
        'resources' => [
            'curso_academico' => 'Cursos académicos',
            'estado_curso_academico' => 'Estado de los cursos académicos',
            'curso_historico' => 'Histórico de cursos cerrados',
            'reapertura_curso_academico' => 'Reapertura de cursos académicos',
            'apertura_curso_academico' => 'Apertura del curso siguiente',
        ],
    ],

    'validation' => [
        'code_required' => 'El código del curso es obligatorio.',
        'code_taken' => 'Ya existe un curso con ese código en el centro.',
        'ends_before_start' => 'La fecha de fin debe ser posterior a la de inicio.',
        'dates_overlap' => 'Las fechas se solapan con las del curso «:code».',
        'status_not_editable' => 'El estado de un curso no se puede indicar aquí: usa las acciones de activar y cerrar.',
        'reason_required' => 'El motivo de la reapertura es obligatorio.',
        'rollover_target_required' => 'Indica el curso académico de destino.',
        'rollover_target_not_found' => 'El curso académico de destino no existe.',
        'rollover_source_required' => 'Indica el curso académico de origen.',
        'rollover_source_not_found' => 'El curso académico de origen no existe.',
        'rollover_source_invalid' => 'El curso de origen debe estar activo o cerrado y empezar antes que el de destino.',
        'rollover_elements_required' => 'Selecciona al menos un elemento que copiar.',
        'rollover_element_unknown' => 'No se puede copiar «:element».',
        'rollover_element_requires' => 'Para copiar «:element» hay que copiar también «:requires».',
        'rollover_element_not_selectable' => '«:element» no se selecciona por separado: se copia con la asignación docente.',
        'rollover_teaching_assignments_unavailable' => 'No se puede copiar la asignación docente: ningún módulo contratado la ofrece.',
    ],

    'conflict' => [
        'planning_exists' => 'Ya hay un curso en planificación («:code»). Edítalo o actívalo antes de crear otro.',
        'active_exists' => 'Ya hay un curso activo («:code»). Ciérralo antes de activar otro.',
        'invalid_transition' => 'No se puede pasar un curso de «:from» a «:to».',
        'not_editable' => 'Solo se puede editar un curso en planificación.',
        'closure_checks_failed' => 'El curso no se puede cerrar: hay validaciones de cierre que no se cumplen.',
        'date_change_checks_failed' => 'Las nuevas fechas del curso académico dejan fuera datos que dependen de ellas.',
        'reopen_not_latest' => 'Solo se puede reabrir el curso cerrado más reciente: el curso «:code» es posterior.',
        'reopen_checks_failed' => 'El curso no se puede reabrir: hay validaciones de reapertura que no se cumplen.',
        'rollover_target_not_planning' => 'Solo se puede copiar o revertir una copia en un curso académico en planificación.',
        'rollover_exists' => 'Este curso académico ya tiene una copia en curso o completada. Para repetirla, reviértela antes.',
        'rollover_blocked' => 'La copia no se puede ejecutar: revisa los motivos.',
        'rollover_in_progress' => 'Hay una copia o una reversión en curso sobre este curso académico. Espera a que termine.',
        'rollover_not_revertible' => 'Esta copia no se puede revertir en su estado actual.',
        'rollover_revert_module_unavailable' => 'No se puede revertir: el módulo «:module» no está contratado.',
        'rollover_revert_blocked' => 'No se puede revertir: hay datos añadidos después que dependen de lo copiado.',
        'rollover_not_cancellable' => 'Esta copia no se puede cancelar en su estado actual.',
        'rollover_cancel_too_early' => 'Aún no se puede cancelar: espera hasta las :available_at por si la copia sigue en marcha.',
    ],

    // 1.12b (api.md §5.1): incidencias propias de `curso`, estados de la copia y motivos de fallo.
    'rollover' => [
        'empty' => 'Con esta selección no hay nada que copiar.',
        'exists' => 'Este curso académico ya tiene una copia en curso o completada.',
        'element_forbidden' => 'No tienes permiso para crear «:element».',
        'element_unavailable' => 'El módulo de «:element» ya no está contratado.',
        'status' => [
            'pendiente' => 'En cola',
            'en_curso' => 'En curso',
            'completado' => 'Completada',
            'fallido' => 'Fallida',
            'revirtiendo' => 'Revirtiendo',
            'revertido' => 'Revertida',
        ],
        'failure' => [
            'rollover_blocked' => 'Algo impedía copiar',
            'target_not_planning' => 'El curso de destino ya no está en planificación',
            'target_changed' => 'Los datos cambiaron mientras se copiaba; vuelve a intentarlo',
            'internal_error' => 'Error interno; no se ha copiado nada',
            'actor_unavailable' => 'Quien pidió la operación ya no tiene una cuenta activa; no se ha hecho nada',
            'cancelled' => 'Cancelada; no se ha copiado nada',
        ],
    ],

    'no_active_year' => 'El centro no tiene ningún curso activo.',

    // ADR-057 §5.5, api.md §4: `urn:pge:error:academic-year-closed`.
    'academic_year_closed' => 'El curso «:code» está :status_label y sus datos son de solo lectura.',
    'academic_year_closed_anonymous' => 'El curso está cerrado y sus datos son de solo lectura.',

    'errors' => [
        'rollover_rate_limited' => 'Has alcanzado el límite de solicitudes de copia o de reversión de este centro. Inténtalo de nuevo en unos :minutes minutos.',
        'academic_year_closed' => [
            'title' => 'El curso académico es de solo lectura',
            'detail' => 'El curso «:code» está :status_label y sus datos son de solo lectura. No se pueden crear, modificar ni eliminar datos de un curso cerrado.',
            'detail_anonymous' => 'El curso está cerrado y sus datos son de solo lectura. No se pueden crear, modificar ni eliminar datos de un curso cerrado.',
        ],
    ],
];
