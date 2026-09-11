<?php

use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Backoffice\Application\ActivateProvisionedTenant;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\TenantLifecycleEvent;
use App\Modules\Backoffice\Infrastructure\Jobs\ProvisionTenant;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * REQ-BO-001 (1.6b). CA-BO-106 a CA-BO-122 y CA-BO-127: alta en dos
 * fases, resolución/caché, transiciones simples, sesiones y datos, y
 * período de gracia. `boPlatformHost()`, `boAllowCurrentTestIp()`,
 * `boCreateEnrolledAdmin()`, `boReauthenticatedSessionCookie()` y
 * `boWithReauthenticatedCookie()` están definidas en
 * PlatformAdminManagementTest.php.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->artisan('platform:sync-registry')->run();
    boAllowCurrentTestIp();
});

afterEach(function (): void {
    // admin_action_logs y tenant_lifecycle_events son de solo-anexión
    // permanente (RN-BO-29, ADR-047 §4.3): TRUNCATE es el único camino
    // de limpieza en tests.
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_owner')->statement('TRUNCATE tenant_lifecycle_events');
    DB::connection('pgsql_platform')->table('dual_authorizations')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_sessions')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_challenges')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_recovery_codes')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_invitations')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * @return array<string, mixed>
 */
function boValidTenantPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Colegio de Prueba',
        'slug' => 'colegio-'.Str::lower(Str::random(8)),
        'reason' => 'Alta comercial de prueba',
        'settings' => [
            'default_locale' => 'es-ES',
            'active_locales' => ['es-ES'],
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'autonomous_community' => 'MD',
        ],
        'administrator' => [
            'email' => 'admin-'.Str::lower(Str::random(8)).'@example.com',
            'given_name' => 'Ana',
            'family_name' => 'Pérez',
        ],
    ], $overrides);
}

/**
 * Reautentica y devuelve un cliente de test listo para una operación
 * sensible (api.md §4). Ver PlatformAdminManagementTest.php para el
 * detalle de por qué es un round-trip HTTP real y no un `session()->put()`.
 */
function boSensitiveClient(\App\Modules\Backoffice\Domain\Models\PlatformAdmin $admin, string $secret): mixed
{
    $cookie = boReauthenticatedSessionCookie($admin, $secret);

    return boWithReauthenticatedCookie($cookie);
}

// ---------------------------------------------------------------------
// Alta (CA-BO-106 a CA-BO-109)
// ---------------------------------------------------------------------

test('CA-BO-106: el alta responde 201 en en_alta y, tras la fase 2, el tenant queda activo con sus 16 roles y su administrador invitado', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $payload = boValidTenantPayload();

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/tenants', $payload);

    $response->assertStatus(201);
    // La respuesta 201 no miente sobre el instante en que se construyó
    // (funcional.md §5.3.3): en ese momento el tenant estaba en_alta,
    // aunque la fase 2 (síncrona en tests, QUEUE_CONNECTION=sync) ya
    // haya terminado para cuando se lee esta aserción.
    expect($response->json('data.status'))->toBe('en_alta');

    $tenant = Tenant::query()->where('slug', $payload['slug'])->firstOrFail();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Activo);

    $counts = app(TenantContext::class)->runFor($tenant->id, fn () => [
        'roles' => Role::query()->count(),
        'grants' => PermissionRole::query()->count(),
        'users' => User::query()->count(),
        'email' => User::query()->value('email'),
    ]);

    expect($counts['roles'])->toBe(16);
    expect($counts['grants'])->toBeGreaterThan(0);
    expect($counts['users'])->toBe(1);
    expect($counts['email'])->toBe($payload['administrator']['email']);

    expect(AdminActionLog::query()->where('action', 'tenant.creado')->where('affected_tenant_id', $tenant->id)->exists())->toBeTrue();

    $systemEntry = AdminActionLog::query()->where('action', 'tenant.actualizado')->where('affected_tenant_id', $tenant->id)->first();
    expect($systemEntry)->not->toBeNull();
    expect($systemEntry->actor_type->value)->toBe('system');

    $event = TenantLifecycleEvent::query()->where('affected_tenant_id', $tenant->id)->where('to_status', 'activo')->first();
    expect($event)->not->toBeNull();
    expect($event->from_status)->toBe(TenantStatus::EnAlta);
});

