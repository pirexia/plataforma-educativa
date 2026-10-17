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
    ],

    'dependents' => [
        'courses' => 'Jahrgänge',
        'groups' => 'Gruppen',
        'subgroups' => 'Untergruppen',
        'subjects' => 'Fächer',
        'academic_periods' => 'Unterrichtszeiträume',
    ],
];
