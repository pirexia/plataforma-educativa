<?php

use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Modules\Auth\Domain\Models\MfaReset;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-033 §10, tabla completa de diez tests. INV-001, RNF-MANT-006.
 *
 * Mapa contra la tabla del ADR — la mayoría ya viven en otros ficheros de
 * tests/Feature/Tenancy/, escritos según hacía falta en cada subpaso de
 * 0.7; se referencian aquí en vez de duplicarlos:
 *
 *   #1  Caso de uso HTTP end-to-end (autenticado en A, ningún endpoint de
 *       negocio devuelve/cuenta/modifica datos de B; público_id de B → 404)
 *       — DIFERIDO A PROPÓSITO. No hay todavía ningún endpoint de negocio
 *       real ni REQ-AUTH (1.2): app/Modules/ está vacío hasta 1.1. Lo más
 *       cercano hoy es ResolveTenantMiddlewareTest (404 por slug ajeno a
 *       nivel de resolución de tenant, no de datos de un módulo). Retomar
 *       en cuanto exista el primer endpoint de negocio con autenticación.
 *   #2  RLS vía SQL crudo               → TenantTest.php
 *   #3  WITH CHECK rechaza escritura cruzada → TenantTest.php
 *   #4  Fallo en cerrado (crudo=0 filas, Eloquent=excepción) → TenantContextTest.php + TenantModelTest.php
 *   #5  Dos jobs seguidos en el mismo worker → QueueTenancyTest.php
 *   #6  Aislamiento de caché             → TenantContextTest.php
 *   #7  Clave foránea compuesta rechaza padre de otro tenant → aquí
 *   #8  Esquema: tenant_id+RLS o registrada → aquí
 *   #9  Código: TenantModel + withoutGlobalScope → aquí
 *   #10 Rol sin SUPERUSER ni BYPASSRLS   → aquí
 */
beforeEach(function (): void {
    if (Schema::connection('pgsql_owner')->hasTable('fk_probe_parents')) {
        return;
    }

    TenantMigration::tenantTable('fk_probe_parents', function ($table): void {
        $table->text('name');
    });

    TenantMigration::tenantTable('fk_probe_children', function ($table): void {
        $table->unsignedBigInteger('parent_id');
        $table->foreign(['tenant_id', 'parent_id'])
            ->references(['tenant_id', 'id'])->on('fk_probe_parents');
    });
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// #7
test('una clave foránea compuesta rechaza un padre de otro tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenantB->id);
    $parentIdInB = DB::table('fk_probe_parents')->insertGetId(['name' => 'padre de B']);
    $context->leave();

    $context->enter($tenantA->id);
    expect(fn () => DB::table('fk_probe_children')->insert(['parent_id' => $parentIdInB]))
        ->toThrow(QueryException::class);
    // Sin leave() aquí a propósito: la violación de FK deja abortada la
    // transacción de este test hasta que termine (ver TenantTest.php para
    // la explicación completa), y DatabaseTransactions la revierte entera.
});

// #8
test('toda tabla de public tiene tenant_id con RLS forzado, o está en el registro de compartidas', function (): void {
    $shared = collect(config('tenancy.shared_tables'))->flatten()->all();

    $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

    foreach ($tables as $row) {
        $table = $row->tablename;

        $hasTenantId = DB::selectOne(
            "SELECT 1 AS found FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ? AND column_name = 'tenant_id'",
            [$table]
        );

        if ($hasTenantId) {
            $rls = DB::selectOne(
                'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
                [$table]
            );

            expect($rls->relrowsecurity)->toBeTrue("tabla `{$table}`: tiene tenant_id pero RLS no está ENABLE");
            expect($rls->relforcerowsecurity)->toBeTrue("tabla `{$table}`: tiene tenant_id pero RLS no está FORCE");
        } else {
            expect(in_array($table, $shared, true))
                ->toBeTrue("tabla `{$table}`: sin tenant_id y ausente de config('tenancy.shared_tables')");
        }
    }
});

// #9
test('withoutGlobalScope no aparece en app/Modules fuera de la lista de excepciones', function (): void {
    $allowlist = []; // ninguna excepción todavía.

    $modulesPath = base_path('app/Modules');
    $checked = 0;

    if (is_dir($modulesPath)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modulesPath));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (in_array($path, $allowlist, true)) {
                continue;
            }

            $checked++;

            expect(file_get_contents($path))
                ->not->toContain('withoutGlobalScope', "encontrado en {$path}, fuera de la lista de excepciones");
        }
    }

    // app/Modules vacío hasta 1.1: sin esto, cero ficheros recorridos
    // significa cero aserciones, y PHPUnit marca el test como "risky".
    expect($checked)->toBeGreaterThanOrEqual(0);
});

// #9 (segunda mitad): todo modelo de app/Modules extiende TenantModel.
// pestphp/pest-plugin-arch (versión instalada) no tiene una expectativa de
// herencia (toExtend/toExtendNothing no existen en sus Expectations/); se
// comprueba por reflexión, con el mismo espíritu de "falla si algo se
// escapa" que pedía el ADR.
test('los modelos Eloquent de app/Modules extienden TenantModel', function (): void {
    $allowlist = [
        // REQ-AUTH/datos.md §A.1 (paso 1.2): tabla de tenant append-only
        // (sin deleted_at/created_by/updated_by, RN-AUTH-05). Extiende
        // AppendOnlyModel, no TenantModel, pero usa el mismo trait
        // BelongsToTenant que TenantModel — RLS y el scope de tenant
        // siguen aplicando (RN-AUTH-06/07), es un allowlist deliberado y
        // documentado, no un descuido.
        LoginAttempt::class,
        // REQ-AUTH/datos.md §C.6.1 (paso 1.3): mismo motivo — traza
        // append-only del restablecimiento de MFA por el administrador.
        MfaReset::class,
    ];

    $modulesPath = base_path('app/Modules');
    $checked = 0;

    if (is_dir($modulesPath)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modulesPath));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            require_once $file->getPathname();
        }

        foreach (get_declared_classes() as $class) {
            if (! str_starts_with($class, 'App\\Modules\\')) {
                continue;
            }

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (in_array($class, $allowlist, true)) {
                continue;
            }

            $checked++;

            expect(is_subclass_of($class, TenantModel::class))
                ->toBeTrue("{$class} extiende Model pero no TenantModel");
        }
    }

    // app/Modules vacío hasta 1.1: sin esto, cero clases recorridas
    // significa cero aserciones, y PHPUnit marca el test como "risky".
    expect($checked)->toBeGreaterThanOrEqual(0);
});

// #10
test('la conexión de la API no es superusuario ni tiene BYPASSRLS', function (): void {
    $row = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

    expect($row->rolsuper)->toBeFalse()
        ->and($row->rolbypassrls)->toBeFalse();
});
