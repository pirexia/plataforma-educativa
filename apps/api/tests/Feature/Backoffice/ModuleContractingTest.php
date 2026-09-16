<?php

use App\Modules\Backoffice\Application\ModuleSubscriptionsService;
use App\Modules\Backoffice\Domain\DualAuthorizationStatus;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformIdempotencyKey;
use App\Modules\Backoffice\Infrastructure\Jobs\PurgePlatformIdempotencyKeys;
use App\Modules\Core\Domain\Events\ModuleContracted;
use App\Modules\Core\Domain\Events\ModuleDecontracted;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Modules\Core\Domain\ModuleChange;
use App\Support\Api\ApiException;
use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * REQ-BO-002 (1.6c). RN-BO-63 a RN-BO-82, CA-BO-128 a CA-BO-148.
 * `boPlatformHost()`, `boAllowCurrentTestIp()`, `boCreateEnrolledAdmin()`,
 * `boSensitiveClient()` están definidas en PlatformAdminManagementTest.php
 * y TenantLifecycleTest.php.
 *
 * Dos módulos de prueba, registrados en el contenedor (no por escaneo de
 * ficheros, que `platform:sync-registry` no vería) para que
 * `DeclaredModuleCatalog` los resuelva con `depends_on` de verdad:
 * `bo-test-base` (sin dependencias) y `bo-test-dep` (depende de
 * `bo-test-base`). `bo-test-dep-core` añade además `core` a
 * `depends_on`, para CA-BO-131 (un esencial no entra en el cierre).
 */
class ModuleFixtureBaseProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return ['code' => 'bo-test-base', 'name_key' => 'modules.sync_registry_test', 'phase' => '0'];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

class ModuleFixtureDependentProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return ['code' => 'bo-test-dep', 'name_key' => 'modules.sync_registry_test', 'phase' => '0', 'depends_on' => ['bo-test-base']];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

class ModuleFixtureDependentWithCoreProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return ['code' => 'bo-test-dep-core', 'name_key' => 'modules.sync_registry_test', 'phase' => '0', 'depends_on' => ['core', 'bo-test-base']];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

/**
 * CA-BO-140: listener de prueba real (no un doble), en cola, para probar
 * la garantía de infraestructura sobre `ModuleContracted` sin inventar un
 * caso de uso de negocio que todavía no existe (`ModuleContracted`/
 * `ModuleDecontracted` no tienen consumidor real en `1.6c`). `Event::fake()`
 * no sirve para esto: sustituye el despachador entero y nunca llega a
 * encolar nada de verdad. `$connection = 'database'` (no el `sync` de
 * `phpunit.xml`) es lo que hace que este listener aterrice en la tabla
 * `jobs` en vez de ejecutarse en el mismo hilo que el evento.
 */
class ModuleContractedFailingTestListener implements ShouldQueue
{
    public $connection = 'database';

    public $queue = 'default';

    public $tries = 1;

    public function handle(ModuleContracted $event): void
    {
        throw new RuntimeException('CA-BO-140: fallo deliberado del listener de prueba');
    }
}

/** @var list<string> */
const MODULE_FIXTURE_CODES = ['bo-test-base', 'bo-test-dep', 'bo-test-dep-core'];

function registerModuleFixtures(): void
{
    foreach (MODULE_FIXTURE_CODES as $code) {
        DB::connection('pgsql_owner')->table('modules')->updateOrInsert(
            ['code' => $code],
            ['name_key' => 'modules.sync_registry_test', 'phase' => '0', 'retired_at' => null],
        );
    }

    app()->register(new ModuleFixtureBaseProvider(app()));
    app()->register(new ModuleFixtureDependentProvider(app()));
    app()->register(new ModuleFixtureDependentWithCoreProvider(app()));
}

/**
 * @return array{0: Tenant, 1: PlatformAdmin, 2: string}
 */
function boModuleTestSetup(string $role = 'operaciones'): array
{
    $tenant = Tenant::factory()->create();
    [$admin, $secret] = boCreateEnrolledAdmin($role);

    return [$tenant, $admin, $secret];
}

beforeEach(function (): void {
    Mail::fake();
    $this->artisan('platform:sync-registry')->run();
    boAllowCurrentTestIp();
    registerModuleFixtures();
});

afterEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('dual_authorizations')->delete();
    DB::connection('pgsql_platform')->table('module_subscriptions')
        ->whereIn('module_code', [...MODULE_FIXTURE_CODES, 'core', 'auth'])
        ->delete();
    DB::connection('pgsql_platform')->table('platform_admin_sessions')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_challenges')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_recovery_codes')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_invitations')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
    DB::connection('pgsql_platform')->table('tenants')->delete();
    // CA-BO-140, issue #221: limpieza de lo que dejan el listener de
    // prueba en cola y la purga de claves de idempotencia de plataforma.
    DB::table('jobs')->delete();
    DB::connection('pgsql_platform')->table('failed_jobs')->delete();
    DB::connection('pgsql_platform')->table('platform_idempotency_keys')->delete();
    Cache::flush();
});

