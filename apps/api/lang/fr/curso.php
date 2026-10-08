<?php

// REQ-CURSO (paso 1.10), api.md §4, §5, permisos.md §8. INV-009: ningún
// literal de error ni de estado vive fuera de aquí. Los `code` de error de
// api.md §5 son a la vez la clave de su mensaje (`curso.validation.code_taken`…).
// El código del curso es contenido del centro y no se traduce.

return [
    'status' => [
        'planificacion' => 'En planification',
        'activo' => 'Actif',
        'cerrado' => 'Clôturé',
        'archivado' => 'Archivé',
    ],

    'permissions' => [
        'resources' => [
            'curso_academico' => 'Années scolaires',
            'estado_curso_academico' => 'Statut des années scolaires',
            'curso_historico' => 'Historique des années scolaires clôturées',
        ],
    ],

    'validation' => [
        'code_required' => 'Le code de l\'année scolaire est obligatoire.',
        'code_taken' => 'Une année scolaire avec ce code existe déjà dans l\'établissement.',
        'ends_before_start' => 'La date de fin doit être postérieure à la date de début.',
        'dates_overlap' => 'Les dates chevauchent celles de l\'année scolaire « :code ».',
        'status_not_editable' => 'Le statut d\'une année scolaire ne peut pas être indiqué ici : utilisez les actions Activer et Clôturer.',
    ],

    'conflict' => [
        'planning_exists' => 'Il existe déjà une année scolaire en planification (« :code »). Modifiez-la ou activez-la avant d\'en créer une autre.',
        'active_exists' => 'Il existe déjà une année scolaire active (« :code »). Clôturez-la avant d\'en activer une autre.',
        'invalid_transition' => 'Une année scolaire ne peut pas passer de « :from » à « :to ».',
        'not_editable' => 'Seule une année scolaire en planification peut être modifiée.',
        'closure_checks_failed' => 'L\'année scolaire ne peut pas être clôturée : certaines vérifications de clôture ne sont pas remplies.',
    ],

    'no_active_year' => 'L\'établissement n\'a aucune année scolaire active.',

    // ADR-057 §5.5, api.md §4: `urn:pge:error:academic-year-closed`.
    'academic_year_closed' => 'L\'année scolaire « :code » est :status_label et ses données sont en lecture seule.',
    'academic_year_closed_anonymous' => 'L\'année scolaire est clôturée et ses données sont en lecture seule.',

    'errors' => [
        'academic_year_closed' => [
            'title' => 'L\'année scolaire est en lecture seule',
            'detail' => 'L\'année scolaire « :code » est :status_label et ses données sont en lecture seule. Les données d\'une année scolaire clôturée ne peuvent être ni créées, ni modifiées, ni supprimées.',
            'detail_anonymous' => 'L\'année scolaire est clôturée et ses données sont en lecture seule. Les données d\'une année scolaire clôturée ne peuvent être ni créées, ni modifiées, ni supprimées.',
        ],
    ],
];
