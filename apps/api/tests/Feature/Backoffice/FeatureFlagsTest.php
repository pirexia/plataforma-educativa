<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\PlatformCapability;
use App\Modules\Core\Domain\Models\FeatureFlag;
use App\Modules\Core\Domain\Models\FeatureFlagRule;
use App\Modules\Core\Infrastructure\FeatureFlagCatalogCache;
use App\Support\FeatureFlags\FeatureFlagEvaluator;
use App\Support\FeatureFlags\FeatureFlagExplainer;
use App\Support\FeatureFlags\FeatureFlagMatchedBy;
use App\Support\FeatureFlags\FeatureFlagSubject;
use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Modules\SyncModuleRegistry;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * `REQ-BO-005` puntos 1-2, sub-paso `1.6e`. `RN-BO-34` a `RN-BO-47`
 * (§7.5) y `RN-BO-99` a `RN-BO-109` (§7.7), `CA-BO-080` a `CA-BO-097` y
 * `CA-BO-167` a `CA-BO-178`.
 *
 * `boCreateEnrolledAdmin()`, `boPlatformHost()`, `boAllowCurrentTestIp()`,
 * `boSensitiveClient()` están definidas en PlatformAdminManagementTest.php
 * y TenantLifecycleTest.php. `provisionCoreTenant()`/`coreApiUrl()` están
 * en tests/Pest.php.
 *
 * Módulo de prueba con dos *flags* declarados, mismo patrón que
 * `ModuleFixtureBaseProvider` de ModuleContractingTest.php: sin él,
 * `platform:sync-registry` no materializa nada que evaluar.
 */
class FlagFixtureModuleProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function moduleDescriptor(): array
    {
        return [
            'code' => 'bo-test-flags',
            'name_key' => 'modules.sync_registry_test',
            'phase' => '0',
            'feature_flags' => [
                ['key' => 'bo_test.alpha', 'name_key' => 'flags.bo_test.alpha.name', 'description_key' => 'flags.bo_test.alpha.description'],
                ['key' => 'bo_test.beta', 'name_key' => 'flags.bo_test.beta.name', 'description_key' => 'flags.bo_test.beta.description'],
            ],
        ];
    }

    public function declaredPermissions(): array
    {
        return [];
    }
}

/**
 * `SyncModuleRegistryCommand`/`platform:sync-registry` descubre
 * `ServiceProvider`s por **fichero** bajo `app/Modules/*` — no ve una
 * clase de fixture definida dentro de un test (mismo motivo por el que
 * `ModuleContractingTest.php`/`SyncModuleRegistryTest.php` no pasan por
 * el comando: `SyncModuleRegistry::run([FlagFixtureModuleProvider::class])`
 * directamente, que es exactamente lo que el comando hace por debajo,
 * materializa de verdad `modules`/`feature_flags` por `pgsql_owner`.
 * `app()->register()` es aparte: así es como `DeclaredModuleCatalog`
 * (`app()->getProviders(DeclaresModuleRegistry::class)`) ve el
 * descriptor para `depends_on`/`essential`/`featureFlags` — la propia
 * app() de test es nueva en cada test, así que no hay memoización que
 * arrastrar entre tests.
 */
function registerFlagFixtures(): void
{
    app()->register(new FlagFixtureModuleProvider(app()));
    SyncModuleRegistry::run([FlagFixtureModuleProvider::class]);
    forgetFlagCatalogCache();
}

/**
 * `OPEN-BO-24` decisión (b): la caché del catálogo vive en una entrada
 * global de Redis, deliberadamente fuera del alcance de
 * `TenantContext::applyCachePrefix()` (`FeatureFlagCatalogCache`) — así
 * que también fuera del alcance de lo que `Cache::flush()`/`Cache::forget()`
 * limpian con seguridad entre tests. Cualquier fixture que escriba
 * `feature_flags`/`feature_flag_rules` por fuera del servicio real
 * (que sí invalida en cada escritura) tiene que invalidarla a mano.
 */
function forgetFlagCatalogCache(): void
{
    app(FeatureFlagCatalogCache::class)->forget();
}

function flagFixture(string $key): FeatureFlag
{
    return FeatureFlag::query()->where('key', $key)->firstOrFail();
}

