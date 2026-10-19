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
            'asignacion_docente' => 'Lehrauftrag',
            'espacios' => 'Räume',
            'horarios' => 'Stundenpläne',
            'reservas' => 'Reservierungen',
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
        'user_not_found' => 'Der angegebene Benutzer existiert nicht oder ist nicht aktiv.',
        'subject_not_in_group' => 'Das Fach ist dieser Gruppe nicht zugeordnet.',
        'subgroup_not_in_group' => 'Die Untergruppe existiert nicht oder gehört nicht zu dieser Gruppe.',
        'assignment_exists' => 'Dieser Lehrauftrag existiert bereits.',
        'facility_type_invalid' => 'Der Raumtyp ist ungültig.',
        'facility_type_immutable' => 'Der Typ eines Raums kann nicht geändert werden.',
        'frame_type_invalid' => 'Der Typ des Stundenplanrahmens ist ungültig.',
        'frame_type_immutable' => 'Der Typ eines Stundenplanrahmens kann nicht geändert werden.',
        'frame_ordinary_exists' => 'Diese Stufe hat bereits einen regulären Stundenplanrahmen.',
        'frame_dates_invalid' => 'Die Gültigkeitsdaten sind für diesen Typ des Stundenplanrahmens nicht gültig.',
        'frame_outside_academic_year' => 'Die Gültigkeit des Rahmens muss innerhalb des Schuljahres liegen (:academic_year_starts_on – :academic_year_ends_on).',
        'frame_overlap' => 'Die Gültigkeit überschneidet sich mit der eines anderen Sonderrahmens derselben Stufe.',
        'booking_outside_academic_year' => 'Das Datum der Reservierung muss innerhalb des Schuljahres liegen (:academic_year_starts_on – :academic_year_ends_on).',
        'booking_conflict' => 'Der Raum ist zu diesem Datum und dieser Uhrzeit bereits reserviert oder durch eine Unterrichtsstunde belegt.',
        'weekday_invalid' => 'Der Wochentag muss eine Zahl von 1 (Montag) bis 7 (Sonntag) sein.',
        'time_invalid' => 'Die Uhrzeit muss im Format HH:MM (24-Stunden-Format) angegeben werden.',
        'slot_ends_before_start' => 'Die Endzeit muss nach der Anfangszeit liegen.',
        'slot_overlap' => 'Das Zeitfenster überschneidet sich mit einem anderen am selben Tag.',
        'slot_not_in_frame' => 'Das Zeitfenster gehört nicht zu diesem Rahmen oder ist doppelt angegeben.',
        'slots_too_many' => 'Ein Rahmen umfasst höchstens :max Zeitfenster.',
        'slots_invalid' => 'Die Zeitfenster müssen als Liste von Objekten angegeben werden.',
        'slot_not_for_group' => 'Das Zeitfenster gehört zu einem Rahmen einer anderen Stufe als der der Gruppe.',
        'facility_not_found' => 'Der angegebene Raum existiert nicht.',
        'facility_not_room' => 'Der angegebene Raum ist kein Klassenzimmer.',
        'timetable_conflict' => 'Die Stunde überschneidet sich im Stundenplan mit einer anderen derselben Lehrkraft, desselben Raums oder derselben Gruppe.',
        'view_filter_required' => 'Geben Sie mindestens einen der Filter an: teacher, facility, group oder subject.',
        'view_too_large' => 'Die Ansicht enthält zu viele Stunden (höchstens :max).',
    ],

    'staff' => [
        'unnamed' => 'Benutzer ohne Namen',
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
        'subgroup' => 'Untergruppe',
        'facility' => 'Raum',
        'timetable_frame' => 'Stundenplanrahmen',
        'teaching_assignment' => 'Lehrauftrag',
    ],

    'dependents' => [
        'courses' => 'Jahrgänge',
        'groups' => 'Gruppen',
        'subgroups' => 'Untergruppen',
        'subjects' => 'Fächer',
        'academic_periods' => 'Unterrichtszeiträume',
        'group_tutors' => 'Klassenleitungen',
        'teaching_assignments' => 'Lehraufträge',
        'timetable_frames' => 'Stundenplanrahmen',
        'timetable_sessions' => 'Unterrichtsstunden',
        'facility_bookings' => 'Raumreservierungen',
    ],
];
