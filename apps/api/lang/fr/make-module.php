<?php

// Messages de `php artisan make:module` (ADR-056 §3.5, CA-056-30 à -33). INV-009 : aucun littéral en dehors de ce fichier.

return [
    'failed' => 'Rien n\'a été généré : corrigez ce qui suit et relancez la commande.',
    'created' => 'Module :name généré (:count nouveaux fichiers).',
    'edited' => 'Fichier partagé modifié : :file',
    'next_steps' => 'Étapes suivantes : 1) créez la partie web dans apps/web/src/modules/:module (shell.ts, locales/{es,en,de,fr}.json, api/index.ts, types/index.ts) et enregistrez-la dans src/navigation/modules.ts et src/i18n/index.ts (AR-11) ; 2) rédigez la spécification du module dans docs/modulos/ ; 3) exécutez `php artisan platform:sync-registry` pour matérialiser le module.',

    'errors' => [
        'name_invalid' => 'Le nom de module « :name » n\'est pas en StudlyCase ([A-Z][A-Za-z0-9]*).',
        'module_exists' => 'Le module :name existe déjà dans app/Modules.',
        'option_missing' => 'L\'option obligatoire :option est manquante.',
        'code_invalid' => 'Le code « :code » ne respecte pas [a-z][a-z0-9_]*.',
        'code_taken' => 'Le code « :code » est déjà déclaré par un autre module.',
        'lang_file_exists' => 'lang/es/:code.php existe déjà : le code « :code » nomme ce fichier de langue et il n\'est pas écrasé.',
        'entity_invalid' => 'L\'entité « :entity » n\'est pas en StudlyCase ([A-Z][A-Za-z0-9]*).',
        'morph_alias_taken' => 'L\'alias « :alias » figure déjà dans le morph map d\'un autre modèle (ADR-034 §3).',
        'audit_policy_required' => '--entity exige --audit-policy=full|selective|redacted : l\'ADR-035 classe chaque modèle et échoue en mode fermé, le générateur ne choisit donc pas à votre place.',
        'audit_policy_invalid' => 'La politique d\'audit « :policy » n\'est pas valide (ADR-035) : utilisez :allowed.',
        'audit_policy_without_entity' => '--audit-policy n\'a de sens qu\'avec --entity.',
        'table_taken' => "La table « :table » que dériverait l'entité « :entity » existe déjà dans une migration ou appartient au framework : choisissez un autre nom d'entité.",
        'table_too_long' => "Le nom de table « :table » dépasse les :max caractères admis par PostgreSQL : raccourcissez le nom de l'entité.",
        'path_exists' => "Le chemin :path existe déjà : le générateur n'écrase aucun fichier.",
        'shared_file_unexpected' => 'Le fichier partagé :file ne peut pas être modifié : il est absent ou n\'a pas la forme attendue.',
    ],
];
