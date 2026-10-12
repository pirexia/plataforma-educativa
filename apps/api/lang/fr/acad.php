<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 se añaden con los endpoints (lote 2).

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Structure académique',
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

    'validation' => [
        'code_required' => 'Le code est obligatoire.',
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
    ],

    'conflict' => [
        'has_dependents' => 'Impossible de supprimer l\'élément «:entity» «:code» : il a des éléments dépendants (:dependents). Supprimez-les d\'abord.',
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
    ],
];
