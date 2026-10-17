<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error son los de api.md §6.

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Estructura académica',
            'configuracion_academica' => 'Configuración académica',
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

    'period_type' => [
        'trimestre' => 'Trimestre',
        'cuatrimestre' => 'Cuatrimestre',
        'semestre' => 'Semestre',
    ],

    'scale_type' => [
        'numerica' => 'Numérica',
        'cualitativa' => 'Cualitativa',
        'mixta' => 'Mixta',
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
        'period_type_invalid' => 'El tipo de período no es válido.',
        'date_invalid' => 'La fecha no es válida (formato AAAA-MM-DD).',
        'period_ends_before_start' => 'La fecha de fin debe ser posterior a la de inicio.',
        'period_outside_academic_year' => 'El período debe estar dentro del curso académico (:academic_year_starts_on – :academic_year_ends_on).',
        'period_overlap' => 'El período se solapa con otro de la misma etapa.',
        'period_type_mismatch' => 'Todos los períodos de una etapa deben ser del mismo tipo (:period_type).',
        'scale_type_invalid' => 'El tipo de escala no es válido.',
        'scale_type_immutable' => 'El tipo de una escala no se puede cambiar.',
        'scale_field_required' => 'Este campo es obligatorio para este tipo de escala.',
        'not_applicable_to_scale_type' => 'Este campo no se admite en este tipo de escala.',
        'decimal_invalid' => 'Introduce un número con punto decimal, de −999.99 a 999.99.',
        'decimals_invalid' => 'Los decimales deben ser 0, 1 o 2.',
        'decimal_precision_exceeded' => 'El valor tiene más decimales de los que admite la escala (:decimals).',
        'range_invalid' => 'El mínimo debe ser menor que el máximo.',
        'passing_out_of_range' => 'La nota mínima de aprobado debe estar entre el mínimo y el máximo.',
        'values_required' => 'La escala necesita al menos un valor.',
        'values_too_many' => 'Una escala admite como máximo :max valores.',
        'is_passing_required' => 'Indica si este valor es aprobado.',
        'passing_value_required' => 'Al menos un valor debe ser aprobado.',
        'value_not_in_scale' => 'El valor no pertenece a esta escala.',
        'band_range_invalid' => 'El tramo debe estar dentro de la escala y su inicio no puede ser mayor que su fin.',
        'bands_not_contiguous' => 'Los tramos deben cubrir toda la escala, en orden y sin huecos ni solapes.',
        'passing_threshold_inside_band' => 'La nota mínima de aprobado debe coincidir con el inicio de un tramo.',
    ],

    'conflict' => [
        'has_dependents' => 'No se puede borrar «:code» (:entity): tiene elementos dependientes (:dependents). Bórralos antes.',
        'periods_outside_academic_year' => 'Hay períodos lectivos que quedarían fuera del curso académico: sus fechas deben incluir del :periods_starts_on al :periods_ends_on.',
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
        'academic_periods' => 'períodos lectivos',
    ],
];
