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
    ],
    'conflict' => [
        'cannot_restore' => 'Cet élève ne peut pas être restauré : la personne n\'existe plus ou possède déjà une autre fiche d\'élève.',
    ],
];
