<?php

use App\Models\ModuleSubscription;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ADR-034 §5, §7 (0.8.7). Sin modelo ModuleSubscription todavía (0.8.9).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('plataforma_app no puede escribir en modules', function (): void {
    expect(fn () => DB::connection('pgsql')->table('modules')->insert([
        'code' => 'REQ-ALUM', 'name_key' => 'modules.alumnado', 'phase' => '1',
    ]))->toThrow(QueryException::class);
});

// Issue #19: plataforma_platform (BYPASSRLS) también tiene que quedar fuera,
// no solo plataforma_app.
test('plataforma_platform no puede escribir en modules', function (): void {
    expect(fn () => DB::connection('pgsql_platform')->table('modules')->insert([
        'code' => 'REQ-ALUM-PLATFORM', 'name_key' => 'modules.alumnado', 'phase' => '1',
    ]))->toThrow(QueryException::class);
});

test('ausencia de fila en module_subscriptions se lee como módulo desactivado', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    // `->select('id')` (1.6c, datos.md §7.7): tras el `REVOKE SELECT` de
    // tabla, un `exists()` que compile `select *` exige privilegio sobre
    // TODAS las columnas de `module_subscriptions`, y `plataforma_app`
    // ya no lo tiene. Mismo ajuste que `EloquentModuleAvailability::
    // isEnabled()`.
    $enabled = DB::table('module_subscriptions')
        ->select('id')
        ->where('module_code', 'REQ-ALUM-NUNCA-CONTRATADO')
        ->where('enabled', true)
        ->exists();

    $context->leave();

    expect($enabled)->toBeFalse();
});

test('un tenant no ve las suscripciones de otro', function (): void {
    if (! DB::connection('pgsql_owner')->table('modules')->where('code', 'REQ-TEST-MOD')->exists()) {
        DB::connection('pgsql_owner')->table('modules')->insert([
            'code' => 'REQ-TEST-MOD', 'name_key' => 'modules.test', 'phase' => '1',
        ]);
    }

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    // `pgsql_platform` (1.6c, datos.md §7): el `REVOKE INSERT` de la
    // migración de privilegios le quita a `plataforma_app` la capacidad
    // de insertar en `module_subscriptions` — `plataforma_platform` es
    // la conexión del backoffice, que sigue con privilegio completo.
    $context->enter($tenantA->id);
    DB::connection('pgsql_platform')->table('module_subscriptions')->insert([
        'tenant_id' => $tenantA->id, 'public_id' => (string) Str::ulid(), 'module_code' => 'REQ-TEST-MOD', 'enabled' => true,
    ]);
    // `->select('id')`: mismo motivo que el test anterior — el `REVOKE
    // SELECT` de tabla alcanza también a esta lectura por `plataforma_app`.
    $visibleEnB = DB::table('module_subscriptions')->select('id')->where('module_code', 'REQ-TEST-MOD')->exists();
    $context->leave();

    $context->enter($tenantB->id);
    $visibleForB = DB::table('module_subscriptions')->select('id')->where('module_code', 'REQ-TEST-MOD')->exists();
    $context->leave();

    expect($visibleEnB)->toBeTrue()
        ->and($visibleForB)->toBeFalse();

    // La fila de module_subscriptions ahora se escribe por
    // `pgsql_platform` (1.6c, arriba), fuera de la transacción `pgsql`
    // que `DatabaseTransactions` revierte tras el test — a diferencia de
    // antes de `1.6c`, si no se borra aquí queda comprometida de verdad.
    // El fixture 'REQ-TEST-MOD' de `modules` sí se deja registrado entre
    // corridas, igual que las tablas de prueba de TenantMigrationTest.
    DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'REQ-TEST-MOD')->delete();
});

// 1.6c, ADR-045 §4.4, datos.md §7, §7.7: la migración de privilegios de
// `module_subscriptions`. `plataforma_platform` no se toca (sigue con
// privilegio completo, es la conexión del backoffice) — sólo se
// comprueba `plataforma_app`.

// Un `QueryException` deja la transacción `pgsql` de `DatabaseTransactions`
// abortada hasta el `ROLLBACK` del test entero: cualquier comprobación
// que necesite seguir usando esa conexión DESPUÉS de una operación que
// se espera que falle tiene que aislarla en su propio `SAVEPOINT`
// (`DB::connection('pgsql')->transaction()` anidado dentro de la
// transacción de nivel de test), o el resto del test vería
// "current transaction is aborted" en la siguiente sentencia.
function expectPgsqlQueryToThrow(Closure $callback): void
{
    expect(fn () => DB::connection('pgsql')->transaction($callback))->toThrow(QueryException::class);
}