/**
 * `RN-BO-102`: el evaluador comprueba el módulo dueño antes que
 * cualquier regla. Los tests que ejercitan el motor de decisión sobre
 * `bo_test.*` (module_code = bo-test-flags) necesitan el módulo
 * contratado para ese centro, o el paso 0 los corta siempre en falso —
 * eso es lo que comprueban por separado CA-BO-092/CA-BO-171.
 */
function contractFlagModule(Tenant $tenant): void
{
    DB::connection('pgsql_platform')->table('module_subscriptions')->insert([
        'tenant_id' => $tenant->id,
        'public_id' => (string) Str::ulid(),
        'module_code' => 'bo-test-flags',
        'enabled' => true,
        'enabled_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Escribe reglas directamente sobre la tabla, sin pasar por el servicio
 * de administración — para los tests del motor de decisión, que no
 * necesitan ejercitar la escritura HTTP. Por `pgsql_owner`: fuera de
 * `runAsPlatform()`, `FeatureFlagRule::getConnectionName()` resuelve a
 * la conexión de tenant por defecto (`pgsql`, rol `plataforma_app`), que
 * sólo tiene `SELECT` sobre esta tabla (`datos.md §9.6`) — un fixture de
 * test no es una escritura del backoffice y no debería fingir que lo es
 * entrando en modo plataforma sólo para poder escribir.
 */
function putFixtureRule(string $flagKey, string $scopeType, array $attributes = []): FeatureFlagRule
{
    $flag = flagFixture($flagKey);

    $id = DB::connection('pgsql_owner')->table('feature_flag_rules')->insertGetId(array_merge([
        'feature_flag_id' => $flag->id,
        'public_id' => (string) Str::ulid(),
        'scope_type' => $scopeType,
        'enabled' => true,
        'reason' => 'Fixture de test',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    forgetFlagCatalogCache();

    return FeatureFlagRule::query()->findOrFail($id);
}

/**
 * Mismo motivo que `putFixtureRule()`: escritura directa de fixture por
 * `pgsql_owner`, sin fingir una escritura real del backoffice.
 */
function updateFixtureFlag(string $key, array $attributes): void
{
    DB::connection('pgsql_owner')->table('feature_flags')->where('key', $key)->update($attributes);
    forgetFlagCatalogCache();
}

function updateFixtureRulesByFlagId(int $flagId, array $attributes): void
{
    DB::connection('pgsql_owner')->table('feature_flag_rules')->where('feature_flag_id', $flagId)->update($attributes);
    forgetFlagCatalogCache();
}

beforeEach(function (): void {
    registerFlagFixtures();
});

afterEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_owner')->table('feature_flag_rules')->delete();
    DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'bo-test-flags')->delete();
    DB::connection('pgsql_owner')->table('feature_flags')->whereIn('key', ['bo_test.alpha', 'bo_test.beta'])->delete();
    DB::connection('pgsql_owner')->table('modules')->where('code', 'bo-test-flags')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
    DB::connection('pgsql_platform')->table('tenants')->delete();
    forgetFlagCatalogCache();
    Cache::flush();
});

// ---------------------------------------------------------------------
// El motor de decisión (funcional.md §5.11.5, RN-BO-34 a RN-BO-47)
// ---------------------------------------------------------------------

// CA-BO-080
test('CA-BO-080: un flag sin ninguna regla es falso para cualquier centro', function (): void {
    $tenant = Tenant::factory()->create();

    $enabled = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'),
    );

    expect($enabled)->toBeFalse();
});

// CA-BO-081
test('CA-BO-081: una regla global activa expone a todos salvo al centro con regla nominal a false', function (): void {
    $exposed = Tenant::factory()->create();
    $excluded = Tenant::factory()->create();
    contractFlagModule($exposed);
    contractFlagModule($excluded);

    putFixtureRule('bo_test.alpha', 'global');
    putFixtureRule('bo_test.alpha', 'tenant', ['affected_tenant_id' => $excluded->id, 'enabled' => false]);

    $context = app(TenantContext::class);
    $evaluator = app(FeatureFlagEvaluator::class);

    expect($context->runFor($exposed->id, fn () => $evaluator->isEnabled('bo_test.alpha')))->toBeTrue();
    expect($context->runFor($excluded->id, fn () => $evaluator->isEnabled('bo_test.alpha')))->toBeFalse();
});

// CA-BO-082
test('CA-BO-082: forced_off está por encima de la regla nominal y de early_adopters', function (): void {
    $tenant = Tenant::factory()->create(['early_adopter_since' => now()]);
    contractFlagModule($tenant);

    updateFixtureFlag('bo_test.alpha', ['status' => 'forced_off', 'status_reason' => 'Incidente de prueba']);

    putFixtureRule('bo_test.alpha', 'tenant', ['affected_tenant_id' => $tenant->id, 'enabled' => true]);
    putFixtureRule('bo_test.alpha', 'early_adopters');

    $enabled = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'),
    );

    expect($enabled)->toBeFalse();
});

// CA-BO-083
test('CA-BO-083: el reparto por porcentaje es estable y monótono al subir el porcentaje', function (): void {
    $tenants = Tenant::factory()->count(40)->create();
    $tenants->each(fn (Tenant $t) => contractFlagModule($t));

    putFixtureRule('bo_test.alpha', 'percentage', ['percentage' => 10]);

    $context = app(TenantContext::class);
    $evaluator = app(FeatureFlagEvaluator::class);

    $exposedAt10 = $tenants->filter(fn (Tenant $t) => $context->runFor($t->id, fn () => $evaluator->isEnabled('bo_test.alpha')));

    // Estable: reevaluar el mismo centro da el mismo resultado.
    foreach ($exposedAt10 as $tenant) {
        expect($context->runFor($tenant->id, fn () => $evaluator->isEnabled('bo_test.alpha')))->toBeTrue();
    }

    updateFixtureRulesByFlagId(flagFixture('bo_test.alpha')->id, ['percentage' => 40]);

    $exposedAt40 = $tenants->filter(fn (Tenant $t) => $context->runFor($t->id, fn () => $evaluator->isEnabled('bo_test.alpha')));

    // Monótono: nadie que estuviera expuesto al 10% deja de estarlo al 40%.
    expect($exposedAt10->pluck('id')->diff($exposedAt40->pluck('id')))->toBeEmpty();
    expect($exposedAt40->count())->toBeGreaterThanOrEqual($exposedAt10->count());
});

// CA-BO-084
test('CA-BO-084: dos flags al mismo porcentaje no exponen exactamente al mismo conjunto', function (): void {
    $tenants = Tenant::factory()->count(30)->create();
    $tenants->each(fn (Tenant $t) => contractFlagModule($t));

    putFixtureRule('bo_test.alpha', 'percentage', ['percentage' => 50]);
    putFixtureRule('bo_test.beta', 'percentage', ['percentage' => 50]);

    $context = app(TenantContext::class);
    $evaluator = app(FeatureFlagEvaluator::class);

    $exposedAlpha = $tenants->filter(fn (Tenant $t) => $context->runFor($t->id, fn () => $evaluator->isEnabled('bo_test.alpha')))->pluck('id');
    $exposedBeta = $tenants->filter(fn (Tenant $t) => $context->runFor($t->id, fn () => $evaluator->isEnabled('bo_test.beta')))->pluck('id');

    expect($exposedAlpha->values()->all())->not->toBe($exposedBeta->values()->all());
});

// CA-BO-085
test('CA-BO-085: una regla de rol expone a quien tiene ese código, incluido un rol personalizado con el mismo código', function (): void {
    // `provisionCoreTenant()` corre `platform:sync-registry` de verdad
    // (descubrimiento por fichero, ningún proveedor real declara flags
    // todavía) — eso retiraría los flags de fixture materializados en
    // `beforeEach()`, así que se rematerializan después.
    [$tenant, $adminUser] = provisionCoreTenant();
    registerFlagFixtures();
    contractFlagModule($tenant);

    putFixtureRule('bo_test.alpha', 'global');
    putFixtureRule('bo_test.alpha', 'role', ['role_code' => 'docente']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        $docentePerson = Person::factory()->create(['contact_email' => 'docente-flag@example.com']);
        $docente = User::factory()->for($docentePerson)->create(['email' => 'docente-flag@example.com']);
        $docente->roles()->attach(Role::where('code', 'docente')->where('tenant_id', $tenant->id)->firstOrFail()->id);

        $directorPerson = Person::factory()->create(['contact_email' => 'director-flag@example.com']);
        $director = User::factory()->for($directorPerson)->create(['email' => 'director-flag@example.com']);
        $director->roles()->attach(Role::where('code', 'administrador_centro')->where('tenant_id', $tenant->id)->firstOrFail()->id);

        auth('web')->setUser($docente);
        expect(app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'))->toBeTrue();

        auth('web')->setUser($director);
        expect(app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'))->toBeFalse();
    });
});

// CA-BO-086
test('CA-BO-086: una regla de rol con un código inexistente se acepta y no expone a nadie', function (): void {
    [$tenant, $admin] = provisionCoreTenant();
    registerFlagFixtures();
    contractFlagModule($tenant);

    putFixtureRule('bo_test.alpha', 'global');
    putFixtureRule('bo_test.alpha', 'role', ['role_code' => 'codigo_que_no_existe']);

    $enabled = app(TenantContext::class)->runFor($tenant->id, function () use ($admin): bool {
        auth('web')->setUser($admin);

        return app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha');
    });

    expect($enabled)->toBeFalse();
});

// CA-BO-087
test('CA-BO-087: sin sujeto usuario, un flag con reglas de rol es falso', function (): void {
    $tenant = Tenant::factory()->create();
    contractFlagModule($tenant);

    putFixtureRule('bo_test.alpha', 'global');
    putFixtureRule('bo_test.alpha', 'role', ['role_code' => 'docente']);

    $enabled = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'),
    );

    expect($enabled)->toBeFalse();
});

// CA-BO-168, RN-BO-100
test('CA-BO-168: isEnabled() sin contexto de tenant es falso, no lanza y no filtra otro tenant', function (): void {
    $tenant = Tenant::factory()->create();
    contractFlagModule($tenant);
    putFixtureRule('bo_test.alpha', 'global');
    app(TenantContext::class)->runFor($tenant->id, fn () => null); // asegura que la regla existe antes de comprobar sin tenant

    expect(app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'))->toBeFalse();
});

// CA-BO-170, RN-BO-101
test('CA-BO-170: isEnabled() y explain() dan el mismo booleano sobre el mismo conjunto de reglas', function (): void {
    $tenant = Tenant::factory()->create();
    contractFlagModule($tenant);
    putFixtureRule('bo_test.alpha', 'tenant', ['affected_tenant_id' => $tenant->id, 'enabled' => true]);

    $viaEvaluator = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'),
    );

    $subject = new FeatureFlagSubject(tenantId: $tenant->id, tenantPublicId: $tenant->public_id);
    $decision = app(FeatureFlagExplainer::class)->explain($subject, 'bo_test.alpha');

    expect($decision->enabled)->toBe($viaEvaluator)->toBeTrue();
    expect($decision->matchedBy)->toBe(FeatureFlagMatchedBy::Tenant);
});

// ---------------------------------------------------------------------
// RN-BO-102, CA-BO-092, CA-BO-171: el módulo dueño se comprueba antes
// que cualquier regla, también fuera del camino HTTP.
// ---------------------------------------------------------------------

test('CA-BO-171: un flag al 100% sobre un módulo no contratado es falso y matched_by es module_disabled, evaluado como desde un trabajo en cola', function (): void {
    $tenant = Tenant::factory()->create();
    // No se contrata bo-test-flags para este tenant.
    putFixtureRule('bo_test.alpha', 'global');

    $enabled = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'),
    );
    expect($enabled)->toBeFalse();

    $subject = new FeatureFlagSubject(tenantId: $tenant->id, tenantPublicId: $tenant->public_id);
    $decision = app(FeatureFlagExplainer::class)->explain($subject, 'bo_test.alpha');
    expect($decision->enabled)->toBeFalse();
    expect($decision->matchedBy)->toBe(FeatureFlagMatchedBy::ModuleDisabled);
});

