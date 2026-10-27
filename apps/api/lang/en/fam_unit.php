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
        'not_legal_guardian' => 'Only a guardian with legal representation of the student can decide the image consent.',
        'guardian_required' => 'State the guardian who decided (on_behalf_of) when recording their decision on behalf of the family.',
        'guardian_not_linked' => 'The indicated guardian is not linked to that student.',
        'text_not_published' => 'The school has not published the consent text for this level: authorization is not possible without a text.',
        'text_version_invalid' => 'The indicated text version is not valid for this level or channel.',
    ],

    'terms' => [
        'kind_not_available' => 'This type of legal text is not available yet.',
        'kind_not_editable' => 'The type of a legal text cannot be changed once created.',
        'kind_invalid' => 'The legal text type is not valid.',
        'requires_reacceptance_not_allowed' => 'Re-acceptance can only be required for the terms of use, not for the image consent.',
        'not_draft' => 'Only a draft version can be edited, deleted or published.',
        'default_locale_missing' => 'The title and the text must be written in the school\'s default language (:locale) before publishing.',
    ],

    'export' => [
        'q_not_allowed' => 'The consent report does not accept free-text search (q).',
    ],

    'errors' => [
        'revocation_rate_limited' => 'You have reached the limit of revocations or revocation lifts. Try again in about :minutes minutes.',
    ],

    'validation' => [
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
    ],
];
