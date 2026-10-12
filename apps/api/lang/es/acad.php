<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error son los de api.md §6.

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Estructura académica',
        ],
    ],

    'stage' => [
        'infantil' => 'Infantil',
        'primaria' => 'Primaria',
        'eso' => 'ESO',
        'bachillerato' => 'Bachillerato',
        'fp' => 'Formación Profesional',
        'universidad' => 'Universidad',
    ],

    'regime' => [
        'publico' => 'Público',
        'privado' => 'Privado',
        'concertado' => 'Concertado',
    ],

    'validation' => [
        'code_required' => 'El código es obligatorio.',
        'invalid_characters' => 'El texto contiene caracteres de control no permitidos o saltos de línea donde no se admiten.',
        'code_taken' => 'Ya existe un elemento con ese código entre sus hermanos.',
        'name_required' => 'El nombre es obligatorio.',
        'stage_invalid' => 'El tipo de etapa no es válido.',
        'regime_invalid' => 'El régimen no es válido.',
        'position_invalid' => 'La posición debe ser un número entero mayor o igual que 1.',
        'academic_year_required' => 'El curso académico es obligatorio.',
        'academic_year_not_found' => 'El curso académico indicado no existe.',
        'academic_year_not_allowed' => 'El curso académico no se indica: es el del elemento padre.',
        'parent_not_found' => 'El elemento padre indicado no existe.',
        'parent_immutable' => 'El elemento padre y el curso académico no se pueden cambiar.',
        'subject_not_in_course' => 'Alguna asignatura no existe o no pertenece al curso de etapa del grupo.',
    ],

    'conflict' => [
        'has_dependents' => 'No se puede borrar el :entity «:code»: tiene elementos dependientes (:dependents). Bórralos antes.',
    ],

    'entities' => [
        'education_level' => 'etapa',
        'course' => 'curso de etapa',
        'group' => 'grupo',
        'subject' => 'asignatura',
    ],

    'dependents' => [
        'courses' => 'cursos de etapa',
        'groups' => 'grupos',
        'subgroups' => 'subgrupos',
        'subjects' => 'asignaturas',
    ],
];