test('CA-BO-092: con el módulo contratado, la misma regla global sí expone', function (): void {
    $tenant = Tenant::factory()->create();

    DB::connection('pgsql_platform')->table('module_subscriptions')->insert([
        'tenant_id' => $tenant->id,
        'public_id' => (string) Str::ulid(),
        'module_code' => 'bo-test-flags',
        'enabled' => true,
        'enabled_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    putFixtureRule('bo_test.alpha', 'global');

    $enabled = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'),
    );

    expect($enabled)->toBeTrue();
});

// ---------------------------------------------------------------------
// RN-BO-44, RN-BO-103, CA-BO-089, CA-BO-091, CA-BO-172: catálogo,
// retirada y CA-BO-090 (no existe POST de creación).
// ---------------------------------------------------------------------

test('CA-BO-091: un flag retirado evalúa falso y sus escrituras devuelven 422, pero sus reglas siguen consultables', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    boAllowCurrentTestIp();
    $this->actingAs($admin, 'platform');

    putFixtureRule('bo_test.alpha', 'global');
    updateFixtureFlag('bo_test.alpha', ['retired_at' => now()]);

    $tenant = Tenant::factory()->create();
    $enabled = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'),
    );
    expect($enabled)->toBeFalse();

    $client = boSensitiveClient($admin, $secret);
    $response = $client->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/state', [
        'status' => 'forced_off',
        'reason' => 'Intento sobre un flag retirado',
    ]);
    $response->assertStatus(422);
    expect($response->json('errors.key.0.code'))->toBe('bo.flag.retired');

    $show = $this->getJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha');
    $show->assertOk();
    expect($show->json('data.rules'))->toHaveCount(1);
});

