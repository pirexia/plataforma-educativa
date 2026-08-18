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

    DB::table('module_subscriptions')->insert([
        'public_id' => (string) Str::ulid(), 'module_code' => 'sync-registry-test', 'enabled' => true,
    ]);

    $context->leave();

    // Se sincroniza de nuevo sin ningún ServiceProvider: el módulo
    // "desaparece del código".
    SyncModuleRegistry::run([]);

    $module = DB::connection('pgsql_owner')->table('modules')->where('code', 'sync-registry-test')->first();
    $permission = DB::connection('pgsql_owner')->table('permissions')->where('code', 'sync-registry-test.ver')->first();

    // Por la misma conexión con la que se insertó (pgsql), dentro del
    // mismo contexto de tenant: la fila vive sin comprometer todavía en
    // esa transacción (DatabaseTransactions), invisible para pgsql_platform.
    $context->enter($tenant->id);
    $stillReferenced = DB::table('module_subscriptions')->where('module_code', 'sync-registry-test')->exists();
    $context->leave();

    // Re-sincroniza para dejar el catálogo como lo encontró el siguiente
    // test de este fichero (mismo espíritu que TenantModelTest: cada test
    // limpia lo que compromete de verdad por una conexión no
    // transaccional).
    SyncModuleRegistry::run([SyncRegistryFixtureProvider::class]);

    expect($module->retired_at)->not->toBeNull()
        ->and($permission->retired_at)->not->toBeNull()
        ->and($stillReferenced)->toBeTrue();
});
