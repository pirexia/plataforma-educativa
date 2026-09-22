<?php

// operacion.md §2. REQ-CORE (paso 1.1). Ninguna es secreta.

return [

    /*
    |--------------------------------------------------------------------------
    | Caducidad de la invitación (RN-CORE-10)
    |--------------------------------------------------------------------------
    */
    'invitation_ttl_days' => (int) env('CORE_INVITATION_TTL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Importación masiva de usuarios
    |--------------------------------------------------------------------------
    */
    'import_max_rows' => (int) env('CORE_IMPORT_MAX_ROWS', 20000),
    'import_retention_days' => (int) env('CORE_IMPORT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Exportación del registro de auditoría
    |--------------------------------------------------------------------------
    */
    'export_max_rows' => (int) env('CORE_EXPORT_MAX_ROWS', 500000),
    'export_retention_days' => (int) env('CORE_EXPORT_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | URLs firmadas de activos (branding, informes, exportaciones)
    |--------------------------------------------------------------------------
    */
    'signed_url_ttl_minutes' => (int) env('CORE_SIGNED_URL_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Validación de documentos (OPEN-CORE-06, decisión (b))
    |--------------------------------------------------------------------------
    |
    | Conmutador por entorno. `production` fuerza esta comprobación a
    | `true` sin excepción — verificado por
    | Core\Infrastructure\CoreServiceProvider::boot() (ver
    | tests/Feature/Core/DocumentValidationConfigTest.php), no solo por
    | esta nota de documentación: REQ-SEED-005 necesita sembrar documentos
    | con dígito de control inválido a propósito, y eso solo puede
    | ocurrir fuera de producción.
    |
    */
    'documents' => [
        'validate_check_digit' => (bool) env('CORE_VALIDATE_DOCUMENT_CHECK_DIGIT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Motor de feature flags (REQ-BO-005 puntos 1-2, sub-paso 1.6e)
    |--------------------------------------------------------------------------
    |
    | El evaluador vive en REQ-CORE (funcional.md §5.11.8, §12.3) aunque el
    | nombre de la variable de entorno conserve el prefijo `BO_` —
    | operacion.md §4.3 ya la nombra así, y esta clave es la referencia
    | canónica de implementación. OPEN-BO-24, decisión (b): TTL de la
    | única entrada de caché del catálogo completo, no por flag ni por
    | sujeto.
    |
    */
    'feature_flags' => [
        'cache_ttl_seconds' => (int) env('BO_FLAG_CACHE_TTL', 300),
    ],

];