// ---------------------------------------------------------------------
// Catálogo (CA-BO-128, CA-BO-129 en SyncModuleRegistryTest.php)
// ---------------------------------------------------------------------

test('CA-BO-128: el catálogo de descriptores se resuelve una sola vez por proceso', function (): void {
    $catalog = app(ModuleCatalog::class);

    expect($catalog)->toBe(app(ModuleCatalog::class));
    expect($catalog->find('bo-test-dep')?->dependsOn)->toBe(['bo-test-base']);
    expect($catalog->find('core')?->essential)->toBeTrue();
    expect($catalog->dependenciesOf('bo-test-dep'))->toBe(['bo-test-base']);
    expect($catalog->dependentsOf('bo-test-base'))->toContain('bo-test-dep');
});

// ---------------------------------------------------------------------
// Cierre de dependencias (CA-BO-039, CA-BO-040, CA-BO-131, CA-BO-133)
// ---------------------------------------------------------------------

test('CA-BO-039: contratar un módulo con dependencia sin contratar arrastra la dependencia en la misma transacción', function (): void {
    [$tenant, $admin] = boModuleTestSetup();

    $response = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-dep",
        ['enabled' => true, 'reason' => 'Alta de prueba', 'cascade' => true],
    );

    $response->assertStatus(200);
    $applied = collect($response->json('data.applied'))->keyBy('code');

    expect($applied->has('bo-test-dep'))->toBeTrue()
        ->and($applied->has('bo-test-base'))->toBeTrue()
        ->and($applied->get('bo-test-dep')['cascaded'])->toBeFalse()
        ->and($applied->get('bo-test-base')['cascaded'])->toBeTrue();

    $subscribed = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->pluck('enabled', 'module_code');

    expect((bool) $subscribed['bo-test-dep'])->toBeTrue()
        ->and((bool) $subscribed['bo-test-base'])->toBeTrue();
});

test('CA-BO-040: descontratar una dependencia de módulos contratados sin cascade responde 409 con los arrastrados', function (): void {
    [$tenant, $admin, $secret] = boModuleTestSetup();
    $this->actingAs($admin, 'platform');

    app(ModuleSubscriptionsService::class)->applyChange($tenant, new ModuleChange('bo-test-dep', true, 'Alta previa', true));

    $client = boSensitiveClient($admin, $secret);

    $response = $client->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => false, 'reason' => 'Baja de prueba'],
    );

    $response->assertStatus(409);
    expect($response->json('detail'))->not->toBeNull();

    $stillEnabled = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'bo-test-base')->value('enabled');

    expect((bool) $stillEnabled)->toBeTrue();
});

test('CA-BO-041, CA-BO-130: un módulo esencial no se conmuta en ninguna dirección', function (): void {
    [$tenant, $admin, $secret] = boModuleTestSetup();

    $contract = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/core",
        ['enabled' => true, 'reason' => 'Intento'],
    );
    $contract->assertStatus(422);
    expect($contract->json('errors.module_code.0.code'))->toBe('bo.module.essential');

    // Descontratar también exige reautenticación (OPEN-BO-17), que se
    // comprueba ANTES del bloqueo de esencial (api.md §5.8.4) — sin
    // ella, el 403 de reautenticación taparía el 422 que este test
    // quiere comprobar.
    $client = boSensitiveClient($admin, $secret);

    $decontract = $client->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/core",
        ['enabled' => false, 'reason' => 'Intento de descontratar el núcleo'],
    );
    $decontract->assertStatus(422);
    expect($decontract->json('errors.module_code.0.code'))->toBe('bo.module.essential');
});

test('CA-BO-131: un esencial dentro de depends_on no entra en el cierre de dependencias', function (): void {
    [$tenant, $admin] = boModuleTestSetup();

    $response = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-dep-core",
        ['enabled' => true, 'reason' => 'Alta de prueba', 'cascade' => true],
    );

    $response->assertStatus(200);
    $applied = collect($response->json('data.applied'))->pluck('code');

    expect($applied)->toHaveCount(2)
        ->and($applied)->toContain('bo-test-dep-core')
        ->and($applied)->toContain('bo-test-base')
        ->and($applied)->not->toContain('core');
});

