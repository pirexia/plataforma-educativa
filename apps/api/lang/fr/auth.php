<?php

// REQ-AUTH, étape 1.2. Voir lang/es/auth.php pour le commentaire faisant autorité.

return [

    'validation' => [
        'password' => [
            'min_length' => 'Le mot de passe doit comporter au moins :min caractères.',
            'max_bytes' => 'Le mot de passe est trop long.',
            'uppercase' => 'Le mot de passe doit contenir au moins une majuscule.',
            'lowercase' => 'Le mot de passe doit contenir au moins une minuscule.',
            'digit' => 'Le mot de passe doit contenir au moins un chiffre.',
            'symbol' => 'Le mot de passe doit contenir au moins un symbole.',
            'same_as_current' => 'Le nouveau mot de passe ne peut pas être identique à l\'actuel.',
        ],
        'current_password_incorrect' => 'Le mot de passe actuel est incorrect.',
        'lockout_already_unlocked' => 'Ce verrouillage a déjà été levé.',
        'session_already_closed' => 'Cette session est déjà fermée.',
        'mfa_factor_already_confirmed' => 'Vous avez déjà un facteur confirmé pour cette méthode.',
        'mfa_method_not_available' => 'Cette méthode de vérification n\'est pas disponible dans cet établissement.',
        'mfa_code_invalid' => 'Le code n\'est pas correct.',
        'mfa_factor_required_by_role' => 'Vous ne pouvez pas désactiver votre dernier facteur : l\'un de vos rôles exige la vérification en deux étapes.',
    ],

    'mail' => [
        'account_locked' => [
            'subject' => 'Votre compte sur :tenant a été temporairement verrouillé',
            'greeting' => 'Bonjour :name.',
            'body' => ':count tentatives de connexion échouées ont été détectées sur votre compte sur :tenant, il a été temporairement verrouillé par sécurité.',
            'auto_unlock' => 'Le verrouillage sera levé automatiquement le :time si vous ne faites rien.',
            'cta' => 'Déverrouiller mon compte maintenant',
        ],
        'password_reset' => [
            'subject' => 'Réinitialisez votre mot de passe sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => 'Vous avez demandé à réinitialiser votre mot de passe sur :tenant.',
            'cta' => 'Réinitialiser mon mot de passe',
            'expires' => 'Ce lien expire dans :minutes minutes.',
            'ignore' => 'Si ce n\'était pas vous, vous pouvez ignorer ce message : votre mot de passe actuel reste valide.',
        ],
        'password_changed' => [
            'subject' => 'Votre mot de passe sur :tenant a changé',
            'greeting' => 'Bonjour :name.',
            'body' => 'Votre mot de passe sur :tenant vient d\'être modifié.',
            'warning' => 'Si ce n\'était pas vous, contactez au plus vite l\'administration de votre établissement.',
        ],
        'mfa_factor_activated' => [
            'subject' => 'La vérification en deux étapes a été activée sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => 'Un nouveau facteur de vérification en deux étapes vient d\'être activé sur votre compte sur :tenant.',
            'warning' => 'Si ce n\'était pas vous, contactez au plus vite l\'administration de votre établissement.',
        ],
        'mfa_factor_removed' => [
            'subject' => 'La vérification en deux étapes a été désactivée sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body_by_self' => 'Un facteur de vérification en deux étapes vient d\'être retiré de votre compte sur :tenant.',
            'body_by_admin' => 'Un administrateur de :tenant a réinitialisé la vérification en deux étapes de votre compte : vos anciens facteurs et codes de secours ne sont plus valides.',
            'warning' => 'Si ce n\'était pas vous, contactez au plus vite l\'administration de votre établissement.',
        ],
        'recovery_code_used' => [
            'subject' => 'Un code de secours a été utilisé sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => 'L\'un de vos codes de secours a été utilisé pour accéder à votre compte sur :tenant à la place de votre second facteur habituel.',
            'warning' => 'Si ce n\'était pas vous, contactez au plus vite l\'administration de votre établissement et vérifiez vos sessions actives.',
        ],
        'new_device_login' => [
            'subject' => 'Nouvelle connexion à votre compte sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => 'Une connexion à votre compte sur :tenant a eu lieu depuis un appareil que nous n\'avions pas encore vu, le :time.',
            'detail' => 'Détails de la connexion : :client, depuis l\'adresse IP :ip.',
            'location_line' => 'Localisation approximative : :location.',
            'what_to_do' => 'Si c\'était vous, vous n\'avez rien d\'autre à faire. Si vous ne reconnaissez pas cette connexion, vérifiez vos sessions actives et changez votre mot de passe dès que possible.',
            'cta' => 'Vérifier mes sessions actives',
            'unknown_client' => 'un appareil inconnu',
        ],
    ],

];
