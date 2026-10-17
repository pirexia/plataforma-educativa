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
            'asignacion_docente' => 'Asignación docente',
            'espacios' => 'Espacios',
            'horarios' => 'Horarios',
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

    'scale_type' => [
        'numerica' => 'Numérique',
        'cualitativa' => 'Qualitative',
        'mixta' => 'Mixte',
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
        'scale_type_invalid' => 'Le type d’échelle n’est pas valide.',
        'scale_type_immutable' => 'Le type d’une échelle ne peut pas être modifié.',
        'scale_field_required' => 'Ce champ est obligatoire pour ce type d’échelle.',
        'not_applicable_to_scale_type' => 'Ce champ n’est pas admis pour ce type d’échelle.',
        'decimal_invalid' => 'Saisissez un nombre avec un point décimal, de −999.99 à 999.99.',
        'decimals_invalid' => 'Les décimales doivent être 0, 1 ou 2.',
        'decimal_precision_exceeded' => 'La valeur a plus de décimales que l’échelle n’en admet (:decimals).',
        'range_invalid' => 'Le minimum doit être inférieur au maximum.',
        'passing_out_of_range' => 'La note minimale de réussite doit être comprise entre le minimum et le maximum.',
        'values_required' => 'L’échelle doit comporter au moins une valeur.',
        'values_too_many' => 'Une échelle admet au maximum :max valeurs.',
        'is_passing_required' => 'Indiquez si cette valeur correspond à une réussite.',
        'passing_value_required' => 'Au moins une valeur doit correspondre à une réussite.',
        'value_not_in_scale' => 'La valeur n’appartient pas à cette échelle.',
        'band_range_invalid' => 'La tranche doit se situer dans l’échelle et son début ne peut pas dépasser sa fin.',
        'bands_not_contiguous' => 'Les tranches doivent couvrir toute l’échelle, dans l’ordre, sans trou ni chevauchement.',
        'passing_threshold_inside_band' => 'La note minimale de réussite doit coïncider avec le début d’une tranche.',
        'user_not_found' => 'El usuario indicado no existe o no está activo.',
        'subject_not_in_group' => 'La asignatura no está asignada a este grupo.',
        'subgroup_not_in_group' => 'El subgrupo no existe o no pertenece a este grupo.',
        'assignment_exists' => 'Esta asignación docente ya existe.',
        'facility_type_invalid' => 'El tipo de espacio no es válido.',
        'facility_type_immutable' => 'El tipo de un espacio no se puede cambiar.',
        'frame_type_invalid' => 'El tipo de marco horario no es válido.',
        'frame_type_immutable' => 'El tipo de un marco horario no se puede cambiar.',
        'frame_ordinary_exists' => 'Esta etapa ya tiene un marco horario ordinario.',
        'frame_dates_invalid' => 'Las fechas de vigencia no son válidas para este tipo de marco horario.',
        'weekday_invalid' => 'El día de la semana debe ser un número del 1 (lunes) al 7 (domingo).',
        'time_invalid' => 'La hora debe tener el formato HH:MM (24 horas).',
        'slot_ends_before_start' => 'La hora de fin debe ser posterior a la de inicio.',
        'slot_overlap' => 'La franja se solapa con otra del mismo día.',
        'slot_not_in_frame' => 'La franja no pertenece a este marco o está repetida.',
        'slots_too_many' => 'Un marco admite como máximo :max franjas.',
        'slots_invalid' => 'Las franjas deben indicarse como una lista de objetos.',
        'slot_not_for_group' => 'La franja es de un marco de una etapa distinta de la del grupo.',
        'facility_not_found' => 'El espacio indicado no existe.',
        'facility_not_room' => 'El espacio indicado no es un aula.',
        'timetable_conflict' => 'La sesión coincide en el horario con otra del mismo profesor, aula o grupo.',
        'view_filter_required' => 'Indica al menos uno de los filtros teacher, facility, group o subject.',
        'view_too_large' => 'La vista tiene demasiadas sesiones (máximo :max).',
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
        'subgroup' => 'subgrupo',
        'facility' => 'espacio',
        'timetable_frame' => 'marco horario',
    ],

    'dependents' => [
        'courses' => 'niveaux',
        'groups' => 'groupes',
        'subgroups' => 'sous-groupes',
        'subjects' => 'matières',
        'academic_periods' => 'périodes scolaires',
        'group_tutors' => 'tutores de grupo',
        'teaching_assignments' => 'asignaciones docentes',
        'timetable_frames' => 'marcos horarios',
        'timetable_sessions' => 'sesiones de horario',
        'facility_bookings' => 'reservas de espacios',
    ],
];
