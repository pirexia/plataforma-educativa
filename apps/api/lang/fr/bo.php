<?php

// REQ-BO/api.md §5 (1.6). Voir lang/es/bo.php pour le commentaire faisant autorité.

return [
    'mfa' => [
        'invalid_code' => 'Le code saisi n\'est pas valide.',
    ],
    'admin' => [
        'self_modification' => 'Vous ne pouvez pas effectuer cette opération sur votre propre compte.',
        'last_superadministrator' => 'Il doit toujours rester au moins un superadministrateur actif.',
    ],
    'validation' => [
        'cursor_invalid' => 'Le curseur de pagination n\'est pas valide.',
    ],
    'tenant' => [
        'slug_taken' => 'Un établissement existe déjà avec cet identifiant.',
        'slug_reserved' => 'Cet identifiant est réservé par la plateforme.',
        'invalid_transition' => 'Cette transition d\'état n\'est pas autorisée.',
        'name_mismatch' => 'Le nom saisi ne correspond pas exactement au nom de l\'établissement.',
        'clone_source_invalid' => 'Un établissement dans cet état ne peut pas être cloné.',
    ],
    'dual_auth' => [
        'same_actor' => 'La personne qui approuve une demande ne peut pas être celle qui l\'a faite.',
        'expired' => 'Cette demande de double autorisation a expiré.',
        'already_resolved' => 'Cette demande de double autorisation est déjà résolue.',
        'payload_mismatch' => 'Cette opération ne peut plus être exécutée : ses conditions ont changé depuis la demande.',
    ],
    'tenant_lifecycle' => [
        'reason' => [
            'provisioned' => 'Provisionnement initial de l\'établissement terminé.',
        ],
    ],
    'module_subscription' => [
        'reason' => [
            'cloned' => 'Copié lors du clonage de l\'établissement source.',
        ],
    ],
    // REQ-BO-002 (1.6c) : catalogue d'erreurs de la contractualisation des modules.
    'module' => [
        'essential' => 'Le module « :module_code » est essentiel : il ne peut être ni souscrit ni résilié.',
        'retired' => 'Le module « :module_code » n\'existe plus dans le catalogue : il ne peut pas être souscrit.',
        'reason_required' => 'Le motif est obligatoire et ne peut pas être vide.',
        'tenant_state_invalid' => 'L\'établissement est dans un état qui n\'autorise pas l\'écriture de modules.',
        'missing_dependencies' => 'Des dépendances restent à souscrire : :modules. Confirmez l\'entraînement pour continuer.',
        'dependent_modules' => 'D\'autres modules dépendent de celui-ci : :modules. Confirmez l\'entraînement pour continuer.',
    ],
    // REQ-BO-004 (1.6d) : catalogue d'erreurs de santé et de nouvelle tentative des tâches.
    'job' => [
        'reason_required' => 'Le motif est obligatoire et ne peut pas être vide.',
        'tenant_state_invalid' => 'L\'établissement est dans un état qui n\'autorise pas la nouvelle tentative des tâches.',
        'reason' => [
            'retried_via_console' => 'Remise en file via bo:retry-provisioning.',
        ],
    ],
    // REQ-BO-006 (1.6d) : catalogue d'erreurs des métriques de plateforme.
    'metrics' => [
        'invalid_period' => 'Le début de la période ne peut pas être postérieur à sa fin.',
    ],
    // Issue #173. Courriel d'invitation d'un administrateur de plateforme.
    'mail' => [
        'invitation' => [
            'subject' => 'Activez votre compte d\'administrateur de plateforme',
            'greeting' => 'Bonjour :name.',
            'body' => 'Vous avez été invité(e) comme administrateur du backoffice de la plateforme de gestion scolaire.',
            'cta' => 'Activer mon compte',
            'expires' => 'Ce lien expire dans :days jours. Si vous ne vous attendiez pas à ce message, vous pouvez l\'ignorer.',
        ],
    ],
];
