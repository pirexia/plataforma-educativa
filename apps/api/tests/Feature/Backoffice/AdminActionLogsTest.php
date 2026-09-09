<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * REQ-BO-007, api.md §2.9, §2.14. CA-BO-020, CA-BO-022: toda escritura de
 * éxito deja rastro completo en `admin_action_logs`, y el propio centro
 * solo ve, desde `GET /api/v1/platform-actions`, lo que le afecta a él.
 *
 * CA-BO-023 y CA-BO-024 no son verificables en 1.6: exigen una
 * suspensión y el ciclo de vida completo de `Tenant`
 * (`POST /tenants/{id}/transitions`), que es `1.6b` (funcional.md §12.2,
 * §12.2.1 — issue #27 lo cierra explícitamente `1.6b`, no `1.6`). Quedan
 * para el sub-paso que construye ese endpoint.
 */
function alAllowCurrentTestIp(): void
{
    PlatformIpAllowlistEntry::create([
        'cidr' => '127.0.0.1/32',
        'description' => 'Suite de tests',
        'enabled' => true,
    ]);
}

/**
 * @return array{0: PlatformAdmin, 1: string}
 */
function alCreateEnrolledAdmin(string $role = 'superadministrador'): array
{
    $secret = (new Google2FA)->generateSecretKey();

    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'Admin de prueba',
        'password' => Hash::make('contraseña-larga-de-prueba'),
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
        'mfa_enrolled_at' => now(),
    ]);

    PlatformAdminRole::create(['platform_admin_id' => $admin->id, 'role' => PlatformRole::from($role)]);

    PlatformAdminMfaFactor::create([
        'platform_admin_id' => $admin->id,
        'type' => 'totp',
        'secret_encrypted' => $secret,
        'confirmed_at' => now(),
    ]);

    return [$admin, $secret];
}

// TRUNCATE de admin_action_logs en beforeEach, no en afterEach
// (PlatformSchemaGrantsTest.php ya deja escrito por qué, y CA-BO-022
// lo confirma de nuevo aquí): `TestCase::$connectionsToTransact`
// mantiene la transacción de `pgsql` abierta durante todo el test, y
// `GET /api/v1/platform-actions` consulta `admin_action_logs` por esa
// misma conexión — un TRUNCATE por `pgsql_owner` dentro del mismo test
// (en un `afterEach`, o inline) espera un bloqueo que esa transacción,
// todavía abierta, retiene hasta el `tearDown()` de PHPUnit, posterior
// a los `afterEach` de Pest. Limpiar al EMPEZAR el siguiente test evita
// el bloqueo. `afterAll()` recoge el último test del fichero.
beforeEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

afterAll(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// CA-BO-020: toda operación de escritura del backoffice que termina con
// éxito deja entrada con actor, acción, sujeto, IP, user_agent y
// request_id.
test('CA-BO-020: una escritura exitosa del backoffice deja entrada completa en admin_action_logs', function (): void {
    alAllowCurrentTestIp();
    [$admin] = alCreateEnrolledAdmin();

    $this->actingAs($admin, 'platform');

    $response = $this->patchJson(
        'http://'.config('backoffice.host')."/api/platform/v1/admins/{$admin->public_id}",
        ['name' => 'Nombre Actualizado', 'locale' => 'en'],
        ['User-Agent' => 'Suite CA-BO-020'],
    );

    $response->assertOk();

    $entry = AdminActionLog::query()->where('action', 'admin.actualizado')->where('subject_public_id', $admin->public_id)->first();

    expect($entry)->not->toBeNull();
    expect($entry->actor_type->value)->toBe('platform_admin');
    expect($entry->actor_platform_admin_id)->toBe($admin->id);
    expect($entry->subject_public_id)->toBe($admin->public_id);
    expect($entry->ip_address)->not->toBeNull();
    expect($entry->user_agent)->toBe('Suite CA-BO-020');
    expect($entry->request_id)->not->toBeNull();
});

// CA-BO-022: el propio centro solo ve, en GET /api/v1/platform-actions,
// las entradas con su affected_tenant_id, y ninguna otra, ni las de
// alcance global.
test('CA-BO-022: GET /api/v1/platform-actions solo devuelve lo que afecta al propio centro', function (): void {
    [$tenant, $tenantAdmin] = provisionCoreTenant('platform-actions-022');
    $otherTenant = Tenant::factory()->create();

    $ownPublicId = (string) Str::ulid();
    $otherPublicId = (string) Str::ulid();
    $globalPublicId = (string) Str::ulid();

    DB::connection('pgsql_platform')->table('admin_action_logs')->insert([
        ['public_id' => $ownPublicId, 'occurred_at' => now(), 'actor_type' => 'system', 'affected_tenant_id' => $tenant->id, 'subject_type' => 'platform', 'action' => 'tenant.actualizado'],
        ['public_id' => $otherPublicId, 'occurred_at' => now(), 'actor_type' => 'system', 'affected_tenant_id' => $otherTenant->id, 'subject_type' => 'platform', 'action' => 'tenant.actualizado'],
        ['public_id' => $globalPublicId, 'occurred_at' => now(), 'actor_type' => 'system', 'affected_tenant_id' => null, 'subject_type' => 'platform', 'action' => 'modulo.masivo_ejecutado'],
    ]);

    $response = test()->actingAs($tenantAdmin)
        ->getJson(coreApiUrl($tenant->slug, '/platform-actions'))
        ->assertOk();

    $publicIds = collect($response->json('data'))->pluck('public_id');

    expect($publicIds)->toContain($ownPublicId);
    expect($publicIds)->not->toContain($otherPublicId);
    expect($publicIds)->not->toContain($globalPublicId);

    // Sin TRUNCATE aquí: la transacción de `pgsql` de este mismo test
    // sigue abierta (la consulta de arriba pasó por ella) y un TRUNCATE
    // ahora se bloquearía a sí mismo. `beforeEach` del siguiente test
    // limpia esta tabla.
});

// CA-BO-022 (complemento): sin auditoria.leer, 403.
test('CA-BO-022: sin auditoria.leer, GET /api/v1/platform-actions responde 403', function (): void {
    [$tenant] = provisionCoreTenant('platform-actions-022b');

    $secretaria = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create(['contact_email' => 'sec-022@example.com']);
        $user = User::factory()->for($person)->create(['email' => 'sec-022@example.com']);
        $role = Role::query()->where('code', 'secretaria')->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    });

    test()->actingAs($secretaria)
        ->getJson(coreApiUrl($tenant->slug, '/platform-actions'))
        ->assertStatus(403);
});
