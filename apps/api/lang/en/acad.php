<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 se añaden con los endpoints (lote 2).

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Academic structure',
            'configuracion_academica' => 'Academic settings',
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

    'period_type' => [
        'trimestre' => 'Term (three months)',
        'cuatrimestre' => 'Four-month term',
        'semestre' => 'Semester',
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
        'period_type_invalid' => 'The period type is not valid.',
        'date_invalid' => 'The date is not valid (format YYYY-MM-DD).',
        'period_ends_before_start' => 'The end date must be after the start date.',
        'period_outside_academic_year' => 'The period must fall within the academic year (:academic_year_starts_on – :academic_year_ends_on).',
        'period_overlap' => 'The period overlaps another one in the same stage.',
        'period_type_mismatch' => 'All periods of a stage must be of the same type (:period_type).',
    ],

    'conflict' => [
        'has_dependents' => 'Cannot delete ":code" (:entity): it has dependent items (:dependents). Delete them first.',
        'periods_outside_academic_year' => 'Some academic periods would fall outside the academic year: its dates must include :periods_starts_on to :periods_ends_on.',
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
        'academic_periods' => 'academic periods',
    ],
];
