<?php

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

test('ausencia de fila en module_subscriptions se lee como módulo desactivado', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $enabled = DB::table('module_subscriptions')
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

    $context->enter($tenantA->id);
    DB::table('module_subscriptions')->insert([
        'public_id' => (string) Str::ulid(), 'module_code' => 'REQ-TEST-MOD', 'enabled' => true,
    ]);
    $visibleEnB = DB::table('module_subscriptions')->where('module_code', 'REQ-TEST-MOD')->exists();
    $context->leave();

    $context->enter($tenantB->id);
    $visibleForB = DB::table('module_subscriptions')->where('module_code', 'REQ-TEST-MOD')->exists();
    $context->leave();

    expect($visibleEnB)->toBeTrue()
        ->and($visibleForB)->toBeFalse();

    // Sin DELETE de `modules` aquí a propósito (bug 3 de 0.7, ver
    // docs/historial/0.7-nucleo-multitenant.md): la fila de
    // module_subscriptions que la referencia por FK vive en la conexión
    // `pgsql`, todavía sin comprometer (DatabaseTransactions la revierte
    // después de este test) — borrar el padre desde `pgsql_owner` mientras
    // tanto se queda esperando el lock de esa transacción abierta. El
    // fixture 'REQ-TEST-MOD' se queda registrado entre corridas, igual que
    // las tablas de prueba de TenantMigrationTest.
});
