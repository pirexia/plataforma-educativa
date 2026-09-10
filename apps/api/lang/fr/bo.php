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