test('CA-BO-133: arrastrar sin cascade es 409 y no escribe nada; con cascade aplica exactamente lo anunciado', function (): void {
    [$tenant, $admin] = boModuleTestSetup();

    $blocked = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-dep",
        ['enabled' => true, 'reason' => 'Sin cascade'],
    );

    $blocked->assertStatus(409);
    expect(DB::connection('pgsql_platform')->table('module_subscriptions')->where('tenant_id', $tenant->id)->count())->toBe(0);

    $applied = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-dep",
        ['enabled' => true, 'reason' => 'Con cascade', 'cascade' => true],
    );

    $applied->assertStatus(200);
    expect(DB::connection('pgsql_platform')->table('module_subscriptions')->where('tenant_id', $tenant->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------
// Concurrencia (CA-BO-132, RN-BO-66)
// ---------------------------------------------------------------------

test('CA-BO-132: contratar M (que depende de N) y descontratar N en paralelo nunca deja M sin N', function (): void {
    [$tenant, $admin] = boModuleTestSetup();
    $this->actingAs($admin, 'platform');
    $service = app(ModuleSubscriptionsService::class);

    // N ya contratado de partida.
    $service->applyChange($tenant, new ModuleChange('bo-test-base', true, 'Alta previa de N'));

    // Dos copias independientes del mismo tenant — misma técnica que
    // TenantLifecycleTest.php para el issue #207: el bloqueo real lo da
    // `lockForUpdate()` dentro de `ModuleContractingService::apply()`
    // sobre la fila de `tenants`, así que la SEGUNDA llamada recalcula
    // siempre sobre el estado ya escrito por la primera, nunca sobre su
    // copia en memoria obsoleta.
    $staleForA = Tenant::query()->findOrFail($tenant->id);
    $staleForB = Tenant::query()->findOrFail($tenant->id);

    // A contrata M (bo-test-dep), que depende de N (ya contratado: no
    // arrastra nada).
    $service->applyChange($staleForA, new ModuleChange('bo-test-dep', true, 'Contratar M'));

    // B descontrata N. Si el cierre se recalculara sobre una lectura
    // obsoleta (sin ver que M ya depende de N), esto se aplicaría sin
    // arrastrar nada y dejaría M contratado sin N — exactamente lo que
    // RN-BO-22/66 prohíben. Al recalcular sobre la lectura bloqueada
    // (que ya ve M contratado), responde 409 sin escribir nada.
    expect(fn () => $service->applyChange($staleForB, new ModuleChange('bo-test-base', false, 'Descontratar N')))
        ->toThrow(ApiException::class);

    $subscribed = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->pluck('enabled', 'module_code');

    expect((bool) $subscribed['bo-test-dep'])->toBeTrue()
        ->and((bool) $subscribed['bo-test-base'])->toBeTrue();
});

// ---------------------------------------------------------------------
// Idempotencia (CA-BO-044, CA-BO-134)
// ---------------------------------------------------------------------

test('CA-BO-134: recontratar un módulo ya contratado con otro motivo es no-operación completa', function (): void {
    [$tenant, $admin] = boModuleTestSetup();
    $this->actingAs($admin, 'platform');
    $service = app(ModuleSubscriptionsService::class);

    $service->applyChange($tenant, new ModuleChange('bo-test-base', true, 'Motivo original'));
    $before = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'bo-test-base')->first();

    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');

    $response = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => true, 'reason' => 'Motivo distinto'],
    );

    $response->assertStatus(200);
    expect($response->json('data.applied'))->toBe([])
        ->and($response->json('data.unchanged'))->toBe(['bo-test-base']);

    $after = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'bo-test-base')->first();

    expect($after->reason)->toBe($before->reason)
        ->and($after->enabled_at)->toBe($before->enabled_at)
        ->and(AdminActionLog::query()->where('affected_tenant_id', $tenant->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------
// Retirado y estados de tenant (CA-BO-135, CA-BO-136)
// ---------------------------------------------------------------------

test('CA-BO-135: un módulo retirado no se contrata, y sí se descontrata', function (): void {
    [$tenant, $admin, $secret] = boModuleTestSetup();
    $this->actingAs($admin, 'platform');
    $service = app(ModuleSubscriptionsService::class);

    $service->applyChange($tenant, new ModuleChange('bo-test-base', true, 'Alta previa'));
    // `pgsql_owner`: `modules` revoca INSERT/UPDATE/DELETE a los dos
    // roles de aplicación (datos.md §8, 2026_08_18_100600), sólo el
    // propietario puede tocarla — mismo motivo que `platform:sync-registry`
    // corre por esa conexión.
    DB::connection('pgsql_owner')->table('modules')->where('code', 'bo-test-base')->update(['retired_at' => now()]);

    $contract = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => true, 'reason' => 'Recontratar', 'cascade' => true],
    );
    $contract->assertStatus(200);
    expect($contract->json('data.unchanged'))->toBe(['bo-test-base']);

    $client = boSensitiveClient($admin, $secret);

    $decontract = $client->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => false, 'reason' => 'Cerrar suscripción a lo retirado'],
    );
    $decontract->assertStatus(200);

    DB::connection('pgsql_owner')->table('modules')->where('code', 'bo-test-base')->update(['retired_at' => null]);
});

test('CA-BO-136: los cinco estados de tenant frente a la escritura de módulos', function (): void {
    [, $admin] = boModuleTestSetup();

    $expectations = [
        TenantStatus::Activo->value => 200,
        TenantStatus::Suspendido->value => 200,
        TenantStatus::EnBaja->value => 200,
        TenantStatus::EnAlta->value => 409,
        TenantStatus::Eliminado->value => 409,
    ];

    foreach ($expectations as $status => $expectedStatus) {
        $tenant = Tenant::factory()->create(['status' => $status]);

        $response = $this->actingAs($admin, 'platform')->putJson(
            'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
            ['enabled' => true, 'reason' => "Prueba de estado {$status}"],
        );

        expect($response->getStatusCode())->toBe($expectedStatus, "estado {$status} esperaba {$expectedStatus}, obtuvo {$response->getStatusCode()}");

        if ($expectedStatus === 409) {
            expect($response->json('errors') ?? $response->json('detail'))->not->toBeNull();
        }
    }
});

