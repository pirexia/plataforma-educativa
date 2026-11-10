<?php

// Textos del módulo Alum (ADR-056 §3.5, INV-009). Claves a añadir en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad.

return [
    'permissions' => [
        'resources' => [
            'alumno' => 'Schülerschaft, Akte und Foto',
            'matricula' => 'Einschreibung, Gruppen und Wechselanträge',
        ],
    ],
    'validation' => [
        'required' => 'Diese Angabe ist erforderlich.',
        'text_invalid' => 'Der angegebene Text ist ungültig: Er muss zwischen 1 und 255 Zeichen lang sein und darf keine Steuerzeichen enthalten.',
        'birth_date_invalid' => 'Das Geburtsdatum muss im Format JJJJ-MM-TT angegeben werden, ein reales Datum sein und darf nicht in der Zukunft liegen.',
        'person_exists' => 'Es existiert bereits eine Person mit diesem Ausweisdokument.',
        'person_already_student' => 'Diese Person ist bereits Schüler der Schule.',
        'family_unit_not_found' => 'Die angegebene Familieneinheit existiert nicht.',
        'field_not_editable' => 'Diese Angabe kann auf diesem Weg nicht geändert werden.',
        'no_changes' => 'Es wurden keine zu ändernden Daten angegeben.',
        'filter_forbidden' => 'Sie haben keine Berechtigung, diesen Filter zu verwenden.',
        'student_not_found' => 'Der angegebene Schüler existiert nicht.',
        'parent_not_found' => 'Der angegebene Kurs, die angegebene Gruppe oder Struktur existiert nicht.',
        'academic_year_not_found' => 'Das angegebene Schuljahr existiert nicht.',
        'course_not_in_academic_year' => 'Der angegebene Kurs gehört nicht zum Schuljahr.',
        'group_not_in_course' => 'Die angegebene Gruppe gehört nicht zum Kurs.',
        'already_enrolled' => 'Der Schüler hat in diesem Schuljahr bereits eine aktive Einschreibung.',
        'date_outside_academic_year' => 'Das Datum muss innerhalb des Schuljahres liegen.',
        'date_invalid' => 'Das Datum muss das Format JJJJ-MM-TT haben und ein echtes Datum sein.',
        'group_change_requires_request' => 'Für den Wechsel der Gruppe einer Einschreibung, die bereits eine hat, ist ein Gruppenwechselantrag erforderlich.',
        'immutable' => 'Diese Angabe der Einschreibung kann nicht geändert werden.',
        'withdrawal_reason_invalid' => 'Der Abmeldegrund muss Versetzung, freiwillige Abmeldung oder Sonstiges sein.',
        'withdrawn_before_enrolled' => 'Das Abmeldedatum darf nicht vor dem Einschreibedatum liegen.',
    ],
    'conflict' => [
        'cannot_restore' => 'Dieser Schüler kann nicht wiederhergestellt werden: Die Person existiert nicht mehr oder hat bereits eine andere Schülerakte.',
        'already_withdrawn' => 'Die Einschreibung ist bereits abgemeldet.',
        'has_active_enrollments' => 'Ein Schüler mit aktiven Einschreibungen kann nicht abgemeldet werden.',
    ],
];
