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
        ],
    ],

    'validation' => [
        'code_required' => 'El código del curso es obligatorio.',
        'code_taken' => 'Ya existe un curso con ese código en el centro.',
        'ends_before_start' => 'La fecha de fin debe ser posterior a la de inicio.',
        'dates_overlap' => 'Las fechas se solapan con las del curso «:code».',
        'status_not_editable' => 'El estado de un curso no se puede indicar aquí: usa las acciones de activar y cerrar.',
    ],

    'conflict' => [
        'planning_exists' => 'Ya hay un curso en planificación («:code»). Edítalo o actívalo antes de crear otro.',
        'active_exists' => 'Ya hay un curso activo («:code»). Ciérralo antes de activar otro.',
        'invalid_transition' => 'No se puede pasar un curso de «:from» a «:to».',
        'not_editable' => 'Solo se puede editar un curso en planificación.',
        'closure_checks_failed' => 'El curso no se puede cerrar: hay validaciones de cierre que no se cumplen.',
    ],

    'no_active_year' => 'El centro no tiene ningún curso activo.',

    // ADR-057 §5.5, api.md §4: `urn:pge:error:academic-year-closed`.
    'academic_year_closed' => 'El curso «:code» está :status_label y sus datos son de solo lectura.',
    'academic_year_closed_anonymous' => 'El curso está cerrado y sus datos son de solo lectura.',

    'errors' => [
        'academic_year_closed' => [
            'title' => 'El curso académico es de solo lectura',
            'detail' => 'El curso «:code» está :status_label y sus datos son de solo lectura. No se pueden crear, modificar ni eliminar datos de un curso cerrado.',
            'detail_anonymous' => 'El curso está cerrado y sus datos son de solo lectura. No se pueden crear, modificar ni eliminar datos de un curso cerrado.',
        ],
    ],
];