// ---------------------------------------------------------------------
// Autoría, auditoría de plataforma y caché (CA-BO-137, CA-BO-138, CA-BO-139)
// ---------------------------------------------------------------------

test('CA-BO-137, CA-BO-138: la contratación no escribe created_by/updated_by, no deja audit_logs y sí admin_action_logs', function (): void {
    [$tenant, $admin] = boModuleTestSetup();

    $response = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => true, 'reason' => 'Alta comercial de prueba'],
    );

    $response->assertStatus(200);

    $row = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'bo-test-base')->first();

    expect($row->created_by)->toBeNull()
        ->and($row->updated_by)->toBeNull();

    $auditLogCount = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => DB::table('audit_logs')->where('auditable_type', 'module_subscription')->count(),
    );
    expect($auditLogCount)->toBe(0);

    $entry = AdminActionLog::query()->where('affected_tenant_id', $tenant->id)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->action->value)->toBe('modulo.contratado');
});

test('CA-BO-139: si falla la invalidación de caché, la petición responde con éxito igualmente', function (): void {
    [$tenant, $admin] = boModuleTestSetup();

    // `shouldReceive()` pone toda la fachada en modo mock estricto:
    // `afterEach()` también llama a `Cache::flush()`, así que hay que
    // dejarlo pasar aquí para no reventar la limpieza del test.
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('Redis no disponible'));
    Cache::shouldReceive('flush')->andReturn(true);

    $response = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => true, 'reason' => 'Alta con caché caída'],
    );

    $response->assertStatus(200);
    expect($response->json('data.cache_invalidated'))->toBeFalse();

    $row = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'bo-test-base')->first();
    expect((bool) $row->enabled)->toBeTrue();
});

// ---------------------------------------------------------------------
// Eventos (CA-BO-037, CA-BO-038, CA-BO-042, CA-BO-140)
// ---------------------------------------------------------------------

// Hallazgo doc-reviewer (1.6c, sin issue propio): funcional.md §13.3.1
// afirmaba cobertura de los 21 criterios de aceptación, pero CA-BO-140 no
// tenía ningún test. `ModuleContracted`/`ModuleDecontracted` no tienen
// consumidor real todavía, así que la garantía que se prueba aquí es de
// infraestructura: CUALQUIER listener real que se registre sobre estos
// eventos (a) va en cola, nunca en el mismo hilo que la petición HTTP que
// disparó el evento, y (b) si ese listener falla, el fallo aterriza en
// `failed_jobs`, nunca como error de la respuesta de quien contrató el
// módulo.
test('CA-BO-140: un listener en cola de ModuleContracted no se ejecuta en el hilo del evento, y su fallo va a failed_jobs, no a la respuesta HTTP', function (): void {
    config(['queue.default' => 'database']);
    // `queue.failed.database` es `pgsql` por defecto (`env('DB_CONNECTION')`,
    // `plataforma_app`), que sólo tiene INSERT sobre `failed_jobs` desde
    // `harden_failed_jobs_grants` (SELECT/UPDATE/DELETE revocados
    // deliberadamente, `ADR-033 §7`): el propio worker puede escribir su
    // fallo, pero leerlo de vuelta en el mismo hilo de test — a través de
    // la MISMA transacción de `DatabaseTransactions`, que nunca hace
    // `COMMIT` — no lo vería ninguna conexión distinta hasta que la
    // transacción se cerrara, y `pgsql` no tiene privilegio para leerlo
    // ni aun así. Se apunta aquí a `pgsql_platform` (BYPASSRLS, con
    // privilegio completo) sólo para poder comprobar el resultado: la
    // garantía que CA-BO-140 exige —el fallo no llega a la respuesta
    // HTTP— no depende de qué conexión registre `failed_jobs` en
    // producción, sólo de que el listener esté en cola.
    config(['queue.failed.database' => 'pgsql_platform']);
    Event::listen(ModuleContracted::class, ModuleContractedFailingTestListener::class);

    [$tenant, $admin] = boModuleTestSetup();

    // La petición HTTP que dispara ModuleContracted responde 200: el
    // listener todavía no ha corrido ni una vez cuando esto se comprueba.
    $response = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => true, 'reason' => 'CA-BO-140: prueba de listener en cola'],
    );
    $response->assertStatus(200);

    // El listener aterrizó en la tabla de trabajos en cola, no se ejecutó
    // en el hilo de la petición (si lo hubiera hecho, la excepción que
    // lanza habría quedado sin capturar y la respuesta no sería 200).
    $queued = DB::table('jobs')->latest('id')->first();
    expect($queued)->not->toBeNull();
    expect($queued->payload)->toContain(ModuleContractedFailingTestListener::class);

    // Ahora se ejecuta de verdad, con un único intento: falla como el
    // listener de prueba está diseñado para hacer.
    Artisan::call('queue:work', [
        'connection' => 'database', '--once' => true, '--queue' => 'default', '--tries' => 1,
    ]);

    expect(DB::table('jobs')->count())->toBe(0);

    $failed = DB::connection('pgsql_platform')->table('failed_jobs')->latest('id')->first();
    expect($failed)->not->toBeNull();
    expect($failed->payload)->toContain(ModuleContractedFailingTestListener::class);
    expect($failed->exception)->toContain('CA-BO-140: fallo deliberado del listener de prueba');

    DB::connection('pgsql_platform')->table('failed_jobs')->where('id', $failed->id)->delete();
});