test('CA-BO-030: plataforma_app no puede escribir enabled en module_subscriptions', function (): void {
    if (! DB::connection('pgsql_owner')->table('modules')->where('code', 'REQ-TEST-MOD')->exists()) {
        DB::connection('pgsql_owner')->table('modules')->insert(['code' => 'REQ-TEST-MOD', 'name_key' => 'modules.test', 'phase' => '1']);
    }

    $tenant = Tenant::factory()->create();
    $subscriptionId = DB::connection('pgsql_platform')->table('module_subscriptions')->insertGetId([
        'tenant_id' => $tenant->id, 'public_id' => (string) Str::ulid(), 'module_code' => 'REQ-TEST-MOD', 'enabled' => false,
    ]);

    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    expectPgsqlQueryToThrow(fn () => DB::table('module_subscriptions')->where('id', $subscriptionId)->update(['enabled' => true]));

    $context->leave();

    DB::connection('pgsql_platform')->table('module_subscriptions')->where('id', $subscriptionId)->delete();
});

test('CA-BO-031: plataforma_app no puede insertar en module_subscriptions, y sí puede seguir escribiendo settings', function (): void {
    if (! DB::connection('pgsql_owner')->table('modules')->where('code', 'REQ-TEST-MOD')->exists()) {
        DB::connection('pgsql_owner')->table('modules')->insert(['code' => 'REQ-TEST-MOD', 'name_key' => 'modules.test', 'phase' => '1']);
    }

    // Auto-limpieza de la fila que deja la corrida anterior de este
    // mismo test (ver comentario más abajo, sobre por qué esa fila NO
    // se borra al final de este test): para entonces su tenant ya no
    // existe (afterEach ya lo borró), así que este `whereNotIn` sólo
    // alcanza filas huérfanas de ejecuciones pasadas, nunca la del
    // tenant que este test está a punto de crear.
    DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('module_code', 'REQ-TEST-MOD')
        ->whereNotIn('tenant_id', DB::connection('pgsql_platform')->table('tenants')->select('id'))
        ->delete();

    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    expectPgsqlQueryToThrow(fn () => DB::table('module_subscriptions')->insert([
        'public_id' => (string) Str::ulid(), 'module_code' => 'REQ-TEST-MOD', 'enabled' => true,
    ]));

    $context->leave();

    $subscriptionId = DB::connection('pgsql_platform')->table('module_subscriptions')->insertGetId([
        'tenant_id' => $tenant->id, 'public_id' => (string) Str::ulid(), 'module_code' => 'REQ-TEST-MOD', 'enabled' => true, 'settings' => '{}',
    ]);

    $context->enter($tenant->id);

    // La lista de columnas concedidas está completa: settings/updated_at/
    // updated_by/deleted_at siguen escribibles por el camino normal de
    // la aplicación (`ModulesController::updateSettings()`, REQ-CORE).
    DB::table('module_subscriptions')->where('id', $subscriptionId)->update(['settings' => json_encode(['x' => 1])]);
    $settings = DB::table('module_subscriptions')->select('settings')->where('id', $subscriptionId)->value('settings');

    $context->leave();

    expect(json_decode((string) $settings, true))->toBe(['x' => 1]);

    // Sin DELETE aquí a propósito, y no es un olvido: el `UPDATE` de
    // arriba corrió por `pgsql` dentro de la transacción de nivel de
    // test (`DatabaseTransactions`), que mantiene el bloqueo de fila
    // hasta que el test termina — un `DELETE` por `pgsql_platform`
    // (autocommit, sesión distinta) sobre esa misma fila aquí se
    // quedaría esperando ese bloqueo para siempre: un punto muerto real,
    // reproducido al escribir este test. La limpieza vive al principio
    // del test, sobre la fila huérfana que deja la corrida anterior.
});

test('CA-BO-147: plataforma_app no puede leer reason de module_subscriptions, y sí las columnas concedidas', function (): void {
    if (! DB::connection('pgsql_owner')->table('modules')->where('code', 'REQ-TEST-MOD')->exists()) {
        DB::connection('pgsql_owner')->table('modules')->insert(['code' => 'REQ-TEST-MOD', 'name_key' => 'modules.test', 'phase' => '1']);
    }

    $tenant = Tenant::factory()->create();
    $subscriptionId = DB::connection('pgsql_platform')->table('module_subscriptions')->insertGetId([
        'tenant_id' => $tenant->id, 'public_id' => (string) Str::ulid(), 'module_code' => 'REQ-TEST-MOD', 'enabled' => true,
        'enabled_at' => now(), 'reason' => 'Motivo interno del proveedor',
    ]);

    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    expectPgsqlQueryToThrow(fn () => DB::table('module_subscriptions')->select('reason')->where('id', $subscriptionId)->first());

    $row = DB::table('module_subscriptions')
        ->select(ModuleSubscription::TENANT_VISIBLE_COLUMNS)
        ->where('id', $subscriptionId)
        ->first();

    $context->leave();

    expect($row)->not->toBeNull()
        ->and($row->enabled)->toBeTrue();

    DB::connection('pgsql_platform')->table('module_subscriptions')->where('id', $subscriptionId)->delete();
});
