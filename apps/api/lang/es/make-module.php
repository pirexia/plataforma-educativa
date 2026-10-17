<?php

// Mensajes de `php artisan make:module` (ADR-056 §3.5, CA-056-30 a -33). INV-009: ningún literal fuera de aquí.

return [
    'failed' => 'No se ha generado nada: corrige lo siguiente y vuelve a ejecutar.',
    'created' => 'Módulo :name generado (:count ficheros nuevos).',
    'edited' => 'Fichero compartido editado: :file',
    'next_steps' => 'Siguientes pasos: 1) crea la parte web en apps/web/src/modules/:module (shell.ts, locales/{es,en,de,fr}.json, api/index.ts, types/index.ts) y regístrala en src/navigation/modules.ts y src/i18n/index.ts (AR-11); 2) escribe la especificación del módulo en docs/modulos/; 3) ejecuta `php artisan platform:sync-registry` para materializar el módulo.',

    'errors' => [
        'name_invalid' => 'El nombre del módulo «:name» no es StudlyCase ([A-Z][A-Za-z0-9]*).',
        'module_exists' => 'El módulo :name ya existe en app/Modules.',
        'option_missing' => 'Falta la opción obligatoria :option.',
        'code_invalid' => 'El código «:code» no cumple [a-z][a-z0-9_]*.',
        'code_taken' => 'El código «:code» ya lo declara otro módulo.',
        'lang_file_exists' => 'Ya existe lang/es/:code.php: el código «:code» da nombre a ese fichero de idioma y no se sobrescribe.',
        'entity_invalid' => 'La entidad «:entity» no es StudlyCase ([A-Z][A-Za-z0-9]*).',
        'morph_alias_taken' => 'El alias «:alias» ya está en el morph map de otro modelo (ADR-034 §3).',
        'audit_policy_required' => '--entity exige --audit-policy=full|selective|redacted: ADR-035 clasifica cada modelo y falla en cerrado, así que el generador no elige por ti.',
        'audit_policy_invalid' => 'La política de auditoría «:policy» no es válida (ADR-035): usa :allowed.',
        'audit_policy_without_entity' => '--audit-policy solo tiene sentido con --entity.',
        'shared_file_unexpected' => 'No se puede editar el fichero compartido :file: falta o no tiene la forma esperada.',
    ],
];