test('CA-BO-037, CA-BO-038: contratar y descontratar emiten ModuleContracted/ModuleDecontracted, uno por módulo', function (): void {
    [$tenant, $admin, $secret] = boModuleTestSetup();

    Event::fake([ModuleContracted::class, ModuleDecontracted::class]);

    $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => true, 'reason' => 'Alta'],
    )->assertStatus(200);

    Event::assertDispatched(ModuleContracted::class, fn ($e) => $e->tenantId === $tenant->id && $e->moduleCode === 'bo-test-base');

    $client = boSensitiveClient($admin, $secret);

    $client->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => false, 'reason' => 'Baja'],
    )->assertStatus(200);

    // El evento sale aunque el módulo quede apagado (RMOD-010).
    Event::assertDispatched(ModuleDecontracted::class, fn ($e) => $e->tenantId === $tenant->id && $e->moduleCode === 'bo-test-base');
});

test('CA-BO-042: una activación masiva sobre tres centros emite tres eventos, uno por centro', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $tenants = [Tenant::factory()->create(), Tenant::factory()->create(), Tenant::factory()->create()];

    Event::fake([ModuleContracted::class]);

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => true,
        'reason' => 'Despliegue de prueba',
        'tenant_public_ids' => array_map(fn (Tenant $t) => $t->public_id, $tenants),
    ], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertStatus(202);

    Event::assertDispatchedTimes(ModuleContracted::class, 3);
});

test('CA-BO-043: reintentar la masiva con la misma Idempotency-Key no contrata dos veces ni duplica eventos', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);
    $tenant = Tenant::factory()->create();
    $key = (string) Str::ulid();

    $body = [
        'module_code' => 'bo-test-base',
        'enabled' => true,
        'reason' => 'Despliegue de prueba',
        'tenant_public_ids' => [$tenant->public_id],
    ];

    $first = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', $body, ['Idempotency-Key' => $key]);
    $first->assertStatus(202);

    $countAfterFirst = AdminActionLog::query()->where('action', 'modulo.masivo_ejecutado')->count();

    $second = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', $body, ['Idempotency-Key' => $key]);
    $second->assertStatus(202);
    expect($second->headers->get('Idempotency-Replayed'))->toBe('true');

    $countAfterSecond = AdminActionLog::query()->where('action', 'modulo.masivo_ejecutado')->count();
    expect($countAfterSecond)->toBe($countAfterFirst);
});

// ---------------------------------------------------------------------
// La masiva: fallo parcial (CA-BO-141)
// ---------------------------------------------------------------------

test('CA-BO-141: un lote de tres centros con el segundo en en_alta lo omite y sigue con el resto', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create(['status' => TenantStatus::EnAlta]);
    $third = Tenant::factory()->create();

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => true,
        'reason' => 'Despliegue de prueba',
        'tenant_public_ids' => [$first->public_id, $second->public_id, $third->public_id],
    ], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertStatus(202);

    expect(AdminActionLog::query()->where('affected_tenant_id', $first->id)->exists())->toBeTrue()
        ->and(AdminActionLog::query()->where('affected_tenant_id', $second->id)->exists())->toBeFalse()
        ->and(AdminActionLog::query()->where('affected_tenant_id', $third->id)->exists())->toBeTrue();

    $summary = AdminActionLog::query()->where('action', 'modulo.masivo_ejecutado')->whereNull('affected_tenant_id')->latest('id')->first();
    expect($summary)->not->toBeNull();
    expect($summary->context['requested'])->toBe(3);
    expect($summary->context['applied'])->toBe(2);
    expect($summary->context['omitted'])->toHaveCount(1);
    expect($summary->context['omitted'][0]['tenant_public_id'])->toBe($second->public_id);
});

