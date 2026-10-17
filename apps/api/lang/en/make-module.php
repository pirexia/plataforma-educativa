<?php

// Messages of `php artisan make:module` (ADR-056 §3.5, CA-056-30 to -33). INV-009: no literal outside this file.

return [
    'failed' => 'Nothing was generated: fix the following and run it again.',
    'created' => 'Module :name generated (:count new files).',
    'edited' => 'Shared file edited: :file',
    'next_steps' => 'Next steps: 1) create the web part in apps/web/src/modules/:module (shell.ts, locales/{es,en,de,fr}.json, api/index.ts, types/index.ts) and register it in src/navigation/modules.ts and src/i18n/index.ts (AR-11); 2) write the module specification in docs/modulos/; 3) run `php artisan platform:sync-registry` to materialise the module.',

    'errors' => [
        'name_invalid' => 'The module name ":name" is not StudlyCase ([A-Z][A-Za-z0-9]*).',
        'module_exists' => 'Module :name already exists in app/Modules.',
        'option_missing' => 'The required option :option is missing.',
        'code_invalid' => 'The code ":code" does not match [a-z][a-z0-9_]*.',
        'code_taken' => 'The code ":code" is already declared by another module.',
        'lang_file_exists' => 'lang/es/:code.php already exists: the code ":code" names that language file and it is not overwritten.',
        'entity_invalid' => 'The entity ":entity" is not StudlyCase ([A-Z][A-Za-z0-9]*).',
        'morph_alias_taken' => 'The alias ":alias" is already in the morph map of another model (ADR-034 §3).',
        'audit_policy_required' => '--entity requires --audit-policy=full|selective|redacted: ADR-035 classifies every model and fails closed, so the generator does not choose for you.',
        'audit_policy_invalid' => 'The audit policy ":policy" is not valid (ADR-035): use :allowed.',
        'audit_policy_without_entity' => '--audit-policy only makes sense with --entity.',
        'shared_file_unexpected' => 'The shared file :file cannot be edited: it is missing or does not have the expected shape.',
    ],
];