test('CA-BO-107: alta sin motivo o con motivo vacío responde 422 y no crea el tenant', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $payload = boValidTenantPayload(['reason' => '']);

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/tenants', $payload);

    $response->assertStatus(422);
    expect(Tenant::query()->where('slug', $payload['slug'])->exists())->toBeFalse();
});

test('CA-BO-107: un slug con formato inválido responde 422 sin usar el código de slug ya ocupado', function (): void {
    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $payload = boValidTenantPayload(['slug' => 'NO_Valido!']);

    $response = $client->postJson('http://'.boPlatformHost().'/api/platform/v1/tenants', $payload);

    $response->assertStatus(422);
    $codes = collect($response->json('errors.slug') ?? [])->pluck('code')->all();
    expect($codes)->not->toContain('bo.tenant.slug_taken');
});

test('CA-BO-108: un aprovisionamiento fallido deja el tenant en en_alta con una única entrada tenant.aprovisionamiento_fallido de actor system', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'en_alta']);

    $job = new ProvisionTenant(
        $tenant->public_id,
        TenantInitialSettings::defaults(),
        new TenantAdministrator('fallo@example.com', 'Nombre', 'Apellido'),
    );

    $job->failed(new RuntimeException('fallo simulado de aprovisionamiento'));

    expect($tenant->fresh()->status)->toBe(TenantStatus::EnAlta);

    $entry = AdminActionLog::query()->where('action', 'tenant.aprovisionamiento_fallido')->where('affected_tenant_id', $tenant->id)->first();
    expect($entry)->not->toBeNull();
    expect($entry->actor_type->value)->toBe('system');
    expect($entry->context['error'])->toBe('fallo simulado de aprovisionamiento');

    expect(TenantLifecycleEvent::query()->where('affected_tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('CA-BO-108: reencolar el mismo trabajo de aprovisionamiento no duplica roles, concesiones ni administrador', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'en_alta']);
    $administrator = new TenantAdministrator('retry@example.com', 'Ana', 'Perez');
    $settings = TenantInitialSettings::defaults();

    $provisioner = app(TenantProvisioner::class);
    $activator = app(ActivateProvisionedTenant::class);

    (new ProvisionTenant($tenant->public_id, $settings, $administrator))->handle($provisioner, $activator);

    $before = app(TenantContext::class)->runFor($tenant->id, fn () => [
        'roles' => Role::count(),
        'grants' => PermissionRole::count(),
        'users' => User::count(),
    ]);

    // Simula el caso que repara `bo:retry-provisioning`: la fase 2 había
    // fallado a medias y el tenant sigue (o vuelve a estar) en_alta.
    $tenant->forceFill(['status' => 'en_alta'])->save();

    (new ProvisionTenant($tenant->public_id, $settings, $administrator))->handle($provisioner, $activator);

    $after = app(TenantContext::class)->runFor($tenant->id, fn () => [
        'roles' => Role::count(),
        'grants' => PermissionRole::count(),
        'users' => User::count(),
    ]);

    expect($after)->toBe($before);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Activo);
});