// Hallazgo doc-reviewer (1.6c, sin issue propio): CA-BO-142 no tenía
// ningún test — a diferencia de CA-BO-141 (arriba), que sólo comprueba
// `admin_action_logs`, esta prueba lee `module_subscriptions` directamente
// para el primer y el tercer centro. `RunModuleRollout::handle()` llama a
// `ModuleSubscriptionsService::applyChange()` una vez por centro dentro
// del bucle, y cada llamada abre y cierra su PROPIA
// `DB::connection('pgsql_platform')->transaction()` (RN-BO-78): para
// cuando el segundo centro falla (en_alta, no admite escritura), la
// transacción del primero ya hizo COMMIT. Si el lote entero viviera
// dentro de una única transacción compartida, el fallo del segundo
// centro revertiría también la fila ya escrita del primero.
test('CA-BO-142: la activación masiva es una transacción por centro, no una única para todo el lote', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create(['status' => TenantStatus::EnAlta]);
    $third = Tenant::factory()->create();

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => true,
        'reason' => 'CA-BO-142: prueba de independencia transaccional',
        'tenant_public_ids' => [$first->public_id, $second->public_id, $third->public_id],
    ], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertStatus(202);

    $firstSubscription = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $first->id)->where('module_code', 'bo-test-base')->first();
    $secondSubscription = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $second->id)->where('module_code', 'bo-test-base')->first();
    $thirdSubscription = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $third->id)->where('module_code', 'bo-test-base')->first();

    expect($firstSubscription)->not->toBeNull()
        ->and((bool) $firstSubscription->enabled)->toBeTrue()
        ->and($secondSubscription)->toBeNull()
        ->and($thirdSubscription)->not->toBeNull()
        ->and((bool) $thirdSubscription->enabled)->toBeTrue();
});

// ---------------------------------------------------------------------
// Descontratación masiva y doble autorización (CA-BO-066, CA-BO-143, CA-BO-144, CA-BO-145)
// ---------------------------------------------------------------------

test('CA-BO-143, CA-BO-066: la descontratación masiva responde 202 con la dual_authorization pendiente y no cambia nada hasta aprobarse', function (): void {
    [$requester, $requesterSecret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($requester, 'platform');
    $requesterClient = boSensitiveClient($requester, $requesterSecret);

    $tenant = Tenant::factory()->create();
    app(ModuleSubscriptionsService::class)->applyChange($tenant, new ModuleChange('bo-test-base', true, 'Alta previa'));

    $response = $requesterClient->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => false,
        'reason' => 'Fin del piloto',
        'tenant_public_ids' => [$tenant->public_id],
    ], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertStatus(202);
    expect($response->json('data.type'))->toBe('dual_authorization');
    expect($response->json('data.status'))->toBe('pendiente');

    $authPublicId = $response->json('data.public_id');

    expect(DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'bo-test-base')->value('enabled'))->toBeTruthy();

    // Segundo administrador, distinto del solicitante (RN-BO-19).
    [$approver, $approverSecret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($approver, 'platform');
    $approverClient = boSensitiveClient($approver, $approverSecret);

    $approval = $approverClient->postJson(
        'http://'.boPlatformHost()."/api/platform/v1/dual-authorizations/{$authPublicId}/approval",
        [],
    );

    $approval->assertStatus(200);
    expect($approval->json('status'))->toBe('ejecutada');

    $enabledAfter = DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'bo-test-base')->value('enabled');

    expect((bool) $enabledAfter)->toBeFalse();
});

// Issue #224 (Alta, /codex:review), #227 (Media, falta de test de
// regresión, db-reviewer/security-reviewer): ModuleSubscriptionsService::
// executeApprovedBulkDecontract() corre dentro de la transacción de
// DualAuthorizationService::execute(). Antes del arreglo, encolaba
// RunModuleRollout de inmediato; con QUEUE_CONNECTION=sync eso ejecutaba
// el lote en el sitio, incluso si algo posterior en la misma transacción
// (el forceFill(...)->save() o el record() de "ejecutada") lanzaba y
// revertía. Reproduce exactamente esa forma: llama al método real dentro
// de una transacción que después lanza a propósito, sin pasar por el
// execute() privado (no se puede invocar desde fuera), y comprueba que
// el lote no se aplicó.
test('issue #224: si algo lanza en la misma transacción después de encolar el lote, el lote no se aplica', function (): void {
    [$requester, $requesterSecret] = boCreateEnrolledAdmin('operaciones');
    [$approver] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($requester, 'platform');
    $requesterClient = boSensitiveClient($requester, $requesterSecret);

    $tenant = Tenant::factory()->create();
    app(ModuleSubscriptionsService::class)->applyChange($tenant, new ModuleChange('bo-test-base', true, 'Alta previa'));

    $response = $requesterClient->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => false,
        'reason' => 'Fin del piloto',
        'tenant_public_ids' => [$tenant->public_id],
    ], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertStatus(202);
    $authPublicId = $response->json('data.public_id');

    // Réplica mínima de lo que approve() deja antes de llamar a
    // execute() (approved_by es lo único que executeApprovedBulkDecontract()
    // necesita de verdad, vía $authorization->approver).
    $authorization = DualAuthorization::query()->where('public_id', $authPublicId)->firstOrFail();
    $authorization->forceFill([
        'status' => DualAuthorizationStatus::Aprobada,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ])->save();
    $authorization->refresh();

    $thrown = null;

    try {
        DB::connection('pgsql_platform')->transaction(function () use ($authorization): void {
            app(ModuleSubscriptionsService::class)->executeApprovedBulkDecontract($authorization);

            // El fallo simulado que motivó #224: algo revienta DESPUÉS
            // de registrar el afterCommit() del lote, dentro de la misma
            // transacción — exactamente donde execute() hace el
            // forceFill(...)->save() y el record() de "ejecutada".
            throw new RuntimeException('fallo simulado tras encolar el lote');
        });
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();

    // La propiedad que exige #224: con QUEUE_CONNECTION=sync, si el
    // afterCommit() se hubiera disparado pese al ROLLBACK, esto ya
    // estaría en false.
    expect((bool) DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $tenant->id)->where('module_code', 'bo-test-base')->value('enabled'))->toBeTrue();
});

