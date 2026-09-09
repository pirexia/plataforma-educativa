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
];