test('CA-BO-089: platform:sync-registry aborta si dos módulos declaran la misma clave de flag', function (): void {
    $duplicate = new class(app()) extends ServiceProvider implements DeclaresModuleRegistry
    {
        public function moduleDescriptor(): array
        {
            return [
                'code' => 'bo-test-flags-dup',
                'name_key' => 'modules.sync_registry_test',
                'phase' => '0',
                'feature_flags' => [
                    ['key' => 'bo_test.alpha', 'name_key' => 'x', 'description_key' => 'y'],
                ],
            ];
        }

        public function declaredPermissions(): array
        {
            return [];
        }
    };

    app()->register($duplicate);

    expect(fn () => SyncModuleRegistry::run([FlagFixtureModuleProvider::class, $duplicate::class]))
        ->toThrow(InvalidArgumentException::class);

    DB::connection('pgsql_owner')->table('modules')->where('code', 'bo-test-flags-dup')->delete();
});

test('CA-BO-172, RN-BO-103: retirar un flag en un despliegue incrementa rules_version y evalúa falso de inmediato, sin esperar caché', function (): void {
    $tenant = Tenant::factory()->create();
    contractFlagModule($tenant);
    putFixtureRule('bo_test.alpha', 'global');

    $context = app(TenantContext::class);
    $evaluator = app(FeatureFlagEvaluator::class);

    // Calienta la caché del catálogo (decisión (b), OPEN-BO-24).
    expect($context->runFor($tenant->id, fn () => $evaluator->isEnabled('bo_test.alpha')))->toBeTrue();

    $versionBefore = flagFixture('bo_test.alpha')->rules_version;

    // Redespliegue sin bo_test.alpha en el descriptor: se retira.
    $reduced = new class(app()) extends ServiceProvider implements DeclaresModuleRegistry
    {
        public function moduleDescriptor(): array
        {
            return [
                'code' => 'bo-test-flags',
                'name_key' => 'modules.sync_registry_test',
                'phase' => '0',
                'feature_flags' => [
                    ['key' => 'bo_test.beta', 'name_key' => 'x', 'description_key' => 'y'],
                ],
            ];
        }

        public function declaredPermissions(): array
        {
            return [];
        }
    };

    // Sustituye el provider registrado por uno que ya no declara
    // bo_test.alpha, y vuelve a sincronizar.
    app()->register($reduced);
    SyncModuleRegistry::run([$reduced::class]);

    $flag = flagFixture('bo_test.alpha');
    expect($flag->retired_at)->not->toBeNull();
    expect($flag->rules_version)->toBeGreaterThan($versionBefore);

    expect($context->runFor($tenant->id, fn () => $evaluator->isEnabled('bo_test.alpha')))->toBeFalse();
});

