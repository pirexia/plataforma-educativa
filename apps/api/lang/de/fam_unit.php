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
            'aceptacion_condiciones' => 'Zustimmungen zu den Nutzungsbedingungen',
            'autorizado_recogida' => 'Personas autorizadas a recoger a un menor',
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
        'text_version_outdated' => 'Die angezeigte Textversion ist nicht mehr die aktuelle: Lesen Sie den veröffentlichten Text erneut, bevor Sie einwilligen.',
        'not_legal_guardian' => 'Nur ein Erziehungsberechtigter mit gesetzlicher Vertretung des Schülers kann über die Einwilligung zur Bildnutzung entscheiden.',
        'guardian_required' => 'Geben Sie den Erziehungsberechtigten an, der entschieden hat (on_behalf_of), wenn Sie seine Entscheidung im Namen der Familie erfassen.',
        'guardian_not_linked' => 'Der angegebene Erziehungsberechtigte ist nicht mit diesem Schüler verknüpft.',
        'text_not_published' => 'Die Schule hat den Einwilligungstext für diese Stufe nicht veröffentlicht: Ohne Text kann nicht eingewilligt werden.',
        'text_version_invalid' => 'Die angegebene Textversion ist für diese Stufe oder diesen Kanal nicht gültig.',
    ],

    'terms' => [
        'kind_not_editable' => 'Die Art eines Rechtstexts kann nach dem Anlegen nicht geändert werden.',
        'kind_invalid' => 'Die Art des Rechtstexts ist nicht gültig.',
        'requires_reacceptance_not_allowed' => 'Eine erneute Zustimmung kann nur für die Nutzungsbedingungen verlangt werden, nicht für die Einwilligung zur Bildnutzung.',
        'version_not_current' => 'Die angezeigte Fassung der Bedingungen ist nicht mehr die gültige: Lesen Sie den veröffentlichten Text erneut, bevor Sie akzeptieren.',
        'not_draft' => 'Nur eine Entwurfsversion kann bearbeitet, gelöscht oder veröffentlicht werden.',
        'default_locale_missing' => 'Titel und Text müssen vor der Veröffentlichung in der Standardsprache der Schule (:locale) vorliegen.',
    ],

    'pickup' => [
        'principal_required' => 'Solo el tutor principal de la unidad familiar, con vínculo activo y representación legal sobre el alumno, puede autorizar a una persona a recogerlo.',
        'text_not_published' => 'El centro no ha publicado el texto de autorización de recogida: no se puede autorizar a nadie sin texto.',
        'text_version_invalid' => 'La versión del texto indicada no es válida: debe ser una versión publicada o retirada del texto de autorización de recogida.',
        'text_version_outdated' => 'La versión del texto que se mostró ya no es la vigente: vuelve a leer el texto publicado antes de autorizar.',
        'person_not_eligible' => 'Esa persona no se puede autorizar para este alumno.',
        'already_exists' => 'Ya existe una autorización vigente de esa persona para ese alumno.',
        'channel_requires_puntual' => 'Una autorización registrada por teléfono solo puede ser puntual.',
        'withdrawn' => 'La autorización está retirada y no se puede modificar.',
        'invalid_transition' => 'La autorización ya está retirada.',
        'not_editable' => 'Este dato de la autorización no se puede cambiar: retírala y crea otra.',
        'document_not_editable' => 'El tipo y el número de documento de una persona no se pueden cambiar por aquí.',
    ],

    'photo' => [
        'type_mismatch' => 'El contenido de la imagen no coincide con su extensión o no se puede leer.',
        'dimensions' => 'Las dimensiones de la imagen superan el máximo admitido o no se pueden leer.',
    ],

    'export' => [
        'q_not_allowed' => 'Der Einwilligungsbericht unterstützt keine Freitextsuche (q).',
    ],

    'errors' => [
        'consent_rate_limited' => 'Sie haben das Limit für Bildentscheidungen oder Einwilligungsberichte erreicht. Versuchen Sie es in etwa :minutes Minuten erneut.',
        'revocation_rate_limited' => 'Das Limit für Entzüge oder Aufhebungen von Entzügen wurde erreicht. Versuche es in etwa :minutes Minuten erneut.',
        'pickup_read_rate_limited' => 'Has alcanzado el límite de consultas de la lista de recogida y de fotografías. Inténtalo de nuevo en unos :minutes minutos.',
    ],

    'validation' => [
        'terms_version_not_found' => 'Die angegebene Fassung der Nutzungsbedingungen existiert nicht.',
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
        'person_not_found' => 'La persona indicada no existe.',
        'pickup_relationship_invalid' => 'La relación con el alumno no es válida.',
        'pickup_kind_invalid' => 'El tipo de autorización no es válido.',
        'date_invalid' => 'La fecha no es válida (formato AAAA-MM-DD).',
        'date_range_invalid' => 'La fecha de fin no puede ser anterior a la de inicio (en una autorización puntual, debe ser el mismo día).',
        'datetime_invalid' => 'La fecha y hora no son válidas (formato ISO 8601).',
        'authorized_at_future' => 'La fecha y hora de la autorización no pueden ser futuras.',
        'reason_too_long' => 'El motivo no puede superar los :max caracteres.',
        'text_invalid' => 'El valor debe ser un texto.',
        'text_too_long' => 'El texto no puede superar los :max caracteres.',
        'at_least_one_field' => 'Indica al menos un dato que corregir.',
    ],
];
