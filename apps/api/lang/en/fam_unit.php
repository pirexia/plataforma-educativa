<?php

// REQ-FAM-UNIT (pasos 1.14-a y 1.14-b), docs/modulos/REQ-FAM-UNIT/api.md §7. INV-009: ningún literal visible vive fuera de aquí.
// El `code` de cada error de api.md §7 es a la vez la clave de su mensaje. Los enumerados (`relationship`,
// `access_level`, `status`...) viajan como código técnico (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'familia' => 'Family units, guardians and students',
            'vinculo_tutor' => 'Guardian-student links',
            'revocacion_tutor' => 'Revocation of a guardian\'s access',
            'consentimiento_imagen' => 'Image use consent',
            'texto_legal' => 'School legal texts',
            'aceptacion_condiciones' => 'Acceptances of the terms of use',
            'autorizado_recogida' => 'Personas autorizadas a recoger a un menor',
        ],
    ],

    'family_unit' => [
        'code_taken' => 'A family unit with that code already exists.',
        'code_invalid' => 'The family unit code must be between 1 and 40 characters and cannot contain control characters.',
        'principal_not_member' => 'The principal guardian must be a guardian of this same family unit.',
        'has_members' => 'The family unit has guardians or students: remove them before deleting it.',
    ],

    'guardian' => [
        'already_exists' => 'That person is already a guardian of a family unit.',
        'not_editable' => 'A guardian does not change unit: delete the guardian and create them again in the other unit.',
        'user_not_eligible' => 'The user must be pending activation or active to become a guardian.',
    ],

    'student' => [
        'already_in_unit' => 'That student already belongs to a family unit.',
    ],

    'link' => [
        'already_exists' => 'A link between that guardian and that student already exists.',
        'self_link' => 'A person cannot be their own guardian.',
        'emergency_contact_taken' => 'That student already has another emergency contact.',
        'invalid_transition' => 'The link is not in a state that allows this operation.',
        'not_editable' => 'The status of the link and its revocation cannot be edited: use the revocation resource.',
        'emergency_contact_revoked' => 'A revoked link cannot be an emergency contact.',
        'revoked_not_deletable' => 'A revoked link cannot be deleted: lift the revocation first.',
    ],

    'image_consent' => [
        'text_version_outdated' => 'The text version that was shown is no longer the current one: read the published text again before authorising.',
        'not_legal_guardian' => 'Only a guardian with legal representation of the student can decide the image consent.',
        'guardian_required' => 'State the guardian who decided (on_behalf_of) when recording their decision on behalf of the family.',
        'guardian_not_linked' => 'The indicated guardian is not linked to that student.',
        'text_not_published' => 'The school has not published the consent text for this level: authorization is not possible without a text.',
        'text_version_invalid' => 'The indicated text version is not valid for this level or channel.',
    ],

    'terms' => [
        'kind_not_editable' => 'The type of a legal text cannot be changed once created.',
        'kind_invalid' => 'The legal text type is not valid.',
        'requires_reacceptance_not_allowed' => 'Re-acceptance can only be required for the terms of use, not for the image consent.',
        'version_not_current' => 'The version of the terms that was shown is no longer the current one: read the published text again before accepting.',
        'not_draft' => 'Only a draft version can be edited, deleted or published.',
        'default_locale_missing' => 'The title and the text must be written in the school\'s default language (:locale) before publishing.',
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
        'q_not_allowed' => 'The consent report does not accept free-text search (q).',
    ],

    'errors' => [
        'consent_rate_limited' => 'You have reached the limit of image decisions or consent reports. Try again in about :minutes minutes.',
        'revocation_rate_limited' => 'You have reached the limit of revocations or revocation lifts. Try again in about :minutes minutes.',
        'pickup_read_rate_limited' => 'Has alcanzado el límite de consultas de la lista de recogida y de fotografías. Inténtalo de nuevo en unos :minutes minutos.',
    ],

    'validation' => [
        'terms_version_not_found' => 'The indicated version of the terms of use does not exist.',
        'user_not_found' => 'The specified user does not exist.',
        'student_not_found' => 'The specified student does not exist.',
        'guardian_not_found' => 'The specified guardian does not exist.',
        'family_unit_not_found' => 'The specified family unit does not exist.',
        'principal_guardian_not_found' => 'The specified principal guardian does not exist.',
        'relationship_invalid' => 'The relationship type is not valid.',
        'access_level_invalid' => 'The access level is not valid.',
        'restricted_category_invalid' => 'The restricted category is not valid.',
        'reason_invalid' => 'The revocation reason is not valid.',
        'required' => 'This field is required.',
        'boolean_invalid' => 'The value must be true or false.',
        'reference_invalid' => 'The specified identifier is not valid.',
        'filter_forbidden' => 'You do not have permission to use this filter.',
        'level_invalid' => 'The consent level is not valid.',
        'decision_invalid' => 'The decision is not valid.',
        'channel_invalid' => 'The channel is not valid for this record.',
        'state_invalid' => 'The state is not valid.',
        'cursor_invalid' => 'The cursor is not valid for this query.',
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