// Issue #225 (Media, /codex:review), #227: un tenant que ya está en el
// estado solicitado es una no-operación completa (RN-BO-69) y no debe
// contarse como `applied` en el resumen del lote — se cuenta aparte,
// `unchanged`.
test('issue #225: el resumen de la masiva distingue applied de unchanged', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $alreadyEnabled = Tenant::factory()->create();
    $needsChange = Tenant::factory()->create();

    app(ModuleSubscriptionsService::class)->applyChange($alreadyEnabled, new ModuleChange('bo-test-base', true, 'Alta previa'));

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => true,
        'reason' => 'CA-BO-142: prueba de unchanged vs applied',
        'tenant_public_ids' => [$alreadyEnabled->public_id, $needsChange->public_id],
    ], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertStatus(202);

    $summary = AdminActionLog::query()->where('action', 'modulo.masivo_ejecutado')->whereNull('affected_tenant_id')->latest('id')->first();

    expect($summary)->not->toBeNull();
    expect($summary->context['requested'])->toBe(2);
    expect($summary->context['applied'])->toBe(1);
    expect($summary->context['unchanged'])->toBe(1);
});

test('CA-BO-144: un centro eliminado entre la solicitud y la aprobación se omite, y el resto se aplica', function (): void {
    [$requester, $requesterSecret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($requester, 'platform');
    $requesterClient = boSensitiveClient($requester, $requesterSecret);

    $stays = Tenant::factory()->create();
    $removed = Tenant::factory()->create();

    app(ModuleSubscriptionsService::class)->applyChange($stays, new ModuleChange('bo-test-base', true, 'Alta previa'));
    app(ModuleSubscriptionsService::class)->applyChange($removed, new ModuleChange('bo-test-base', true, 'Alta previa'));

    $response = $requesterClient->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => false,
        'reason' => 'Fin del piloto',
        'tenant_public_ids' => [$stays->public_id, $removed->public_id],
    ], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertStatus(202);
    $authPublicId = $response->json('data.public_id');

    $removed->forceFill(['status' => TenantStatus::Eliminado])->save();
    $removed->delete();

    [$approver, $approverSecret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($approver, 'platform');
    $approverClient = boSensitiveClient($approver, $approverSecret);

    $approverClient->postJson('http://'.boPlatformHost()."/api/platform/v1/dual-authorizations/{$authPublicId}/approval", [])
        ->assertStatus(200);

    expect((bool) DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('tenant_id', $stays->id)->where('module_code', 'bo-test-base')->value('enabled'))->toBeFalse();

    $summary = AdminActionLog::query()->where('action', 'modulo.masivo_ejecutado')->latest('id')->first();
    expect($summary->context['omitted'])->toHaveCount(1);
});

test('CA-BO-145: no existe ningún selector por filtro en el cuerpo de la masiva', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => true,
        'reason' => 'Todos los activos',
        'status' => 'activo',
    ], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertStatus(422);
});

// ---------------------------------------------------------------------
// Lectura del centro (CA-BO-146) y aislamiento (CA-BO-148)
// ---------------------------------------------------------------------

test('CA-BO-146: GET tenants/{id}/modules muestra los tres estados y la incoherencia de dependencias', function (): void {
    [$tenant, $admin] = boModuleTestSetup();
    $service = app(ModuleSubscriptionsService::class);

    // bo-test-dep contratado sin bo-test-base: incoherencia deliberada,
    // simulando que una versión nueva añadió la arista después.
    DB::connection('pgsql_platform')->table('module_subscriptions')->insert([
        'tenant_id' => $tenant->id, 'public_id' => (string) Str::ulid(), 'module_code' => 'bo-test-dep',
        'enabled' => true, 'enabled_at' => now(), 'reason' => 'Contratado antes de depender de bo-test-base',
    ]);

    $response = $this->actingAs($admin, 'platform')->getJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules",
    );

    $response->assertStatus(200);
    $data = collect($response->json('data'))->keyBy('code');

    expect($data->get('core')['state'])->toBe('esencial')
        ->and($data->get('bo-test-dep')['state'])->toBe('contratado')
        ->and($data->get('bo-test-dep')['missing_dependencies'])->toBe(['bo-test-base'])
        ->and($data->get('bo-test-base')['state'])->toBe('no_contratado')
        ->and($data->get('bo-test-base')['dependent_modules'])->toContain('bo-test-dep');
});

