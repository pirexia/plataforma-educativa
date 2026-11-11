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
        'export_query_not_allowed' => 'Ein Export kann keinen Suchbegriff verwenden: Verwenden Sie nur die Filter der Liste.',
        'status_invalid' => 'Der Status muss activa oder baja sein.',
        'request_already_pending' => 'Für die Einschreibung liegt bereits ein offener Antrag auf Gruppenwechsel vor.',
        'reason_invalid' => 'Die Begründung muss zwischen 1 und 500 Zeichen lang sein und darf keine Steuerzeichen enthalten.',
        'decision_note_required' => 'Bei der Ablehnung eines Antrags muss eine Begründung angegeben werden.',
        'decision_note_invalid' => 'Der Entscheidungsvermerk muss zwischen 1 und 500 Zeichen lang sein und darf keine Steuerzeichen enthalten.',
        'enrollment_not_active' => 'Ein Gruppenwechsel kann nur für eine aktive Einschreibung beantragt werden.',
        'enrollment_without_group' => 'Die Einschreibung hat keine Gruppe: Weisen Sie ihr direkt eine zu, ohne Antrag.',
    ],
    'errors' => [
        'export_rate_limited' => 'Sie haben das Limit für Matrikelexporte erreicht. Versuchen Sie es in etwa :minutes Minuten erneut.',
    ],
    'conflict' => [
        'cannot_restore' => 'Dieser Schüler kann nicht wiederhergestellt werden: Die Person existiert nicht mehr oder hat bereits eine andere Schülerakte.',
        'already_withdrawn' => 'Die Einschreibung ist bereits abgemeldet.',
        'has_active_enrollments' => 'Ein Schüler mit aktiven Einschreibungen kann nicht abgemeldet werden.',
        'request_closed' => 'Der Antrag ist bereits entschieden und kann nicht mehr geändert werden.',
        'enrollment_changed' => 'Die Einschreibung hat sich seit dem Antrag geändert: Sie ist nicht mehr aktiv oder nicht mehr in der Ausgangsgruppe.',
    ],
    'forbidden' => [
        'self_approval' => 'Sie können einen von Ihnen selbst gestellten Antrag nicht genehmigen.',
    ],
];
