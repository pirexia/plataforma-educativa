<?php

// Textos del módulo Prl (ADR-056 §3.5, INV-009), docs/modulos/REQ-PRL/api.md §8.4, §8.6. Claves en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad. El `code` de cada error de api.md §8.6 es a la vez
// la clave de su mensaje. Los enumerados (`direction`, `basis`, `verification_method`, `status`...) viajan como código técnico
// (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'entrega_menor' => 'Übergabe von Minderjährigen an der Tür',
            'alerta_entrega' => 'Warnungen bei nicht berechtigter Person bei der Übergabe',
        ],
    ],

    'movement' => [
        'person_not_authorized' => 'Diese Person steht nicht auf der Abholliste dieses Schülers: Der Schüler kann ihr nicht übergeben werden.',
        'verification_requires_document' => 'Für diese Person ist kein Foto hinterlegt: Die Übergabe kann nur anhand eines Dokuments überprüft werden.',
        'reason_required' => 'Eine Bewegung außerhalb der Zeiten braucht einen Grund (und eine Anmerkung, wenn der Grund „sonstiges" ist).',
    ],

    'alert' => [
        'closed' => 'Die Warnung ist bereits geschlossen und kann nicht mehr geändert werden.',
    ],

    'idempotency_mismatch' => 'Dieser Idempotenzschlüssel wurde bereits mit anderem Inhalt verwendet.',

    'protocol' => [
        'minimal' => 'No entregues al alumno. Retenlo en el centro, avisa a dirección y llama al tutor principal con el teléfono que figura en esta alerta. Anota después la hora de la llamada y la resolución.',
    ],

    'validation' => [
        'required' => 'Dieses Feld ist ein Pflichtfeld.',
        'reference_invalid' => 'Die angegebene Kennung ist nicht gültig.',
        'enum_invalid' => 'Der angegebene Wert ist nicht gültig.',
        'text_invalid' => 'Der Text ist nicht gültig oder überschreitet :max Zeichen.',
        'boolean_invalid' => 'Der Wert muss „wahr" oder „falsch" sein.',
        'datetime_invalid' => 'Datum und Uhrzeit sind nicht gültig (Format ISO 8601).',
        'student_not_found' => 'Der angegebene Schüler existiert nicht.',
        'user_not_found' => 'Der angegebene Benutzer existiert nicht oder ist nicht aktiv.',
        'reference_not_found' => 'Die zu korrigierende Bewegung existiert für diesen Schüler nicht.',
        'entry_without_pickup' => 'Ein Eintrag enthält weder eine abholende Person noch eine Überprüfungsmethode.',
        'off_schedule_reason_unexpected' => 'Nur eine Bewegung außerhalb der Zeiten enthält einen Grund.',
        'cursor_invalid' => 'Der Cursor ist für diese Abfrage nicht gültig.',
        'idempotency_key_invalid' => 'Der Idempotenzschlüssel muss zwischen 1 und 128 sichtbare Zeichen lang sein.',
        'field_not_editable' => 'Dieses Datum der Warnung kann nicht geändert werden.',
        'notes_without_resolution' => 'Anmerkungen zur Klärung können nur zusammen mit der Klärung angegeben werden.',
        'empty_body' => 'Geben Sie mindestens eine Angabe an: den Anruf beim Hauptverantwortlichen oder die Klärung.',
        'contact_time_out_of_range' => 'Die Uhrzeit des Anrufs darf weder in der Zukunft liegen noch vor der Eröffnung der Warnung.',
    ],
];
