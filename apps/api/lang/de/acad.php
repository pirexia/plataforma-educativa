<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 se añaden con los endpoints (lote 2).

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Akademische Struktur',
            'configuracion_academica' => 'Akademische Einstellungen',
            'asignacion_docente' => 'Asignación docente',
            'espacios' => 'Espacios',
            'horarios' => 'Horarios',
        ],
    ],

    'stage' => [
        'infantil' => 'Frühkindliche Bildung',
        'primaria' => 'Grundschule',
        'eso' => 'Sekundarstufe I (ESO)',
        'bachillerato' => 'Sekundarstufe II (Bachillerato)',
        'fp' => 'Berufsbildung',
        'universidad' => 'Universität',
    ],

    'regime' => [
        'publico' => 'Öffentlich',
        'privado' => 'Privat',
        'concertado' => 'Staatlich gefördert',
    ],

    'period_type' => [
        'trimestre' => 'Trimester',
        'cuatrimestre' => 'Tertial',
        'semestre' => 'Semester',
    ],

    'scale_type' => [
        'numerica' => 'Numerisch',
        'cualitativa' => 'Qualitativ',
        'mixta' => 'Gemischt',
    ],

    'validation' => [
        'code_required' => 'Der Code ist erforderlich.',
        'invalid_characters' => 'Der Text enthält unzulässige Steuerzeichen oder Zeilenumbrüche an unzulässiger Stelle.',
        'code_taken' => 'Unter den gleichrangigen Elementen existiert bereits ein Element mit diesem Code.',
        'name_required' => 'Der Name ist erforderlich.',
        'stage_invalid' => 'Die Stufenart ist ungültig.',
        'regime_invalid' => 'Die Trägerschaft ist ungültig.',
        'position_invalid' => 'Die Position muss eine ganze Zahl größer oder gleich 1 sein.',
        'academic_year_required' => 'Das Schuljahr ist erforderlich.',
        'academic_year_not_found' => 'Das angegebene Schuljahr existiert nicht.',
        'academic_year_not_allowed' => 'Das Schuljahr wird nicht angegeben: Es ist das des übergeordneten Elements.',
        'parent_not_found' => 'Das angegebene übergeordnete Element existiert nicht.',
        'parent_immutable' => 'Das übergeordnete Element und das Schuljahr können nicht geändert werden.',
        'subject_not_in_course' => 'Ein Fach existiert nicht oder gehört nicht zum Jahrgang der Gruppe.',
        'period_type_invalid' => 'Die Art des Zeitraums ist ungültig.',
        'date_invalid' => 'Das Datum ist ungültig (Format JJJJ-MM-TT).',
        'period_ends_before_start' => 'Das Enddatum muss nach dem Startdatum liegen.',
        'period_outside_academic_year' => 'Der Zeitraum muss innerhalb des Schuljahres liegen (:academic_year_starts_on – :academic_year_ends_on).',
        'period_overlap' => 'Der Zeitraum überschneidet sich mit einem anderen derselben Stufe.',
        'period_type_mismatch' => 'Alle Zeiträume einer Stufe müssen von derselben Art sein (:period_type).',
        'scale_type_invalid' => 'Die Art der Skala ist ungültig.',
        'scale_type_immutable' => 'Die Art einer Skala kann nicht geändert werden.',
        'scale_field_required' => 'Dieses Feld ist für diese Skalenart erforderlich.',
        'not_applicable_to_scale_type' => 'Dieses Feld ist für diese Skalenart nicht zulässig.',
        'decimal_invalid' => 'Geben Sie eine Zahl mit Dezimalpunkt zwischen −999.99 und 999.99 ein.',
        'decimals_invalid' => 'Die Nachkommastellen müssen 0, 1 oder 2 sein.',
        'decimal_precision_exceeded' => 'Der Wert hat mehr Nachkommastellen, als die Skala zulässt (:decimals).',
        'range_invalid' => 'Das Minimum muss kleiner als das Maximum sein.',
        'passing_out_of_range' => 'Die Mindestnote zum Bestehen muss zwischen Minimum und Maximum liegen.',
        'values_required' => 'Die Skala braucht mindestens einen Wert.',
        'values_too_many' => 'Eine Skala erlaubt höchstens :max Werte.',
        'is_passing_required' => 'Geben Sie an, ob dieser Wert bestanden bedeutet.',
        'passing_value_required' => 'Mindestens ein Wert muss bestanden bedeuten.',
        'value_not_in_scale' => 'Der Wert gehört nicht zu dieser Skala.',
        'band_range_invalid' => 'Der Bereich muss innerhalb der Skala liegen und sein Anfang darf sein Ende nicht übersteigen.',
        'bands_not_contiguous' => 'Die Bereiche müssen die ganze Skala lückenlos, geordnet und ohne Überschneidung abdecken.',
        'passing_threshold_inside_band' => 'Die Mindestnote zum Bestehen muss mit dem Anfang eines Bereichs übereinstimmen.',
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
        'has_dependents' => 'Das Element „:code“ (:entity) kann nicht gelöscht werden: Es hat abhängige Elemente (:dependents). Löschen Sie diese zuerst.',
        'periods_outside_academic_year' => 'Einige Unterrichtszeiträume lägen außerhalb des Schuljahres: Seine Daten müssen den Zeitraum vom :periods_starts_on bis zum :periods_ends_on einschließen.',
    ],

    'entities' => [
        'education_level' => 'Stufe',
        'course' => 'Jahrgang',
        'group' => 'Gruppe',
        'subject' => 'Fach',
        'subgroup' => 'subgrupo',
        'facility' => 'espacio',
        'timetable_frame' => 'marco horario',
    ],

    'dependents' => [
        'courses' => 'Jahrgänge',
        'groups' => 'Gruppen',
        'subgroups' => 'Untergruppen',
        'subjects' => 'Fächer',
        'academic_periods' => 'Unterrichtszeiträume',
        'group_tutors' => 'tutores de grupo',
        'teaching_assignments' => 'asignaciones docentes',
        'timetable_frames' => 'marcos horarios',
        'timetable_sessions' => 'sesiones de horario',
        'facility_bookings' => 'reservas de espacios',
    ],
];
