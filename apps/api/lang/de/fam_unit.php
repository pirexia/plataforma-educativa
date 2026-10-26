<?php

// REQ-FAM-UNIT (paso 1.14-a), docs/modulos/REQ-FAM-UNIT/api.md §7. INV-009: ningún literal visible vive fuera de aquí.
// El `code` de cada error de api.md §7 es a la vez la clave de su mensaje. Los enumerados (`relationship`,
// `access_level`, `status`...) viajan como código técnico (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'familia' => 'Familieneinheiten, Erziehungsberechtigte und Schülerschaft',
            'vinculo_tutor' => 'Verknüpfungen Erziehungsberechtigte-Schüler',
            'revocacion_tutor' => 'Entzug des Zugangs eines Erziehungsberechtigten',
        ],
    ],

    'family_unit' => [
        'code_taken' => 'Es existiert bereits eine Familieneinheit mit diesem Code.',
        'code_invalid' => 'Der Code der Familieneinheit muss zwischen 1 und 40 Zeichen lang sein und darf keine Steuerzeichen enthalten.',
        'principal_not_member' => 'Der Hauptverantwortliche muss ein Erziehungsberechtigter derselben Familieneinheit sein.',
        'has_members' => 'Die Familieneinheit hat Erziehungsberechtigte oder Schüler: Entfernen Sie diese, bevor Sie sie löschen.',
    ],

    'guardian' => [
        'already_exists' => 'Diese Person ist bereits Erziehungsberechtigte einer Familieneinheit.',
        'not_editable' => 'Ein Erziehungsberechtigter wechselt nicht die Einheit: Löschen Sie ihn und legen Sie ihn in der anderen Einheit neu an.',
        'user_not_eligible' => 'Der Benutzer muss den Status „ausstehend" oder „aktiv" haben, um Erziehungsberechtigter zu werden.',
    ],

    'student' => [
        'already_in_unit' => 'Dieser Schüler gehört bereits zu einer Familieneinheit.',
    ],

    'link' => [
        'already_exists' => 'Zwischen diesem Erziehungsberechtigten und diesem Schüler besteht bereits eine Verknüpfung.',
        'self_link' => 'Eine Person kann nicht ihr eigener Erziehungsberechtigter sein.',
        'emergency_contact_taken' => 'Dieser Schüler hat bereits einen anderen Notfallkontakt.',
        'invalid_transition' => 'Die Verknüpfung befindet sich nicht in einem Zustand, der diesen Vorgang zulässt.',
        'not_editable' => 'Der Status der Verknüpfung und ihr Entzug können nicht bearbeitet werden: Verwenden Sie die Entzugsressource.',
        'emergency_contact_revoked' => 'Eine entzogene Verknüpfung kann kein Notfallkontakt sein.',
        'revoked_not_deletable' => 'Eine entzogene Verknüpfung kann nicht gelöscht werden: Heben Sie zuerst den Entzug auf.',
    ],

    'validation' => [
        'user_not_found' => 'Der angegebene Benutzer existiert nicht.',
        'student_not_found' => 'Der angegebene Schüler existiert nicht.',
        'guardian_not_found' => 'Der angegebene Erziehungsberechtigte existiert nicht.',
        'family_unit_not_found' => 'Die angegebene Familieneinheit existiert nicht.',
        'principal_guardian_not_found' => 'Der angegebene Hauptverantwortliche existiert nicht.',
        'relationship_invalid' => 'Die Art der Beziehung ist ungültig.',
        'access_level_invalid' => 'Die Zugriffsstufe ist ungültig.',
        'restricted_category_invalid' => 'Die eingeschränkte Kategorie ist ungültig.',
        'reason_invalid' => 'Der Entzugsgrund ist ungültig.',
        'required' => 'Dieses Feld ist erforderlich.',
        'boolean_invalid' => 'Der Wert muss wahr oder falsch sein.',
        'reference_invalid' => 'Die angegebene Kennung ist ungültig.',
        'filter_forbidden' => 'Sie haben keine Berechtigung, diesen Filter zu verwenden.',
    ],
];