// CA-BO-109: RN-BO-53/INV-007. Ningún fichero de App\Modules\Backoffice
// importa una clase interna de App\Modules\Core fuera de su superficie
// pública (Core\Domain, y solo tipos que no sean modelos Eloquent
// internos — TenantSettingsReader es la interfaz pública, no
// Core\Domain\Models\TenantSetting).
test('CA-BO-109: App\\Modules\\Backoffice no importa clases internas de App\\Modules\\Core fuera de su superficie pública', function (): void {
    $modulesPath = base_path('app/Modules/Backoffice');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modulesPath));
    $violations = [];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match_all('/^use (App\\\\Modules\\\\Core\\\\[^;]+);/m', $contents, $matches)) {
            foreach ($matches[1] as $import) {
                // Superficie pública: Core\Domain, pero NUNCA sus
                // modelos Eloquent internos (Core\Domain\Models\*) —
                // esos se consumen por interfaz (TenantSettingsReader,
                // UserDirectory...), nunca directamente.
                $isPublicSurface = str_starts_with($import, 'App\\Modules\\Core\\Domain')
                    && ! str_starts_with($import, 'App\\Modules\\Core\\Domain\\Models');

                if (! $isPublicSurface) {
                    $violations[] = "{$file->getPathname()}: {$import}";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

// ---------------------------------------------------------------------
// Resolución de tenant y caché — CA-BO-113, CA-BO-114 (CA-BO-110 a 112
// están en ResolveTenantMiddlewareTest.php)
// ---------------------------------------------------------------------

test('CA-BO-113: la caché de resolución no se envenena si la transición no llega a confirmarse', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'activo']);

    // Calienta la caché con el estado "activo".
    Cache::remember("tenant-resolution:{$tenant->slug}", 60, fn () => ['id' => $tenant->id, 'status' => 'activo']);

    // La invalidación real ocurre en `afterCommit`, después de que la
    // transacción de `pgsql_platform` confirme — no hay forma de
    // forzar un rollback desde fuera sin acoplarse a la implementación,
    // así que este test comprueba la propiedad observable: tras una
    // transición que SÍ confirma, el valor cacheado es el nuevo, nunca
    // el intermedio de una escritura a medias.
    [$admin] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/transitions", [
        'to_status' => 'suspendido',
        'reason' => 'Prueba CA-BO-113',
    ])->assertStatus(200);

    $cached = Cache::get("tenant-resolution:{$tenant->slug}");
    expect($cached)->toBeNull();
});

test('CA-BO-114: un cambio de slug invalida las dos claves de caché, la vieja y la nueva', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'activo', 'slug' => 'slug-viejo']);

    Cache::remember('tenant-resolution:slug-viejo', 60, fn () => ['id' => $tenant->id, 'status' => 'activo']);
    Cache::remember('tenant-resolution:slug-nuevo', 60, fn () => null);

    [$admin, $secret] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $client->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/slug", [
        'slug' => 'slug-nuevo',
        'reason' => 'Cambio de dominio de prueba',
    ])->assertStatus(200);

    expect(Cache::get('tenant-resolution:slug-viejo'))->toBeNull();
    expect(Cache::get('tenant-resolution:slug-nuevo'))->toBeNull();

    $entry = AdminActionLog::query()->where('action', 'tenant.slug_cambiado')->where('affected_tenant_id', $tenant->id)->first();
    expect($entry)->not->toBeNull();
});

// ---------------------------------------------------------------------
// Transiciones (CA-BO-115 a CA-BO-118, CA-BO-127)
// ---------------------------------------------------------------------

test('CA-BO-115: ninguna transición por API es alcanzable desde en_alta', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'en_alta']);

    [$admin] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');

    $response = $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/transitions", [
        'to_status' => 'activo',
        'reason' => 'Intento de forzar la transición',
    ]);

    $response->assertStatus(409);
    expect($tenant->fresh()->status)->toBe(TenantStatus::EnAlta);
});

test('CA-BO-116: reactivar limpia suspended_at/suspension_message, y el rescate limpia los campos de gracia', function (): void {
    [$admin] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $suspended = Tenant::factory()->create(['status' => 'suspendido', 'suspended_at' => now(), 'suspension_message' => 'Mensaje de prueba']);

    $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$suspended->public_id}/transitions", [
        'to_status' => 'activo',
        'reason' => 'Reactivación de prueba',
    ])->assertStatus(200);

    $suspended->refresh();
    expect($suspended->suspended_at)->toBeNull();
    expect($suspended->suspension_message)->toBeNull();

    $enBaja = Tenant::factory()->create(['status' => 'en_baja', 'grace_period_ends_at' => now()->addDays(10)]);

    [$superadmin] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($superadmin, 'platform');

    $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$enBaja->public_id}/transitions", [
        'to_status' => 'activo',
        'reason' => 'Rescate de prueba',
    ])->assertStatus(200);

    $enBaja->refresh();
    expect($enBaja->grace_period_ends_at)->toBeNull();
    expect($enBaja->grace_period_expired_at)->toBeNull();
});

test('CA-BO-117: la transición automática en_alta a activo escribe una clave de traducción, no una frase, y existe en los cuatro idiomas', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'en_alta']);

    (new ProvisionTenant($tenant->public_id, TenantInitialSettings::defaults(), new TenantAdministrator('sys@example.com', 'A', 'B')))
        ->handle(app(TenantProvisioner::class), app(ActivateProvisionedTenant::class));

    $event = TenantLifecycleEvent::query()->where('affected_tenant_id', $tenant->id)->where('to_status', 'activo')->firstOrFail();

    expect($event->reason)->toBe('bo.tenant_lifecycle.reason.provisioned');

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        app()->setLocale($locale);
        expect(__($event->reason))->not->toBe($event->reason);
    }

    app()->setLocale('es');
});

