<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 se añaden con los endpoints (lote 2).

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Structure académique',
            'configuracion_academica' => 'Paramètres académiques',
        ],
    ],

    'stage' => [
        'infantil' => 'Petite enfance',
        'primaria' => 'Primaire',
        'eso' => 'Collège (ESO)',
        'bachillerato' => 'Lycée (Bachillerato)',
        'fp' => 'Formation professionnelle',
        'universidad' => 'Université',
    ],

    'regime' => [
        'publico' => 'Public',
        'privado' => 'Privé',
        'concertado' => 'Sous contrat',
    ],

    'period_type' => [
        'trimestre' => 'Trimestre',
        'cuatrimestre' => 'Quadrimestre',
        'semestre' => 'Semestre',
    ],

    'validation' => [
        'code_required' => 'Le code est obligatoire.',
        'invalid_characters' => 'Le texte contient des caractères de contrôle non autorisés, ou des sauts de ligne là où ils ne sont pas admis.',
        'code_taken' => 'Un élément avec ce code existe déjà parmi ses éléments frères.',
        'name_required' => 'Le nom est obligatoire.',
        'stage_invalid' => 'Le type d\'étape n\'est pas valide.',
        'regime_invalid' => 'Le régime n\'est pas valide.',
        'position_invalid' => 'La position doit être un entier supérieur ou égal à 1.',
        'academic_year_required' => 'L\'année scolaire est obligatoire.',
        'academic_year_not_found' => 'L\'année scolaire indiquée n\'existe pas.',
        'academic_year_not_allowed' => 'L\'année scolaire ne s\'indique pas : c\'est celle de l\'élément parent.',
        'parent_not_found' => 'L\'élément parent indiqué n\'existe pas.',
        'parent_immutable' => 'L\'élément parent et l\'année scolaire ne peuvent pas être modifiés.',
        'subject_not_in_course' => 'Une matière n\'existe pas ou n\'appartient pas au niveau du groupe.',
        'period_type_invalid' => 'Le type de période n’est pas valide.',
        'date_invalid' => 'La date n’est pas valide (format AAAA-MM-JJ).',
        'period_ends_before_start' => 'La date de fin doit être postérieure à la date de début.',
        'period_outside_academic_year' => 'La période doit se situer dans l’année scolaire (:academic_year_starts_on – :academic_year_ends_on).',
        'period_overlap' => 'La période chevauche une autre période de la même étape.',
        'period_type_mismatch' => 'Toutes les périodes d’une étape doivent être du même type (:period_type).',
    ],

    'conflict' => [
        'has_dependents' => 'Impossible de supprimer « :code » (:entity) : il a des éléments dépendants (:dependents). Supprimez-les d\'abord.',
        'periods_outside_academic_year' => 'Des périodes scolaires se trouveraient en dehors de l’année scolaire : ses dates doivent inclure la période du :periods_starts_on au :periods_ends_on.',
    ],

    'entities' => [
        'education_level' => 'étape',
        'course' => 'niveau',
        'group' => 'groupe',
        'subject' => 'matière',
    ],

    'dependents' => [
        'courses' => 'niveaux',
        'groups' => 'groupes',
        'subgroups' => 'sous-groupes',
        'subjects' => 'matières',
        'academic_periods' => 'périodes scolaires',
    ],
];
