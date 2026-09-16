<?php

use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Modules\SyncModuleRegistry;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

// ADR-034 §2, §5, §7 (0.8.11). Sin módulos reales todavía (app/Modules/
// vacío hasta 1.1): se ejercita con un ServiceProvider de prueba definido
// aquí mismo, igual que TenantModelProbe en TenantModelTest.php — sin
// pasar por el escaneo de ficheros de ModuleServiceProviderDiscovery
// (eso ya lo cubren sus propios tests de 0.4), solo la lógica de sync.

class SyncRegistryFixtureProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return ['code' => 'sync-registry-test', 'name_key' => 'modules.sync_registry_test', 'phase' => '0'];
    }

    public function declaredPermissions(): array
    {
        return [
            ['code' => 'sync-registry-test.ver', 'resource' => 'sync-registry-test', 'action' => 'ver'],
        ];
    }
}

// 1.6c, RN-BO-64, CA-BO-034, CA-BO-035, CA-BO-129: fixtures del grafo de
// `depends_on`.

class SyncRegistryDependsOnMissingProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return [
            'code' => 'sync-registry-missing-dep',
            'name_key' => 'modules.sync_registry_test',
            'phase' => '0',
            'depends_on' => ['sync-registry-no-existe'],
        ];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

class SyncRegistryCycleAProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return [
            'code' => 'sync-registry-cycle-a',
            'name_key' => 'modules.sync_registry_test',
            'phase' => '0',
            'depends_on' => ['sync-registry-cycle-b'],
        ];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

class SyncRegistryCycleBProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return [
            'code' => 'sync-registry-cycle-b',
            'name_key' => 'modules.sync_registry_test',
            'phase' => '0',
            'depends_on' => ['sync-registry-cycle-a'],
        ];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

class SyncRegistryEssentialBadDependencyProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return [
            'code' => 'sync-registry-essential-bad',
            'name_key' => 'modules.sync_registry_test',
            'phase' => '0',
            'depends_on' => ['sync-registry-test'],
            'essential' => true,
        ];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('sincroniza el módulo y el permiso declarados', function (): void {
    SyncModuleRegistry::run([SyncRegistryFixtureProvider::class]);

    $module = DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-test')->first();
    $permission = DB::connection('pgsql_owner')->table('permissions')->where('code', 'sync-registry-test.ver')->first();

    expect($module)->not->toBeNull()
        ->and($module->retired_at)->toBeNull()
        ->and($permission)->not->toBeNull()
        ->and($permission->retired_at)->toBeNull()
        ->and($permission->module_code)->toBe('sync-registry-test');
});

test('dos ejecuciones seguidas con el mismo código no producen cambios', function (): void {
    SyncModuleRegistry::run([SyncRegistryFixtureProvider::class]);

    $before = (array) DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-test')->first();

    SyncModuleRegistry::run([SyncRegistryFixtureProvider::class]);

    $after = (array) DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-test')->first();

    expect($after)->toBe($before);
});

test('retirar un módulo del código marca retired_at y conserva las suscripciones', function (): void {
    SyncModuleRegistry::run([SyncRegistryFixtureProvider::class]);

    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    // `pgsql_platform` (1.6c, datos.md §7): el `REVOKE INSERT` de la
    // migración de privilegios le quita a `plataforma_app` la capacidad
    // de insertar en `module_subscriptions`. `tenant_id` a mano: la GUC
    // `app.current_tenant_id()` que rellenaba la columna por `DEFAULT`
    // sólo está fijada sobre la conexión `pgsql`, nunca sobre
    // `pgsql_platform` (`TenantContext::applyToConnection()`).
    DB::connection('pgsql_platform')->table('module_subscriptions')->insert([
        'tenant_id' => $tenant->id, 'public_id' => (string) Str::ulid(), 'module_code' => 'sync-registry-test', 'enabled' => true,
    ]);

    $context->leave();

    // Se sincroniza de nuevo sin ningún ServiceProvider: el módulo
    // "desaparece del código".
    SyncModuleRegistry::run([]);

    $module = DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-test')->first();
    $permission = DB::connection('pgsql_owner')->table('permissions')->where('code', 'sync-registry-test.ver')->first();

    // `plataforma_platform` conserva `SELECT` de tabla completa
    // (`datos.md §7`): no necesita la proyección explícita que sí exige
    // `plataforma_app` tras el `REVOKE SELECT`.
    $stillReferenced = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'sync-registry-test')->exists();

    // La fila ahora vive en `pgsql_platform`, comprometida de verdad
    // (fuera de la transacción `pgsql` que `DatabaseTransactions`
    // revierte tras el test): se limpia explícitamente, a diferencia de
    // antes de `1.6c`.
    DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'sync-registry-test')->delete();

    // Re-sincroniza para dejar el catálogo como lo encontró el siguiente
    // test de este fichero (mismo espíritu que TenantModelTest: cada test
    // limpia lo que compromete de verdad por una conexión no
    // transaccional).
    SyncModuleRegistry::run([SyncRegistryFixtureProvider::class]);

    expect($module->retired_at)->not->toBeNull()
        ->and($permission->retired_at)->not->toBeNull()
        ->and($stillReferenced)->toBeTrue();
});

// 1.6c, RN-BO-64: las tres validaciones de depends_on.

test('CA-BO-034: platform:sync-registry aborta si depends_on referencia un código inexistente y no escribe nada', function (): void {
    expect(fn () => SyncModuleRegistry::run([SyncRegistryDependsOnMissingProvider::class]))
        ->toThrow(InvalidArgumentException::class);

    expect(DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-missing-dep')->exists())->toBeFalse();
});

test('CA-BO-035: platform:sync-registry aborta ante un ciclo en depends_on y nombra el ciclo', function (): void {
    expect(fn () => SyncModuleRegistry::run([SyncRegistryCycleAProvider::class, SyncRegistryCycleBProvider::class]))
        ->toThrow(InvalidArgumentException::class, 'ciclo');

    expect(DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-cycle-a')->exists())->toBeFalse()
        ->and(DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-cycle-b')->exists())->toBeFalse();
});

test('CA-BO-129: platform:sync-registry aborta si un módulo esencial depende de uno no esencial y no escribe nada', function (): void {
    expect(fn () => SyncModuleRegistry::run([SyncRegistryFixtureProvider::class, SyncRegistryEssentialBadDependencyProvider::class]))
        ->toThrow(InvalidArgumentException::class);

    expect(DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-essential-bad')->exists())->toBeFalse();
});