test('CA-BO-148: un lote sobre dos centros no altera las suscripciones ni la caché del tercero', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $affectedA = Tenant::factory()->create();
    $untouched = Tenant::factory()->create();
    $affectedC = Tenant::factory()->create();

    // Precarga de la caché de disponibilidad del tenant no afectado, para
    // comprobar que sigue intacta después del lote (CA-BO-033).
    app(TenantContext::class)->runFor($untouched->id, function (): void {
        Cache::put('modules:bo-test-base:enabled', false, 300);
    });

    $client->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base',
        'enabled' => true,
        'reason' => 'Despliegue de prueba',
        'tenant_public_ids' => [$affectedA->public_id, $affectedC->public_id],
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertStatus(202);

    expect(DB::connection('pgsql_platform')->table('module_subscriptions')->where('tenant_id', $untouched->id)->count())->toBe(0);

    $cachedForUntouched = app(TenantContext::class)->runFor(
        $untouched->id,
        fn () => Cache::get('modules:bo-test-base:enabled'),
    );
    expect($cachedForUntouched)->toBeFalse();
});

// ---------------------------------------------------------------------
// Reautenticación (OPEN-BO-17)
// ---------------------------------------------------------------------

test('OPEN-BO-17: descontratar exige reautenticación viva, contratar no', function (): void {
    [$tenant, $admin] = boModuleTestSetup();

    // Contratar: sin reautenticación, funciona.
    $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => true, 'reason' => 'Alta sin reautenticación'],
    )->assertStatus(200);

    // Descontratar: sin reautenticación, 403 propio.
    $response = $this->actingAs($admin, 'platform')->putJson(
        'http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base",
        ['enabled' => false, 'reason' => 'Baja sin reautenticación'],
    );

    $response->assertStatus(403);
    expect($response->json('type'))->toBe('urn:pge:error:reauthentication-required');
});

// ---------------------------------------------------------------------
// Permisos (permisos.md §4.4)
// ---------------------------------------------------------------------

test('permisos.md §4.4: soporte lee el catálogo y las vistas previas, y recibe 403 en las cuatro escrituras', function (): void {
    [$tenant] = boModuleTestSetup();
    [$soporte] = boCreateEnrolledAdmin('soporte');

    $this->actingAs($soporte, 'platform');

    $this->getJson('http://'.boPlatformHost().'/api/platform/v1/modules')->assertStatus(200);
    $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules")->assertStatus(200);
    $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/preview", [
        'module_code' => 'bo-test-base', 'enabled' => true,
    ])->assertStatus(200);
    $this->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts/preview', [
        'module_code' => 'bo-test-base', 'enabled' => true, 'tenant_public_ids' => [$tenant->public_id],
    ])->assertStatus(200);

    $this->putJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/modules/bo-test-base", [
        'enabled' => true, 'reason' => 'Intento de soporte',
    ])->assertStatus(403);

    $this->postJson('http://'.boPlatformHost().'/api/platform/v1/module-rollouts', [
        'module_code' => 'bo-test-base', 'enabled' => true, 'reason' => 'Intento de soporte',
        'tenant_public_ids' => [$tenant->public_id],
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertStatus(403);
});

// ---------------------------------------------------------------------
// Purga de claves de idempotencia de plataforma (issue #221)
// ---------------------------------------------------------------------

// Issue #221 (hallazgo Medio de revisión independiente, 1.6c): el
// docblock de la migración que crea `platform_idempotency_keys` afirmaba
// "el mismo criterio de purga física a las 24h (PurgePlatformIdempotencyKeys)",
// pero esa clase no existía. Cerrado con
// App\Modules\Backoffice\Infrastructure\Jobs\PurgePlatformIdempotencyKeys,
// mismo patrón que su homóloga de tenant (PurgeExpiredIdempotencyKeys).
test('issue #221: PurgePlatformIdempotencyKeys borra físicamente las claves vencidas y conserva las vigentes', function (): void {
    $expired = PlatformIdempotencyKey::create([
        'endpoint' => 'bo.module-rollouts.store',
        'idempotency_key' => (string) Str::ulid(),
        'request_body_hash' => hash('sha256', 'vencida'),
        'status' => 'completado',
        'response_status' => 202,
        'response_body' => ['status' => 'ok'],
        'expires_at' => now()->subHour(),
    ]);

    $current = PlatformIdempotencyKey::create([
        'endpoint' => 'bo.module-rollouts.store',
        'idempotency_key' => (string) Str::ulid(),
        'request_body_hash' => hash('sha256', 'vigente'),
        'status' => 'completado',
        'response_status' => 202,
        'response_body' => ['status' => 'ok'],
        'expires_at' => now()->addHour(),
    ]);

    PurgePlatformIdempotencyKeys::dispatchSync();

    expect(PlatformIdempotencyKey::find($expired->id))->toBeNull();
    expect(PlatformIdempotencyKey::find($current->id))->not->toBeNull();
});
