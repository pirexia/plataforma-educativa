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
        'mfa_exemption_already_live' => 'Cet utilisateur a déjà une exemption MFA en cours.',
        'mfa_exemption_self' => 'Vous ne pouvez pas vous accorder une exemption MFA à vous-même.',
        'mfa_reset_self' => 'Vous ne pouvez pas réinitialiser votre propre MFA.',
        'oauth_provider_not_configured' => "Ce fournisseur de connexion n'est pas disponible dans cet établissement.",
        'oauth_intent_requires_session' => 'Vous devez être connecté pour lier un compte.',
        'identity_would_leave_user_without_access' => "Vous ne pouvez pas dissocier ce compte : c'est votre seul moyen de vous connecter.",
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
        'mfa_enrollment_code' => [
            'subject' => 'Code pour activer la vérification en deux étapes sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => 'Vous activez l\'e-mail comme vérification en deux étapes sur votre compte sur :tenant. Utilisez ce code pour le confirmer.',
            'code' => 'Votre code : :code',
            'expires' => 'Ce code expire dans :minutes minutes.',
        ],
        'mfa_challenge_code' => [
            'subject' => 'Votre code de connexion pour :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => 'Vous vous connectez à :tenant et avez besoin de ce code pour terminer la vérification en deux étapes.',
            'code' => 'Votre code : :code',
            'expires' => 'Ce code expire dans :minutes minutes.',
            'warning' => 'Si vous n\'avez pas tenté de vous connecter, changez votre mot de passe au plus vite.',
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
        'identity_linked' => [
            'subject' => 'Un compte Google a été lié sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body_fusion' => 'En vous connectant avec Google sur :tenant, le système a automatiquement lié le compte :email à votre profil car l\'adresse e-mail correspondait et était vérifiée.',
            'body_profile' => 'Vous venez de lier le compte Google :email à votre profil sur :tenant.',
            'warning' => "Si ce n'était pas vous, contactez au plus vite l'administration de votre établissement.",
        ],
        'identity_unlinked' => [
            'subject' => 'Un compte Google a été dissocié sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => 'Le compte Google :email a été dissocié de votre profil sur :tenant.',
            'warning' => "Si ce n'était pas vous, contactez au plus vite l'administration de votre établissement.",
        ],
        'identity_matched' => [
            'subject' => 'Votre compte a été lié sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => "En vous connectant avec :provider sur :tenant, le système a automatiquement lié le compte :email à votre profil car l'adresse e-mail correspondait.",
            'warning' => "Si ce n'était pas vous, contactez au plus vite l'administration de votre établissement.",
        ],
    ],

    'sso' => [
        'identity_provider_issuer_already_catalogued' => 'Cet établissement a déjà un fournisseur catalogué avec cet émetteur.',
        'identity_provider_enable_without_secret' => 'Vous ne pouvez pas activer ce fournisseur sans identifiant client valide.',
        'identity_provider_secret_last_active' => "Vous ne pouvez pas retirer le dernier identifiant valide d'un fournisseur actif : désactivez-le d'abord.",
        'discovery' => [
            'esquema_no_admitido' => "L'URL de découverte doit utiliser https.",
            'destino_no_publico' => 'Cette adresse est inaccessible depuis ce serveur.',
            'demasiadas_redirecciones' => 'Le document de découverte redirige trop de fois.',
            'sin_respuesta' => 'Le document de découverte n\'a pas pu être téléchargé.',
            'respuesta_demasiado_grande' => 'Le document de découverte est trop volumineux.',
            'documento_no_valido' => 'Le document de découverte est invalide ou il manque des champs obligatoires.',
            'emisor_no_coincide' => "L'émetteur déclaré ne correspond pas à l'origine de l'URL de découverte.",
            'endpoint_no_seguro' => "L'un des points de terminaison déclarés n'utilise pas https.",
            'flujo_no_admitido' => "Cet émetteur ne prend pas en charge le flux d'autorisation requis.",
        ],
    ],

];