test('CA-BO-090: no existe ningún endpoint para crear un flag', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    boAllowCurrentTestIp();
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags', [
        'key' => 'inventado.no_deberia_existir',
    ]);

    expect($response->status())->toBeIn([404, 405]);
});

// ---------------------------------------------------------------------
// Escrituras administrativas: capacidad, motivo, auditoría (RN-BO-43,
// CA-BO-094, CA-BO-095, RN-BO-104, CA-BO-173)
// ---------------------------------------------------------------------

test('CA-BO-094: soporte no puede escribir flags y operaciones sí', function (): void {
    boAllowCurrentTestIp();
    putFixtureRule('bo_test.alpha', 'global');

    [$soporte] = boCreateEnrolledAdmin('soporte');
    $this->actingAs($soporte, 'platform');
    $forbidden = $this->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/state', [
        'status' => 'forced_off',
        'reason' => 'Intento sin capacidad',
    ]);
    $forbidden->assertStatus(403);

    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();

    [$operaciones, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($operaciones, 'platform');
    $client = boSensitiveClient($operaciones, $secret);
    $allowed = $client->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/state', [
        'status' => 'forced_off',
        'reason' => 'Apagando por incidencia',
    ]);
    $allowed->assertOk();
});

test('CA-BO-095: escribir sin motivo es 422; con motivo, hay entrada en admin_action_logs con affected_tenant_id nulo (alcance global)', function (): void {
    boAllowCurrentTestIp();
    putFixtureRule('bo_test.alpha', 'global');
    [$admin] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $missingReason = $this->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/state', [
        'status' => 'forced_off',
    ]);
    $missingReason->assertStatus(422);
    expect(AdminActionLog::query()->count())->toBe(0);

    $ok = $this->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/state', [
        'status' => 'forced_off',
        'reason' => 'Apagando por incidencia',
    ]);
    $ok->assertOk();

    $log = AdminActionLog::query()->where('action', 'flag.estado_cambiado')->firstOrFail();
    expect($log->affected_tenant_id)->toBeNull();
    expect($log->reason)->toBe('Apagando por incidencia');
});

