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
            'asignacion_docente' => 'Asignación docente',
            'espacios' => 'Espacios',
            'horarios' => 'Horarios',
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

    'scale_type' => [
        'numerica' => 'Numeric',
        'cualitativa' => 'Qualitative',
        'mixta' => 'Mixed',
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
        'scale_type_invalid' => 'The scale type is not valid.',
        'scale_type_immutable' => 'The type of a scale cannot be changed.',
        'scale_field_required' => 'This field is required for this scale type.',
        'not_applicable_to_scale_type' => 'This field is not allowed for this scale type.',
        'decimal_invalid' => 'Enter a number with a decimal point, from −999.99 to 999.99.',
        'decimals_invalid' => 'Decimals must be 0, 1 or 2.',
        'decimal_precision_exceeded' => 'The value has more decimals than the scale allows (:decimals).',
        'range_invalid' => 'The minimum must be lower than the maximum.',
        'passing_out_of_range' => 'The minimum passing grade must be between the minimum and the maximum.',
        'values_required' => 'The scale needs at least one value.',
        'values_too_many' => 'A scale allows at most :max values.',
        'is_passing_required' => 'Indicate whether this value is a pass.',
        'passing_value_required' => 'At least one value must be a pass.',
        'value_not_in_scale' => 'The value does not belong to this scale.',
        'band_range_invalid' => 'The band must lie within the scale and its start cannot exceed its end.',
        'bands_not_contiguous' => 'Bands must cover the whole scale, in order, with no gaps or overlaps.',
        'passing_threshold_inside_band' => 'The minimum passing grade must match the start of a band.',
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
        'has_dependents' => 'Cannot delete ":code" (:entity): it has dependent items (:dependents). Delete them first.',
        'periods_outside_academic_year' => 'Some academic periods would fall outside the academic year: its dates must include :periods_starts_on to :periods_ends_on.',
    ],

    'entities' => [
        'education_level' => 'stage',
        'course' => 'course',
        'group' => 'group',
        'subject' => 'subject',
        'subgroup' => 'subgrupo',
        'facility' => 'espacio',
        'timetable_frame' => 'marco horario',
    ],

    'dependents' => [
        'courses' => 'courses',
        'groups' => 'groups',
        'subgroups' => 'subgroups',
        'subjects' => 'subjects',
        'academic_periods' => 'academic periods',
        'group_tutors' => 'tutores de grupo',
        'teaching_assignments' => 'asignaciones docentes',
        'timetable_frames' => 'marcos horarios',
        'timetable_sessions' => 'sesiones de horario',
        'facility_bookings' => 'reservas de espacios',
    ],
];