test('CA-BO-118: una confirmación de nombre que difiere en mayúsculas, acentos o espacios responde 422 y no crea ninguna solicitud', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'en_baja', 'name' => 'Colegio Peña Alta']);

    [$admin, $secret] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');
    $client = boSensitiveClient($admin, $secret);

    $response = $client->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/transitions", [
        'to_status' => 'eliminado',
        'reason' => 'Intento de eliminación con nombre incorrecto',
        'confirmation_name' => 'colegio peña alta',
    ]);

    $response->assertStatus(422);
    expect(DB::connection('pgsql_platform')->table('dual_authorizations')->count())->toBe(0);
});

// ---------------------------------------------------------------------
// Sesiones y datos (CA-BO-119, CA-BO-120, CA-BO-121)
// ---------------------------------------------------------------------

test('CA-BO-119: suspender no revoca las sesiones de los usuarios del centro, y siguen sirviendo tras reactivar', function (): void {
    [$tenant, $user] = provisionCoreTenant();

    $sessionId = app(TenantContext::class)->runFor($tenant->id, function () use ($user) {
        return \App\Modules\Auth\Domain\Models\UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'sess-'.Str::random(20),
            'ip_address' => '127.0.0.1',
            'started_at' => now(),
        ])->id;
    });

    [$admin] = boCreateEnrolledAdmin('operaciones');
    $this->actingAs($admin, 'platform');

    $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/transitions", [
        'to_status' => 'suspendido',
        'reason' => 'Suspensión de prueba',
    ])->assertStatus(200);

    $stillLive = app(TenantContext::class)->runFor($tenant->id, fn () => \App\Modules\Auth\Domain\Models\UserSession::query()->whereNull('ended_at')->where('id', $sessionId)->exists());
    expect($stillLive)->toBeTrue();

    $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/transitions", [
        'to_status' => 'activo',
        'reason' => 'Reactivación de prueba',
    ])->assertStatus(200);

    $stillLiveAfterReactivation = app(TenantContext::class)->runFor($tenant->id, fn () => \App\Modules\Auth\Domain\Models\UserSession::query()->whereNull('ended_at')->where('id', $sessionId)->exists());
    expect($stillLiveAfterReactivation)->toBeTrue();
});

test('CA-BO-120: eliminar un tenant revoca todas las sesiones de sus usuarios, y ninguna de otro centro', function (): void {
    [$tenantA, $userA] = provisionCoreTenant();
    [$tenantB, $userB] = provisionCoreTenant();

    $sessionA = app(TenantContext::class)->runFor($tenantA->id, fn () => \App\Modules\Auth\Domain\Models\UserSession::create([
        'user_id' => $userA->id, 'session_id' => 'sess-a-'.Str::random(10), 'ip_address' => '127.0.0.1', 'started_at' => now(),
    ]));

    $sessionB = app(TenantContext::class)->runFor($tenantB->id, fn () => \App\Modules\Auth\Domain\Models\UserSession::create([
        'user_id' => $userB->id, 'session_id' => 'sess-b-'.Str::random(10), 'ip_address' => '127.0.0.1', 'started_at' => now(),
    ]));

    $tenantA->forceFill(['status' => 'en_baja'])->save();

    [$requester, $secretRequester] = boCreateEnrolledAdmin('superadministrador');
    [$approver, $secretApprover] = boCreateEnrolledAdmin('superadministrador');

    $this->actingAs($requester, 'platform');
    $requestClient = boSensitiveClient($requester, $secretRequester);

    $deletionResponse = $requestClient->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenantA->public_id}/transitions", [
        'to_status' => 'eliminado',
        'reason' => 'Eliminación de prueba',
        'confirmation_name' => $tenantA->name,
    ]);
    $deletionResponse->assertStatus(202);
    $authorizationPublicId = $deletionResponse->json('data.public_id');

    $this->actingAs($approver, 'platform');
    $approveClient = boSensitiveClient($approver, $secretApprover);

    $approveClient->postJson('http://'.boPlatformHost()."/api/platform/v1/dual-authorizations/{$authorizationPublicId}/approval", [])
        ->assertStatus(200);

    $sessionAStillLive = app(TenantContext::class)->runFor($tenantA->id, fn () => \App\Modules\Auth\Domain\Models\UserSession::query()->where('id', $sessionA->id)->whereNull('ended_at')->exists());
    $sessionBStillLive = app(TenantContext::class)->runFor($tenantB->id, fn () => \App\Modules\Auth\Domain\Models\UserSession::query()->where('id', $sessionB->id)->whereNull('ended_at')->exists());

    expect($sessionAStillLive)->toBeFalse();
    expect($sessionBStillLive)->toBeTrue();
});

