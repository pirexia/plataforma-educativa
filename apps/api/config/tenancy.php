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

        // Fuera del sistema de tenancy por completo: sin tenant_id.
        // `users` salió de aquí en 0.8.4: ADR-034 la rehace con tenant_id y
        // RLS, ya no es la tabla provisional del starter kit.
        // `password_reset_tokens` también salió en 0.8.4 (ADR-034 §8): tiene
        // tenant_id, RLS y política propia, aunque su PK sea compuesta
        // (tenant_id, email) en vez de id+tenant_id como tenantTable() — el
        // test de esquema de 0.7.11 la exige RLS por tener tenant_id, no
        // por estar en este registro.
        'framework' => [
            'migrations', 'job_batches', 'cache', 'cache_locks', 'jobs',
            'sessions',
        ],

        // Solo lectura para plataforma_app, sin tenant_id. Catálogo de
        // plataforma materializado desde el código por el comando
        // idempotente de 0.8.11 (ADR-034 §2, §7) — nunca a mano.
        'reference' => ['permissions', 'modules'],

    ],

];
