<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dominio base para la resolución por subdominio (ADR-014)
    |--------------------------------------------------------------------------
    |
    | El slug del tenant es la etiqueta más a la izquierda del host cuando el
    | resto coincide con este dominio (p.ej. "demo.plataforma.test" con base
    | "plataforma.test" resuelve el slug "demo"). Sin valor, ningún host
    | resuelve tenant (falla en cerrado: 404, nunca "sin filtro").
    |
    | Pendiente de infraestructura real: dominio y DNS con comodín (OPEN-08,
    | paso 0.10b, todavía bloqueante). ADR-014 también prevé dominio
    | personalizado por tenant; no implementado en 0.7 — requiere el campo
    | correspondiente en `tenants` y la gestión de certificados de RUX-DOM-003,
    | ninguno de los dos decidido todavía.
    |
    */

    'base_domain' => env('TENANCY_BASE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Registro de tablas compartidas (ADR-033 §7)
    |--------------------------------------------------------------------------
    |
    | Una tabla es de tenant por defecto: lleva tenant_id, RLS con ENABLE y
    | FORCE, y la política estándar (App\Support\Tenancy\TenantMigration::
    | tenantTable() lo aplica todo en un solo sitio). Ser compartida exige
    | figurar aquí explícitamente — el test de esquema de 0.7.11 falla si
    | aparece una tabla que no es ni una cosa ni la otra, para que el
    | sistema no se erosione tabla a tabla según se añadan módulos.
    |
    */

    'shared_tables' => [

        // Raíz del aislamiento: política RLS propia (id, no tenant_id).
        'root' => ['tenants'],

        // Sin tenant_id. REVOKE completo para plataforma_app salvo lo
        // imprescindible (ver la migración que aprovisiona cada una).
        'platform' => ['failed_jobs'],

        // Fuera del sistema de tenancy por completo.
        'framework' => [
            'migrations', 'job_batches', 'cache', 'cache_locks', 'jobs',
            'sessions', 'password_reset_tokens',
            // Pendiente de 0.8 (modelo de datos núcleo): users es del
            // starter kit de Laravel, todavía sin tenant_id. Categoría
            // temporal, no una de las cuatro reales de ADR-033 §7 —
            // quitar de aquí en cuanto 0.8 la rehaga.
            'users',
        ],

        // Solo lectura, sin tenant_id, GRANT SELECT. Ninguno todavía.
        'reference' => [],

    ],

];