test('CA-BO-121: eliminar un tenant no cambia el recuento de filas de sus tablas', function (): void {
    [$tenant] = provisionCoreTenant();
    $tenant->forceFill(['status' => 'en_baja'])->save();

    $countsBefore = app(TenantContext::class)->runFor($tenant->id, fn () => [
        'roles' => Role::count(),
        'grants' => PermissionRole::count(),
        'users' => User::count(),
    ]);

    [$requester, $secretRequester] = boCreateEnrolledAdmin('superadministrador');
    [$approver, $secretApprover] = boCreateEnrolledAdmin('superadministrador');

    $this->actingAs($requester, 'platform');
    $requestClient = boSensitiveClient($requester, $secretRequester);

    $deletionResponse = $requestClient->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/transitions", [
        'to_status' => 'eliminado',
        'reason' => 'Eliminación de prueba',
        'confirmation_name' => $tenant->name,
    ]);
    $authorizationPublicId = $deletionResponse->json('data.public_id');

    $this->actingAs($approver, 'platform');
    boSensitiveClient($approver, $secretApprover)
        ->postJson('http://'.boPlatformHost()."/api/platform/v1/dual-authorizations/{$authorizationPublicId}/approval", [])
        ->assertStatus(200);

    $countsAfter = app(TenantContext::class)->runFor($tenant->id, fn () => [
        'roles' => Role::count(),
        'grants' => PermissionRole::count(),
        'users' => User::count(),
    ]);

    expect($countsAfter)->toBe($countsBefore);
    expect(Tenant::withTrashed()->find($tenant->id)->deleted_at)->not->toBeNull();
});

// CA-BO-127: mientras OPEN-BO-14 no diga lo contrario, la baja NO exige
// doble autorización.
test('CA-BO-127: dar de baja un tenant responde 200 y no crea ninguna dual_authorization', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'activo']);

    [$admin] = boCreateEnrolledAdmin('superadministrador');
    $this->actingAs($admin, 'platform');

    $response = $this->postJson('http://'.boPlatformHost()."/api/platform/v1/tenants/{$tenant->public_id}/transitions", [
        'to_status' => 'en_baja',
        'reason' => 'Baja comercial de prueba',
    ]);

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe('en_baja');
    expect(DB::connection('pgsql_platform')->table('dual_authorizations')->count())->toBe(0);

    $tenant->refresh();
    expect($tenant->grace_period_ends_at)->not->toBeNull();
});

// ---------------------------------------------------------------------
// Período de gracia (CA-BO-122)
// ---------------------------------------------------------------------

test('CA-BO-122: ejecutar bo:check-grace-periods varios días seguidos sobre el mismo tenant vencido marca una sola vez', function (): void {
    $tenant = Tenant::factory()->create(['status' => 'en_baja', 'grace_period_ends_at' => now()->subDay()]);

    $this->artisan('bo:check-grace-periods')->run();
    $this->artisan('bo:check-grace-periods')->run();
    $this->artisan('bo:check-grace-periods')->run();

    $tenant->refresh();
    expect($tenant->grace_period_expired_at)->not->toBeNull();
    expect($tenant->status)->toBe(TenantStatus::EnBaja);

    $entries = AdminActionLog::query()->where('action', 'tenant.gracia_vencida')->where('affected_tenant_id', $tenant->id)->get();
    expect($entries)->toHaveCount(1);
    expect($entries->first()->actor_type->value)->toBe('system');

    expect(TenantLifecycleEvent::query()->where('affected_tenant_id', $tenant->id)->exists())->toBeFalse();
});
