<?php

// Textos del módulo Alum (ADR-056 §3.5, INV-009). Claves a añadir en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad.

return [
    'permissions' => [
        'resources' => [
            'alumno' => 'Élèves, dossier et photographie',
            'matricula' => 'Inscription, groupes et demandes de changement',
        ],
    ],
    'validation' => [
        'required' => 'Cette information est obligatoire.',
        'text_invalid' => 'Le texte indiqué n\'est pas valide : il doit comporter entre 1 et 255 caractères, sans caractères de contrôle.',
        'birth_date_invalid' => 'La date de naissance doit respecter le format AAAA-MM-JJ, être une date réelle et ne pas être dans le futur.',
        'person_exists' => 'Une personne portant ce document d\'identité existe déjà.',
        'person_already_student' => 'Cette personne est déjà élève de l\'établissement.',
        'family_unit_not_found' => 'L\'unité familiale indiquée n\'existe pas.',
        'field_not_editable' => 'Cette information ne peut pas être modifiée par cette voie.',
        'no_changes' => 'Aucune donnée à modifier n\'a été indiquée.',
        'filter_forbidden' => 'Vous n\'avez pas l\'autorisation d\'utiliser ce filtre.',
        'student_not_found' => 'L\'élève indiqué n\'existe pas.',
        'parent_not_found' => 'Le cours, le groupe ou la structure indiqués n\'existent pas.',
        'academic_year_not_found' => 'L\'année scolaire indiquée n\'existe pas.',
        'course_not_in_academic_year' => 'Le cours indiqué n\'appartient pas à l\'année scolaire.',
        'group_not_in_course' => 'Le groupe indiqué n\'appartient pas au cours.',
        'already_enrolled' => 'L\'élève a déjà une inscription active pour cette année scolaire.',
        'date_outside_academic_year' => 'La date doit être comprise dans l\'année scolaire.',
        'date_invalid' => 'La date doit respecter le format AAAA-MM-JJ et être une date réelle.',
        'group_change_requires_request' => 'Changer le groupe d\'une inscription qui en a déjà un nécessite une demande de changement de groupe.',
        'immutable' => 'Cette donnée de l\'inscription ne peut pas être modifiée.',
        'withdrawal_reason_invalid' => 'Le motif de radiation doit être transfert, départ volontaire ou autre.',
        'withdrawn_before_enrolled' => 'La date de radiation ne peut pas être antérieure à la date d\'inscription.',
    ],
    'conflict' => [
        'cannot_restore' => 'Cet élève ne peut pas être restauré : la personne n\'existe plus ou possède déjà une autre fiche d\'élève.',
        'already_withdrawn' => 'L\'inscription est déjà radiée.',
        'has_active_enrollments' => 'Un élève ayant des inscriptions actives ne peut pas être radié.',
    ],
];
