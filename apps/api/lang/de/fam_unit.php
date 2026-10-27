<?php

// REQ-FAM-UNIT (pasos 1.14-a y 1.14-b), docs/modulos/REQ-FAM-UNIT/api.md §7. INV-009: ningún literal visible vive fuera de aquí.
// El `code` de cada error de api.md §7 es a la vez la clave de su mensaje. Los enumerados (`relationship`,
// `access_level`, `status`...) viajan como código técnico (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'familia' => 'Familieneinheiten, Erziehungsberechtigte und Schülerschaft',
            'vinculo_tutor' => 'Verknüpfungen Erziehungsberechtigte-Schüler',
            'revocacion_tutor' => 'Entzug des Zugangs eines Erziehungsberechtigten',
            'consentimiento_imagen' => 'Einwilligung zur Bildnutzung',
            'texto_legal' => 'Rechtstexte der Schule',
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

    'image_consent' => [
        'not_legal_guardian' => 'Nur ein Erziehungsberechtigter mit gesetzlicher Vertretung des Schülers kann über die Einwilligung zur Bildnutzung entscheiden.',
        'guardian_required' => 'Geben Sie den Erziehungsberechtigten an, der entschieden hat (on_behalf_of), wenn Sie seine Entscheidung im Namen der Familie erfassen.',
        'guardian_not_linked' => 'Der angegebene Erziehungsberechtigte ist nicht mit diesem Schüler verknüpft.',
        'text_not_published' => 'Die Schule hat den Einwilligungstext für diese Stufe nicht veröffentlicht: Ohne Text kann nicht eingewilligt werden.',
        'text_version_invalid' => 'Die angegebene Textversion ist für diese Stufe oder diesen Kanal nicht gültig.',
    ],

    'terms' => [
        'kind_not_available' => 'Diese Art von Rechtstext ist noch nicht verfügbar.',
        'kind_not_editable' => 'Die Art eines Rechtstexts kann nach dem Anlegen nicht geändert werden.',
        'kind_invalid' => 'Die Art des Rechtstexts ist nicht gültig.',
        'requires_reacceptance_not_allowed' => 'Eine erneute Zustimmung kann nur für die Nutzungsbedingungen verlangt werden, nicht für die Einwilligung zur Bildnutzung.',
        'not_draft' => 'Nur eine Entwurfsversion kann bearbeitet, gelöscht oder veröffentlicht werden.',
        'default_locale_missing' => 'Titel und Text müssen vor der Veröffentlichung in der Standardsprache der Schule (:locale) vorliegen.',
    ],

    'export' => [
        'q_not_allowed' => 'Der Einwilligungsbericht unterstützt keine Freitextsuche (q).',
    ],

    'errors' => [
        'revocation_rate_limited' => 'Das Limit für Entzüge oder Aufhebungen von Entzügen wurde erreicht. Versuche es in etwa :minutes Minuten erneut.',
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
        'level_invalid' => 'Die Einwilligungsstufe ist nicht gültig.',
        'decision_invalid' => 'Die Entscheidung ist nicht gültig.',
        'channel_invalid' => 'Der Kanal ist für diesen Eintrag nicht gültig.',
        'state_invalid' => 'Der Status ist nicht gültig.',
        'cursor_invalid' => 'Der Cursor ist für diese Abfrage nicht gültig.',
    ],
];
