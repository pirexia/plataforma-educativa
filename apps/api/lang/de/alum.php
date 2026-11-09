<?php

// Textos del módulo Alum (ADR-056 §3.5, INV-009). Claves a añadir en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad.

return [
    'permissions' => [
        'resources' => [
            'alumno' => 'Schülerschaft, Akte und Foto',
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
    ],
    'conflict' => [
        'cannot_restore' => 'Dieser Schüler kann nicht wiederhergestellt werden: Die Person existiert nicht mehr oder hat bereits eine andere Schülerakte.',
    ],
];
