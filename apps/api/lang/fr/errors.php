<?php

// ADR-038 §6. Voir lang/es/errors.php pour le commentaire faisant autorité.

return [
    'title' => [
        'malformed' => 'Requête malformée',
        'unauthenticated' => 'Vous n\'êtes pas connecté',
        'forbidden' => 'Vous n\'avez pas la permission',
        'module-disabled' => 'Module non disponible',
        'not-found' => 'Ressource introuvable',
        'method-not-allowed' => 'Méthode non autorisée',
        'conflict' => 'Conflit avec l\'état actuel',
        'gone' => 'Ressource plus disponible',
        'payload-too-large' => 'Fichier trop volumineux',
        'unsupported-media-type' => 'Type de fichier non pris en charge',
        'validation' => 'Les données envoyées ne sont pas valides',
        'too-many-requests' => 'Trop de requêtes',
        'internal' => 'Erreur interne du serveur',
        'unavailable' => 'Service temporairement indisponible',
    ],

    'detail' => [
        'unauthenticated' => 'Vous devez vous connecter pour accéder à cette ressource.',
        'forbidden' => 'Vous n\'avez pas la permission d\'effectuer cette action.',
        'module-disabled' => 'Ce module n\'est pas actif pour votre établissement.',
        'not-found' => 'La ressource demandée n\'existe pas.',
        'method-not-allowed' => 'La méthode HTTP n\'est pas autorisée pour cette route.',
        'validation' => 'Merci de vérifier les champs indiqués.',
        'internal' => 'Une erreur inattendue s\'est produite. Conservez l\'identifiant de la requête si vous contactez le support.',
        'unavailable' => 'Le service est temporairement indisponible. Merci de réessayer dans quelques minutes.',
    ],
];
