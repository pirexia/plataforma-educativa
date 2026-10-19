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
            'asignacion_docente' => 'Teaching assignments',
            'espacios' => 'Facilities',
            'horarios' => 'Timetables',
            'reservas' => 'Reservas',
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
        'user_not_found' => 'The specified user does not exist or is not active.',
        'subject_not_in_group' => 'The subject is not assigned to this group.',
        'subgroup_not_in_group' => 'The subgroup does not exist or does not belong to this group.',
        'assignment_exists' => 'This teaching assignment already exists.',
        'facility_type_invalid' => 'The facility type is not valid.',
        'facility_type_immutable' => 'The type of a facility cannot be changed.',
        'frame_type_invalid' => 'The timetable frame type is not valid.',
        'frame_type_immutable' => 'The type of a timetable frame cannot be changed.',
        'frame_ordinary_exists' => 'This stage already has a regular timetable frame.',
        'frame_dates_invalid' => 'The validity dates are not valid for this type of timetable frame.',
        'frame_outside_academic_year' => 'La vigencia del marco debe estar dentro del curso académico (:academic_year_starts_on – :academic_year_ends_on).',
        'frame_overlap' => 'La vigencia se solapa con la de otro marco especial de la misma etapa.',
        'booking_outside_academic_year' => 'La fecha de la reserva debe estar dentro del curso académico (:academic_year_starts_on – :academic_year_ends_on).',
        'booking_conflict' => 'El espacio ya está reservado u ocupado por una sesión de horario en esa fecha y hora.',
        'weekday_invalid' => 'The day of the week must be a number from 1 (Monday) to 7 (Sunday).',
        'time_invalid' => 'The time must be in HH:MM format (24-hour clock).',
        'slot_ends_before_start' => 'The end time must be later than the start time.',
        'slot_overlap' => 'The time slot overlaps another one on the same day.',
        'slot_not_in_frame' => 'The time slot does not belong to this frame or is repeated.',
        'slots_too_many' => 'A frame allows at most :max time slots.',
        'slots_invalid' => 'The time slots must be given as a list of objects.',
        'slot_not_for_group' => 'The time slot belongs to a frame of a different stage than the group.',
        'facility_not_found' => 'The specified facility does not exist.',
        'facility_not_room' => 'The specified facility is not a classroom.',
        'timetable_conflict' => 'The session clashes in the timetable with another session for the same teacher, room or group.',
        'view_filter_required' => 'Specify at least one of the filters: teacher, facility, group or subject.',
        'view_too_large' => 'The view has too many sessions (maximum :max).',
    ],

    'staff' => [
        'unnamed' => 'Unnamed user',
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
        'subgroup' => 'subgroup',
        'facility' => 'facility',
        'timetable_frame' => 'timetable frame',
        'teaching_assignment' => 'teaching assignment',
    ],

    'dependents' => [
        'courses' => 'courses',
        'groups' => 'groups',
        'subgroups' => 'subgroups',
        'subjects' => 'subjects',
        'academic_periods' => 'academic periods',
        'group_tutors' => 'group tutors',
        'teaching_assignments' => 'teaching assignments',
        'timetable_frames' => 'timetable frames',
        'timetable_sessions' => 'timetable sessions',
        'facility_bookings' => 'facility bookings',
    ],
];
