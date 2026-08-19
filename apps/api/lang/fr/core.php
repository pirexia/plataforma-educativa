<?php

// Voir lang/es/core.php pour le commentaire faisant autorité.

return [
    'mail' => [
        'invitation' => [
            'subject' => 'Activez votre compte sur :tenant',
            'greeting' => 'Bonjour :name.',
            'body' => 'Vous avez été invité(e) à rejoindre :tenant sur la plateforme de gestion scolaire.',
            'cta' => 'Activer mon compte',
            'expires' => 'Ce lien expire dans :days jours. Si vous ne vous attendiez pas à ce message, vous pouvez l\'ignorer.',
        ],
    ],

    'validation' => [
        'locale_not_active' => 'La langue « :locale » n\'est pas active pour cet établissement.',
        'default_locale_not_active' => 'La langue par défaut doit faire partie des langues actives.',
        'active_locales_empty' => 'Il doit y avoir au moins une langue active.',
        'contrast_insufficient' => 'Le contraste de la palette (:ratio:1) n\'atteint pas le minimum requis (:required:1, WCAG 2.2 AA).',
        'document_number_invalid' => 'Le numéro de document n\'est pas valide pour le type indiqué.',
        'document_duplicate' => 'Une personne active avec le même type et numéro de document existe déjà dans cet établissement.',
        'email_duplicate' => 'Un utilisateur actif avec cet e-mail de connexion existe déjà dans cet établissement.',
        'role_not_found' => 'Un des rôles indiqués n\'existe pas dans cet établissement.',
        'role_permission_exceeds_own' => 'Vous ne pouvez pas attribuer un rôle qui accorde des permissions que vous ne possédez pas vous-même.',
        'file_type_mismatch' => 'Le type réel du fichier ne correspond pas au type déclaré.',
        'svg_unrepairable' => 'Le SVG ne peut pas être nettoyé de manière sûre.',
        'empty_string_not_allowed' => 'Pour vider ce champ, envoyez null plutôt qu\'une chaîne vide.',
        'query_boolean_invalid' => 'La valeur doit être « true » ou « false ».',
        'cannot_modify_self' => 'Vous ne pouvez pas effectuer cette action sur votre propre compte.',
        'last_school_administrator' => 'Il doit toujours exister au moins un administrateur d\'établissement actif.',
        'invitation_requires_pending_user' => 'Seul un utilisateur au statut « en attente » peut être invité.',
        'invitation_already_accepted' => 'Cette invitation a déjà été acceptée et ne peut pas être révoquée.',
        'enabled_not_editable' => 'L\'état d\'activation du module ne peut pas être modifié par cette voie.',
        'import_unknown_header' => 'L\'en-tête du fichier ne correspond pas au format attendu.',
        'cursor_invalid' => 'Le curseur de pagination n\'est pas valide pour cette requête.',
        'export_range_too_large' => 'La plage demandée dépasse la limite de lignes autorisée ; réduisez-la et réessayez.',
        'pdf_export_not_available' => 'L\'export PDF n\'est pas encore disponible ; utilisez CSV.',
        'export_not_ready' => 'L\'export est encore en cours de génération ; réessayez dans quelques minutes.',
        'export_failed' => 'La génération de cet export a échoué.',
        'import_not_validated' => 'Le lot doit être validé avant de pouvoir être exécuté.',
        'import_already_executed' => 'Ce lot a déjà été exécuté ou est en cours d\'exécution et ne peut pas être écarté.',
    ],

    'idempotency' => [
        'missing' => 'L\'en-tête Idempotency-Key est absent et obligatoire pour cette opération.',
        'malformed' => 'L\'en-tête Idempotency-Key doit être un ULID.',
        'body_mismatch' => 'La même clé d\'idempotence a été utilisée avec un contenu différent.',
        'in_progress' => 'La même clé d\'idempotence est encore en cours de traitement.',
    ],

    'import' => [
        'campo_obligatorio_vacio' => 'La colonne « :column » est obligatoire et vide.',
        'formato_invalido' => 'La valeur de la colonne « :column » n\'a pas un format valide.',
        'duplicado_en_fichero' => 'La valeur de la colonne « :column » apparaît plus d\'une fois dans le fichier.',
        'duplicado_en_base_de_datos' => 'La valeur de la colonne « :column » appartient déjà à une autre personne ou un autre utilisateur de l\'établissement.',
        'idioma_no_activo' => 'La langue indiquée n\'est pas active pour cet établissement.',
        'rol_no_encontrado' => 'Un des rôles indiqués n\'existe pas dans cet établissement.',
    ],
];
