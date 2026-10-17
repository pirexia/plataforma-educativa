<?php

// Meldungen von `php artisan make:module` (ADR-056 §3.5, CA-056-30 bis -33). INV-009: kein Literal außerhalb dieser Datei.

return [
    'failed' => 'Es wurde nichts generiert: Behebe Folgendes und führe den Befehl erneut aus.',
    'created' => 'Modul :name generiert (:count neue Dateien).',
    'edited' => 'Gemeinsame Datei bearbeitet: :file',
    'next_steps' => 'Nächste Schritte: 1) Lege den Web-Teil in apps/web/src/modules/:module an (shell.ts, locales/{es,en,de,fr}.json, api/index.ts, types/index.ts) und registriere ihn in src/navigation/modules.ts und src/i18n/index.ts (AR-11); 2) schreibe die Modulspezifikation in docs/modulos/; 3) führe `php artisan platform:sync-registry` aus, um das Modul zu materialisieren.',

    'errors' => [
        'name_invalid' => 'Der Modulname ":name" ist nicht in StudlyCase ([A-Z][A-Za-z0-9]*).',
        'module_exists' => 'Das Modul :name existiert bereits in app/Modules.',
        'option_missing' => 'Die Pflichtoption :option fehlt.',
        'code_invalid' => 'Der Code ":code" entspricht nicht [a-z][a-z0-9_]*.',
        'code_taken' => 'Der Code ":code" wird bereits von einem anderen Modul deklariert.',
        'lang_file_exists' => 'lang/es/:code.php existiert bereits: Der Code ":code" benennt diese Sprachdatei und sie wird nicht überschrieben.',
        'entity_invalid' => 'Die Entität ":entity" ist nicht in StudlyCase ([A-Z][A-Za-z0-9]*).',
        'morph_alias_taken' => 'Der Alias ":alias" ist bereits in der Morph-Map eines anderen Modells (ADR-034 §3).',
        'audit_policy_required' => '--entity erfordert --audit-policy=full|selective|redacted: ADR-035 klassifiziert jedes Modell und schlägt geschlossen fehl, daher wählt der Generator nicht für dich.',
        'audit_policy_invalid' => 'Die Audit-Richtlinie ":policy" ist ungültig (ADR-035): verwende :allowed.',
        'audit_policy_without_entity' => '--audit-policy ist nur mit --entity sinnvoll.',
        'shared_file_unexpected' => 'Die gemeinsame Datei :file kann nicht bearbeitet werden: Sie fehlt oder hat nicht die erwartete Form.',
    ],
];
