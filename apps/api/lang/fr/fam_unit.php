<?php

// REQ-FAM-UNIT (pasos 1.14-a y 1.14-b), docs/modulos/REQ-FAM-UNIT/api.md §7. INV-009: ningún literal visible vive fuera de aquí.
// El `code` de cada error de api.md §7 es a la vez la clave de su mensaje. Los enumerados (`relationship`,
// `access_level`, `status`...) viajan como código técnico (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'familia' => 'Unités familiales, tuteurs et élèves',
            'vinculo_tutor' => 'Liens tuteur-élève',
            'revocacion_tutor' => 'Révocation de l\'accès d\'un tuteur',
            'consentimiento_imagen' => 'Consentement à l\'utilisation de l\'image',
            'texto_legal' => 'Textes juridiques de l\'établissement',
            'aceptacion_condiciones' => 'Acceptations des conditions d\'utilisation',
            'autorizado_recogida' => 'Personas autorizadas a recoger a un menor',
        ],
    ],

    'family_unit' => [
        'code_taken' => 'Une unité familiale avec ce code existe déjà.',
        'code_invalid' => 'Le code de l\'unité familiale doit comporter entre 1 et 40 caractères et ne peut pas contenir de caractères de contrôle.',
        'principal_not_member' => 'Le tuteur principal doit être un tuteur de cette même unité familiale.',
        'has_members' => 'L\'unité familiale a des tuteurs ou des élèves : retirez-les avant de la supprimer.',
    ],

    'guardian' => [
        'already_exists' => 'Cette personne est déjà tuteur d\'une unité familiale.',
        'not_editable' => 'Un tuteur ne change pas d\'unité : supprimez-le et recréez-le dans l\'autre unité.',
        'user_not_eligible' => 'L\'utilisateur doit être en attente d\'activation ou actif pour devenir tuteur.',
    ],

    'student' => [
        'already_in_unit' => 'Cet élève appartient déjà à une unité familiale.',
    ],

    'link' => [
        'already_exists' => 'Un lien entre ce tuteur et cet élève existe déjà.',
        'self_link' => 'Une personne ne peut pas être son propre tuteur.',
        'emergency_contact_taken' => 'Cet élève a déjà un autre contact d\'urgence.',
        'invalid_transition' => 'Le lien n\'est pas dans un état qui permet cette opération.',
        'not_editable' => 'Le statut du lien et sa révocation ne sont pas modifiables : utilisez la ressource de révocation.',
        'emergency_contact_revoked' => 'Un lien révoqué ne peut pas être un contact d\'urgence.',
        'revoked_not_deletable' => 'Un lien révoqué ne peut pas être supprimé : levez d\'abord la révocation.',
    ],

    'image_consent' => [
        'text_version_outdated' => 'La version du texte affichée n\'est plus la version en vigueur : relisez le texte publié avant d\'autoriser.',
        'not_legal_guardian' => 'Seul un tuteur ayant la représentation légale de l\'élève peut décider du consentement à l\'utilisation de l\'image.',
        'guardian_required' => 'Indiquez le tuteur qui a décidé (on_behalf_of) lorsque vous enregistrez sa décision au nom de la famille.',
        'guardian_not_linked' => 'Le tuteur indiqué n\'est pas lié à cet élève.',
        'text_not_published' => 'L\'établissement n\'a pas publié le texte du consentement pour ce niveau : on ne peut pas autoriser sans texte.',
        'text_version_invalid' => 'La version du texte indiquée n\'est pas valide pour ce niveau ou ce canal.',
    ],

    'terms' => [
        'kind_not_editable' => 'Le type d\'un texte juridique ne peut pas être modifié après sa création.',
        'kind_invalid' => 'Le type de texte juridique n\'est pas valide.',
        'requires_reacceptance_not_allowed' => 'La nouvelle acceptation ne peut être exigée que pour les conditions d\'utilisation, pas pour le consentement à l\'image.',
        'version_not_current' => 'La version des conditions affichée n\'est plus la version en vigueur : relisez le texte publié avant d\'accepter.',
        'not_draft' => 'Seule une version en brouillon peut être modifiée, supprimée ou publiée.',
        'default_locale_missing' => 'Le titre et le texte doivent être rédigés dans la langue par défaut de l\'établissement (:locale) avant publication.',
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
        'q_not_allowed' => 'Le rapport de consentements n\'accepte pas la recherche libre (q).',
    ],

    'errors' => [
        'consent_rate_limited' => 'Vous avez atteint la limite de décisions d\'image ou de rapports de consentements. Réessayez dans environ :minutes minutes.',
        'revocation_rate_limited' => 'Vous avez atteint la limite de révocations ou de levées de révocation. Réessayez dans environ :minutes minutes.',
        'pickup_read_rate_limited' => 'Has alcanzado el límite de consultas de la lista de recogida y de fotografías. Inténtalo de nuevo en unos :minutes minutos.',
    ],

    'validation' => [
        'terms_version_not_found' => 'La version indiquée des conditions d\'utilisation n\'existe pas.',
        'user_not_found' => 'L\'utilisateur indiqué n\'existe pas.',
        'student_not_found' => 'L\'élève indiqué n\'existe pas.',
        'guardian_not_found' => 'Le tuteur indiqué n\'existe pas.',
        'family_unit_not_found' => 'L\'unité familiale indiquée n\'existe pas.',
        'principal_guardian_not_found' => 'Le tuteur principal indiqué n\'existe pas.',
        'relationship_invalid' => 'Le type de relation n\'est pas valide.',
        'access_level_invalid' => 'Le niveau d\'accès n\'est pas valide.',
        'restricted_category_invalid' => 'La catégorie restreinte n\'est pas valide.',
        'reason_invalid' => 'Le motif de révocation n\'est pas valide.',
        'required' => 'Ce champ est obligatoire.',
        'boolean_invalid' => 'La valeur doit être vrai ou faux.',
        'reference_invalid' => 'L\'identifiant indiqué n\'est pas valide.',
        'filter_forbidden' => 'Vous n\'êtes pas autorisé à utiliser ce filtre.',
        'level_invalid' => 'Le niveau de consentement n\'est pas valide.',
        'decision_invalid' => 'La décision n\'est pas valide.',
        'channel_invalid' => 'Le canal n\'est pas valide pour cet enregistrement.',
        'state_invalid' => 'L\'état n\'est pas valide.',
        'cursor_invalid' => 'Le curseur n\'est pas valide pour cette requête.',
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