test('RN-BO-104, CA-BO-173: PUT .../rules reemplaza por diferencia en una transacción con un solo incremento de rules_version', function (): void {
    boAllowCurrentTestIp();
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $existing = putFixtureRule('bo_test.alpha', 'global');
    $toDrop = putFixtureRule('bo_test.alpha', 'early_adopters');
    $versionBefore = flagFixture('bo_test.alpha')->rules_version;

    $response = $client->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/rules', [
        'reason' => 'Ajuste de despliegue',
        'rules' => [
            ['scope_type' => 'global', 'enabled' => true],
            ['scope_type' => 'percentage', 'percentage' => 30, 'enabled' => true],
        ],
    ]);

    $response->assertOk();
    expect($response->json('data.rules_version'))->toBe($versionBefore + 1);
    expect($response->json('data.rules'))->toHaveCount(2);

    $toDrop->refresh();
    expect($toDrop->deleted_at)->not->toBeNull();

    $existing->refresh();
    expect($existing->deleted_at)->toBeNull();

    $log = AdminActionLog::query()->where('action', 'flag.reglas_cambiadas')->firstOrFail();
    expect($log->reason)->toBe('Ajuste de despliegue');
});

test('api.md §2.11: escribir rollout_unit en el cuerpo de PUT .../rules es 422', function (): void {
    boAllowCurrentTestIp();
    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $response = $client->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/rules', [
        'reason' => 'No debería aceptarse',
        'rollout_unit' => 'user',
        'rules' => [],
    ]);

    $response->assertStatus(422);
});

test('api.md §2.12: encender un flag exige reautenticación viva; apagarlo no', function (): void {
    boAllowCurrentTestIp();
    putFixtureRule('bo_test.alpha', 'global');
    [$admin] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $offWithoutReauth = $this->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/state', [
        'status' => 'forced_off',
        'reason' => 'Apagar sin reautenticación',
    ]);
    $offWithoutReauth->assertOk();

    $onWithoutReauth = $this->putJson('http://'.boPlatformHost().'/api/platform/v1/feature-flags/bo_test.alpha/state', [
        'status' => 'activo',
        'reason' => 'Encender sin reautenticación',
    ]);
    $onWithoutReauth->assertStatus(403);
    expect($onWithoutReauth->json('type'))->toBe('urn:pge:error:reauthentication-required');
});

// ---------------------------------------------------------------------
// RN-BO-46, RN-BO-108, RN-BO-109, CA-BO-093, CA-BO-178: early adopter
// ---------------------------------------------------------------------

test('CA-BO-093, CA-BO-178: designar y retirar early adopter escribe la columna, deja dos entradas de auditoría y ninguna transición de ciclo de vida', function (): void {
    boAllowCurrentTestIp();
    [$admin] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $tenant = Tenant::factory()->create();
    contractFlagModule($tenant);
    putFixtureRule('bo_test.alpha', 'early_adopters');

    $designate = $this->putJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/early-adopter", [
        'early_adopter' => true,
        'reason' => 'Acuerdo de pilotaje',
    ]);
    $designate->assertOk();
    expect($designate->json('data.early_adopter_since'))->not->toBeNull();

    $tenant->refresh();
    expect($tenant->early_adopter_since)->not->toBeNull();

    $enabled = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(FeatureFlagEvaluator::class)->isEnabled('bo_test.alpha'),
    );
    expect($enabled)->toBeTrue();

    $retract = $this->putJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/early-adopter", [
        'early_adopter' => false,
        'reason' => 'Fin del pilotaje',
    ]);
    $retract->assertOk();
    expect($retract->json('data.early_adopter_since'))->toBeNull();

    expect(AdminActionLog::query()->where('action', 'tenant.early_adopter_designado')->count())->toBe(1);
    expect(AdminActionLog::query()->where('action', 'tenant.early_adopter_retirado')->count())->toBe(1);
    expect(DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->where('affected_tenant_id', $tenant->id)->count())->toBe(0);
});

