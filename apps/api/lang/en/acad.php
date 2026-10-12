<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 se añaden con los endpoints (lote 2).

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Academic structure',
        ],
    ],

    'stage' => [
        'infantil' => 'Early childhood',
        'primaria' => 'Primary',
        'eso' => 'Lower secondary (ESO)',
        'bachillerato' => 'Upper secondary (Bachillerato)',
        'fp' => 'Vocational training',
        'universidad' => 'University',
    ],

    'regime' => [
        'publico' => 'Public',
        'privado' => 'Private',
        'concertado' => 'Publicly subsidised',
    ],

    'validation' => [
        'code_required' => 'The code is required.',
        'invalid_characters' => 'The text contains control characters that are not allowed, or line breaks where they are not permitted.',
        'code_taken' => 'An item with that code already exists among its siblings.',
        'name_required' => 'The name is required.',
        'stage_invalid' => 'The stage type is not valid.',
        'regime_invalid' => 'The regime is not valid.',
        'position_invalid' => 'The position must be an integer greater than or equal to 1.',
        'academic_year_required' => 'The academic year is required.',
        'academic_year_not_found' => 'The academic year does not exist.',
        'academic_year_not_allowed' => 'The academic year is not provided: it is the parent item\'s.',
        'parent_not_found' => 'The parent item does not exist.',
        'parent_immutable' => 'The parent item and the academic year cannot be changed.',
        'subject_not_in_course' => 'A subject does not exist or does not belong to the group\'s course.',
    ],

    'conflict' => [
        'has_dependents' => 'Cannot delete the :entity ":code": it has dependent items (:dependents). Delete them first.',
    ],

    'entities' => [
        'education_level' => 'stage',
        'course' => 'course',
        'group' => 'group',
        'subject' => 'subject',
    ],

    'dependents' => [
        'courses' => 'courses',
        'groups' => 'groups',
        'subgroups' => 'subgroups',
        'subjects' => 'subjects',
    ],
];
