<?php

// REQ-FAM-UNIT (paso 1.14-a), docs/modulos/REQ-FAM-UNIT/api.md §7. INV-009: ningún literal visible vive fuera de aquí.
// El `code` de cada error de api.md §7 es a la vez la clave de su mensaje. Los enumerados (`relationship`,
// `access_level`, `status`...) viajan como código técnico (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'familia' => 'Unités familiales, tuteurs et élèves',
            'vinculo_tutor' => 'Liens tuteur-élève',
            'revocacion_tutor' => 'Révocation de l\'accès d\'un tuteur',
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

    'errors' => [
        'revocation_rate_limited' => 'Vous avez atteint la limite de révocations ou de levées de révocation. Réessayez dans environ :minutes minutes.',
    ],

    'validation' => [
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
    ],
];