test('RN-BO-108: designar early adopter a un centro eliminado responde 409 bo.flag.tenant_state_invalid', function (): void {
    boAllowCurrentTestIp();
    [$admin] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $tenant = Tenant::factory()->create(['status' => TenantStatus::Eliminado]);
    $tenant->delete();

    $response = $this->putJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/early-adopter", [
        'early_adopter' => true,
        'reason' => 'No debería admitirse',
    ]);

    $response->assertStatus(409);
    expect($response->json('detail'))->not->toBeNull();
});

test('early-adopter no exige reautenticación (api.md §4)', function (): void {
    boAllowCurrentTestIp();
    [$admin] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $tenant = Tenant::factory()->create();

    $response = $this->putJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/early-adopter", [
        'early_adopter' => true,
        'reason' => 'Sin reautenticación',
    ]);

    $response->assertOk();
});

// ---------------------------------------------------------------------
// api.md §2.13, §2.14: las dos lecturas
// ---------------------------------------------------------------------

test('CA-BO-097, CA-BO-176: GET /api/v1/feature-flags devuelve exactamente el conjunto que isEnabled() da verdadero', function (): void {
    [$tenant, $admin] = provisionCoreTenant();
    registerFlagFixtures();
    contractFlagModule($tenant);

    putFixtureRule('bo_test.alpha', 'global');
    // bo_test.beta queda sin ninguna regla: apagado (RN-BO-35).

    $response = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/feature-flags'));

    $response->assertOk();
    expect($response->json('data'))->toBe(['bo_test.alpha']);
});

test('INV-002: GET /api/v1/feature-flags exige sesión, no evalúa para un visitante anónimo', function (): void {
    [$tenant] = provisionCoreTenant();
    registerFlagFixtures();
    contractFlagModule($tenant);

    putFixtureRule('bo_test.alpha', 'global');

    $response = test()->getJson(coreApiUrl($tenant->slug, '/feature-flags'));

    $response->assertStatus(401);
});

test('GET /tenants/{id}/feature-flags responde con matched_by para cada flag del catálogo', function (): void {
    boAllowCurrentTestIp();
    [$admin] = boCreateEnrolledAdmin('soporte');
    $this->actingAs($admin, 'platform');

    $tenant = Tenant::factory()->create();
    contractFlagModule($tenant);
    putFixtureRule('bo_test.alpha', 'global');

    $response = $this->getJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/feature-flags");

    $response->assertOk();
    $data = collect($response->json('data'))->keyBy('key');
    expect($data['bo_test.alpha']['enabled'])->toBeTrue();
    expect($data['bo_test.alpha']['matched_by'])->toBe('global');
    expect($data['bo_test.beta']['enabled'])->toBeFalse();
    expect($data['bo_test.beta']['matched_by'])->toBe('none');
});

// ---------------------------------------------------------------------
// CA-BO-167: exactamente dos capacidades nuevas
// ---------------------------------------------------------------------

test('CA-BO-167: PlatformCapability declara exactamente flag.leer y flag.gestionar como capacidades de flag', function (): void {
    $flagCases = array_values(array_filter(PlatformCapability::cases(), fn (PlatformCapability $c) => str_starts_with($c->value, 'flag.')));

    expect(array_map(fn (PlatformCapability $c) => $c->value, $flagCases))
        ->toEqualCanonicalizing(['flag.leer', 'flag.gestionar']);
});

// ---------------------------------------------------------------------
// CA-BO-177 (obligatorio, skill aislamiento-tenant): tres tenants, 50%,
// estable, independiente del orden, y ninguna entrada de caché de un
// tenant se lee ni se escribe al evaluar otro.
// ---------------------------------------------------------------------

