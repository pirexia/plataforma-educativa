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
            'reapertura_curso_academico' => 'Réouverture des années scolaires',
            'apertura_curso_academico' => 'Ouverture de l’année scolaire suivante',
        ],
    ],

    'validation' => [
        'code_required' => 'Le code de l\'année scolaire est obligatoire.',
        'code_taken' => 'Une année scolaire avec ce code existe déjà dans l\'établissement.',
        'ends_before_start' => 'La date de fin doit être postérieure à la date de début.',
        'dates_overlap' => 'Les dates chevauchent celles de l\'année scolaire « :code ».',
        'status_not_editable' => 'Le statut d\'une année scolaire ne peut pas être indiqué ici : utilisez les actions Activer et Clôturer.',
        'reason_required' => 'Le motif de la réouverture est obligatoire.',
        'rollover_target_required' => 'Indiquez l’année scolaire de destination.',
        'rollover_target_not_found' => 'L’année scolaire de destination n’existe pas.',
        'rollover_source_required' => 'Indiquez l’année scolaire d’origine.',
        'rollover_source_not_found' => 'L’année scolaire d’origine n’existe pas.',
        'rollover_source_invalid' => 'L’année d’origine doit être active ou clôturée et commencer avant l’année de destination.',
        'rollover_elements_required' => 'Sélectionnez au moins un élément à copier.',
        'rollover_element_unknown' => '« :element » ne peut pas être copié.',
        'rollover_element_requires' => 'Pour copier « :element », il faut aussi copier « :requires ».',
        'rollover_element_not_selectable' => '« :element » ne se sélectionne pas séparément : il est copié avec les affectations d’enseignement.',
        'rollover_teaching_assignments_unavailable' => 'Les affectations d’enseignement ne peuvent pas être copiées : aucun module souscrit ne les propose.',
    ],

    'conflict' => [
        'planning_exists' => 'Il existe déjà une année scolaire en planification (« :code »). Modifiez-la ou activez-la avant d\'en créer une autre.',
        'active_exists' => 'Il existe déjà une année scolaire active (« :code »). Clôturez-la avant d\'en activer une autre.',
        'invalid_transition' => 'Une année scolaire ne peut pas passer de « :from » à « :to ».',
        'not_editable' => 'Seule une année scolaire en planification peut être modifiée.',
        'closure_checks_failed' => 'L\'année scolaire ne peut pas être clôturée : certaines vérifications de clôture ne sont pas remplies.',
        'date_change_checks_failed' => 'Les nouvelles dates de l’année scolaire excluent des données qui en dépendent.',
        'reopen_not_latest' => 'Seule l\'année scolaire clôturée la plus récente peut être rouverte : l\'année scolaire « :code » est postérieure.',
        'reopen_checks_failed' => 'L\'année scolaire ne peut pas être rouverte : certaines vérifications de réouverture ne sont pas remplies.',
        'rollover_target_not_planning' => 'On ne peut copier, ni annuler une copie, que dans une année scolaire en planification.',
        'rollover_exists' => 'Cette année scolaire a déjà une copie en cours ou terminée. Annulez-la avant de la recommencer.',
        'rollover_blocked' => 'La copie ne peut pas être exécutée : vérifiez les motifs.',
        'rollover_in_progress' => 'Une copie ou une annulation est en cours pour cette année scolaire. Attendez qu’elle se termine.',
        'rollover_not_revertible' => 'Cette copie ne peut pas être annulée dans son état actuel.',
        'rollover_revert_module_unavailable' => 'Annulation impossible : le module « :module » n’est pas souscrit.',
        'rollover_revert_blocked' => 'Annulation impossible : des données ajoutées ensuite dépendent de ce qui a été copié.',
        'rollover_not_cancellable' => 'Cette copie ne peut pas être interrompue dans son état actuel.',
        'rollover_cancel_too_early' => 'Interruption pas encore possible : attendez jusqu’à :available_at au cas où la copie serait encore en cours.',
    ],

    // 1.12b (api.md §5.1): incidencias propias de `curso`, estados de la copia y motivos de fallo.
    'rollover' => [
        'empty' => 'Il n’y a rien à copier avec cette sélection.',
        'exists' => 'Cette année scolaire a déjà une copie en cours ou terminée.',
        'element_forbidden' => 'Vous n’avez pas l’autorisation de créer « :element ».',
        'element_unavailable' => 'Le module de « :element » n’est plus souscrit.',
        'status' => [
            'pendiente' => 'En file d’attente',
            'en_curso' => 'En cours',
            'completado' => 'Terminée',
            'fallido' => 'Échouée',
            'revirtiendo' => 'Annulation en cours',
            'revertido' => 'Annulée',
        ],
        'failure' => [
            'rollover_blocked' => 'Un obstacle a empêché la copie',
            'target_not_planning' => 'L’année de destination n’est plus en planification',
            'target_changed' => 'Les données ont changé pendant la copie ; réessayez',
            'internal_error' => 'Erreur interne ; rien n’a été copié',
            'actor_unavailable' => 'La personne à l’origine de la demande n’a plus de compte actif ; rien n’a été fait',
            'cancelled' => 'Interrompue ; rien n’a été copié',
        ],
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
