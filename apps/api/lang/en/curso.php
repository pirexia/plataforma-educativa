<?php

// REQ-CURSO (paso 1.10), api.md §4, §5, permisos.md §8. INV-009: ningún
// literal de error ni de estado vive fuera de aquí. Los `code` de error de
// api.md §5 son a la vez la clave de su mensaje (`curso.validation.code_taken`…).
// El código del curso es contenido del centro y no se traduce.

return [
    'status' => [
        'planificacion' => 'Planning',
        'activo' => 'Active',
        'cerrado' => 'Closed',
        'archivado' => 'Archived',
    ],

    'permissions' => [
        'resources' => [
            'curso_academico' => 'Academic years',
            'estado_curso_academico' => 'Academic year status',
            'curso_historico' => 'Closed academic year history',
        ],
    ],

    'validation' => [
        'code_required' => 'The academic year code is required.',
        'code_taken' => 'An academic year with that code already exists in the school.',
        'ends_before_start' => 'The end date must be after the start date.',
        'dates_overlap' => 'The dates overlap with those of academic year ":code".',
        'status_not_editable' => 'The status of an academic year cannot be set here: use the activate and close actions.',
    ],

    'conflict' => [
        'planning_exists' => 'There is already an academic year in planning (":code"). Edit or activate it before creating another.',
        'active_exists' => 'There is already an active academic year (":code"). Close it before activating another.',
        'invalid_transition' => 'An academic year cannot go from ":from" to ":to".',
        'not_editable' => 'Only an academic year in planning can be edited.',
        'closure_checks_failed' => 'The academic year cannot be closed: some closing checks are not met.',
    ],

    'no_active_year' => 'The school has no active academic year.',

    // ADR-057 §5.5, api.md §4: `urn:pge:error:academic-year-closed`.
    'academic_year_closed' => 'Academic year ":code" is :status_label and its data is read-only.',
    'academic_year_closed_anonymous' => 'The academic year is closed and its data is read-only.',

    'errors' => [
        'academic_year_closed' => [
            'title' => 'The academic year is read-only',
            'detail' => 'Academic year ":code" is :status_label and its data is read-only. Data of a closed academic year cannot be created, changed or deleted.',
            'detail_anonymous' => 'The academic year is closed and its data is read-only. Data of a closed academic year cannot be created, changed or deleted.',
        ],
    ],
];