test('CA-BO-177 (aislamiento obligatorio): el reparto por porcentaje es estable, independiente del orden, y no cruza cachés de tenant', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $c = Tenant::factory()->create();
    contractFlagModule($a);
    contractFlagModule($b);
    contractFlagModule($c);

    putFixtureRule('bo_test.alpha', 'percentage', ['percentage' => 50]);

    $context = app(TenantContext::class);
    $evaluator = app(FeatureFlagEvaluator::class);

    $first = [
        $a->id => $context->runFor($a->id, fn () => $evaluator->isEnabled('bo_test.alpha')),
        $b->id => $context->runFor($b->id, fn () => $evaluator->isEnabled('bo_test.alpha')),
        $c->id => $context->runFor($c->id, fn () => $evaluator->isEnabled('bo_test.alpha')),
    ];

    // Orden inverso: mismo resultado por centro.
    $second = [
        $c->id => $context->runFor($c->id, fn () => $evaluator->isEnabled('bo_test.alpha')),
        $b->id => $context->runFor($b->id, fn () => $evaluator->isEnabled('bo_test.alpha')),
        $a->id => $context->runFor($a->id, fn () => $evaluator->isEnabled('bo_test.alpha')),
    ];

    // `toEqual`, no `toBe`: mismos pares clave-valor, pero el orden de
    // inserción difiere a propósito (evaluación en orden inverso) — el
    // orden de las claves del array no es lo que aquí se comprueba.
    expect($second)->toEqual($first);

    // Prefijo de caché de tenant intacto: evaluar A no deja ninguna
    // clave bajo el prefijo de B (ADR-033 §9) — la caché del motor vive
    // bajo un prefijo global explícito (OPEN-BO-24 (b)), nunca bajo
    // t{tenant_id}:.
    $context->enter($b->id);
    $keysUnderB = Cache::getRedis()->keys(config('cache.prefix').'*');
    $context->leave();

    foreach ($keysUnderB as $key) {
        expect($key)->not->toContain('feature-flags');
    }
});

// ---------------------------------------------------------------------
// Test de arquitectura: RN-BO-99, RN-BO-47, CA-BO-096, CA-BO-169
// ---------------------------------------------------------------------

test('CA-BO-169: ningún fichero fuera de app/Modules/Core lee feature_flags/feature_flag_rules por modelo, ningún fichero fuera de Backoffice inyecta FeatureFlagExplainer, y Backoffice no inyecta FeatureFlagEvaluator', function (): void {
    $appPath = base_path('app');
    $coreModel1 = 'Modules\\Core\\Domain\\Models\\FeatureFlag';
    $coreModel2 = 'Modules\\Core\\Domain\\Models\\FeatureFlagRule';

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace($appPath.'/', '', $file->getPathname());
        $contents = file_get_contents($file->getPathname());

        $insideCore = str_starts_with($relative, 'Modules/Core/');
        $insideBackoffice = str_starts_with($relative, 'Modules/Backoffice/');

        if (! $insideCore) {
            expect($contents)->not->toContain($coreModel1, "{$relative}: usa FeatureFlag directamente (RN-BO-99)");
            expect($contents)->not->toContain($coreModel2, "{$relative}: usa FeatureFlagRule directamente (RN-BO-99)");
        }

        if (! $insideBackoffice) {
            expect($contents)->not->toContain('FeatureFlagExplainer', "{$relative}: inyecta FeatureFlagExplainer fuera de Backoffice");
        }

        if ($insideBackoffice) {
            expect($contents)->not->toContain('FeatureFlagEvaluator', "{$relative}: Backoffice inyecta FeatureFlagEvaluator");
        }
    }
});

// RN-BO-47, CA-BO-096: ningún control de seguridad consulta el motor de flags.
test('CA-BO-096: ningún middleware de MFA, lista blanca, autorización o doble autorización invoca el evaluador de flags', function (): void {
    $middlewarePaths = [
        base_path('app/Modules/Backoffice/Http/Middleware'),
        base_path('app/Http/Middleware'),
    ];

    foreach ($middlewarePaths as $path) {
        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            expect($contents)->not->toContain('FeatureFlagEvaluator', "{$file->getFilename()}: middleware de seguridad consulta el evaluador de flags (RN-BO-47)");
            expect($contents)->not->toContain('FeatureFlagExplainer', "{$file->getFilename()}: middleware de seguridad consulta el evaluador de flags (RN-BO-47)");
        }
    }
});
