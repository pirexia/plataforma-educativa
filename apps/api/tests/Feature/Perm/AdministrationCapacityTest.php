<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Application\AdministrationCapacityGuard;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §20.2.1 (`RN-PERM-47`), api.md §14.5 (1.5b):
 * el centro nunca pierde la capacidad completa de administración.
 *
 * `provisionCoreTenant()` deja al primer administrador `pendiente`: los
 * tests que necesitan un centro que **cumple** lo activan con
 * `capacityActivate()`.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * `actingAs` con la sesión limpia: varias identidades y varias peticiones
 * en el mismo test arrastrarían el actor anterior (issue #83).
 */
function capacityAs(User $actor): mixed
{
    resetSessionState();

    return test()->actingAs($actor);
}

function capacityActivate(object $tenant, string $email): void
{
    app(TenantContext::class)->runFor($tenant->id, function () use ($email): void {
        User::query()->where('email', $email)->firstOrFail()->forceFill(['status' => 'activo'])->save();
    });
}

function capacityRolePublicId(object $tenant, string $code): string
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => (string) Role::query()->where('code', $code)->firstOrFail()->public_id);
}

function capacityUser(object $tenant, string $email): User
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => User::query()->where('email', $email)->firstOrFail());
}

/**
 * Crea un usuario activo con los roles indicados, actuando como `$actor`.
 *
 * @param  list<string>  $roleIds
 */
function capacityCreateUser(object $tenant, User $actor, string $email, array $roleIds): User
{
    resetSessionState();

    capacityAs($actor)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => $email,
        'person' => ['given_name' => 'Luis', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => $roleIds,
    ])->assertCreated();

    capacityActivate($tenant, $email);

    resetSessionState();

    return capacityUser($tenant, $email);
}

/**
 * Cuerpo de `PUT /roles/{id}/permissions` igual a las concesiones actuales
 * del rol, quitando `$without` y aplicando `$overrides` (código → [effect, scope]).
 *
 * @param  list<string>  $without
 * @param  array<string, array{0: string, 1: string}>  $overrides
 * @return array{permissions: list<array{code: string, effect: string, scope: string}>}
 */
function capacityRoleBody(object $tenant, User $actor, string $rolePublicId, array $without = [], array $overrides = []): array
{
    resetSessionState();

    $role = capacityAs($actor)->getJson(coreApiUrl($tenant->slug, "/roles/{$rolePublicId}"))->assertOk();

    resetSessionState();

    $entries = [];

    foreach ($role->json('permissions') as $grant) {
        if (in_array($grant['code'], $without, true)) {
            continue;
        }

        [$effect, $scope] = $overrides[$grant['code']] ?? [$grant['effect'], $grant['scope']];
        $entries[] = ['code' => $grant['code'], 'effect' => $effect, 'scope' => $scope];
    }

    return ['permissions' => $entries];
}

/**
 * `PUT /roles/{id}/permissions` con las concesiones actuales del rol menos
 * `$without` y con `$overrides` aplicados. El cuerpo se construye antes de
 * fijar el actor: leer el rol limpia la sesión.
 *
 * @param  list<string>  $without
 * @param  array<string, array{0: string, 1: string}>  $overrides
 */
function capacityPutRole(object $tenant, User $actor, string $rolePublicId, array $without = [], array $overrides = []): mixed
{
    $body = capacityRoleBody($tenant, $actor, $rolePublicId, $without, $overrides);

    return capacityAs($actor)->putJson(coreApiUrl($tenant->slug, "/roles/{$rolePublicId}/permissions"), $body);
}

function capacityAuditCount(object $tenant): int
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->count());
}

/**
 * @return list<string> `code|effect|scope` de las concesiones del rol, ordenadas
 */
function capacityGrantsOf(object $tenant, string $roleCode): array
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($roleCode): array {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        return $role->permissionGrants()->get()
            ->map(fn (PermissionRole $grant) => "{$grant->permission_code}|{$grant->effect}|{$grant->scope}")
            ->sort()->values()->all();
    });
}

// CA-PERM-046
test('CA-PERM-046: retirar o denegar un permiso de administración al único titular completo responde 409 y no guarda nada', function (): void {
    [$tenant, $ana] = provisionCoreTenant('perm-046');
    capacityActivate($tenant, 'admin@example.com');

    $adminRole = capacityRolePublicId($tenant, 'administrador_centro');

    // Rol personalizado `gestion`, concedido por Ana con un subconjunto de sus permisos.
    capacityAs($ana)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'gestion',
        'name' => 'Gestión',
        'permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated();

    $grantsBefore = capacityGrantsOf($tenant, 'administrador_centro');
    $auditBefore = capacityAuditCount($tenant);

    // Sin rol.actualizar → 409.
    $response = capacityPutRole($tenant, $ana, $adminRole, without: ['rol.actualizar'])
        ->assertStatus(409);

    expect($response->json('type'))->toBe('urn:pge:error:conflict')
        ->and($response->json('detail'))->toContain('rol.actualizar')
        ->and(capacityGrantsOf($tenant, 'administrador_centro'))->toBe($grantsBefore)
        ->and(capacityAuditCount($tenant))->toBe($auditBefore);

    // usuario.crear pasa a deny → 409.
    resetSessionState();

    $response = capacityPutRole($tenant, $ana, $adminRole, overrides: ['usuario.crear' => ['deny', 'todos']])
        ->assertStatus(409);

    expect($response->json('detail'))->toContain('usuario.crear')
        ->and(capacityGrantsOf($tenant, 'administrador_centro'))->toBe($grantsBefore);

    // Con un segundo administrador activo (mismo rol) sigue siendo 409: ambos lo pierden.
    $luis = capacityCreateUser($tenant, $ana, 'luis@example.com', [$adminRole]);

    $response = capacityPutRole($tenant, $ana, $adminRole, without: ['rol.actualizar'])
        ->assertStatus(409);

    expect($response->json('detail'))->toContain('rol.actualizar');

    // Si Luis tiene además un rol personalizado con TODO el conjunto con `todos`, la petición pasa.
    resetSessionState();

    $full = array_map(
        fn (string $code) => ['code' => $code, 'effect' => 'allow', 'scope' => 'todos'],
        ProvisionTenantDefaults::ADMIN_CENTRO_PERMISSIONS,
    );

    $fullRole = capacityAs($ana)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'administracion_completa',
        'name' => 'Administración completa',
        'permissions' => $full,
    ])->assertCreated()->json('public_id');

    resetSessionState();

    capacityAs($ana)->putJson(coreApiUrl($tenant->slug, "/users/{$luis->public_id}/roles"), [
        'role_ids' => [$adminRole, $fullRole],
    ])->assertOk();

    resetSessionState();

    capacityPutRole($tenant, $ana, $adminRole, without: ['rol.actualizar'])
        ->assertOk();

    expect(capacityGrantsOf($tenant, 'administrador_centro'))->not->toContain('rol.actualizar|allow|todos');
});

// CA-PERM-047
test('CA-PERM-047: asignar un rol con deny, desactivar o dar de baja al último titular completo responde 409 aunque RN-CORE-07 lo permitiera', function (): void {
    [$tenant, $ana] = provisionCoreTenant('perm-047');
    capacityActivate($tenant, 'admin@example.com');

    $adminRole = capacityRolePublicId($tenant, 'administrador_centro');

    $luis = capacityCreateUser($tenant, $ana, 'luis@example.com', [$adminRole]);

    $restriccion = capacityAs($ana)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'restriccion',
        'name' => 'Restricción',
        'permissions' => [['code' => 'mfa.eliminar', 'effect' => 'deny', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    // Ana asigna `restriccion` a Luis: Ana conserva el conjunto completo.
    resetSessionState();

    capacityAs($ana)->putJson(coreApiUrl($tenant->slug, "/users/{$luis->public_id}/roles"), [
        'role_ids' => [$adminRole, $restriccion],
    ])->assertOk();

    // Luis asigna `restriccion` a Ana: nadie conservaría mfa.eliminar.
    resetSessionState();

    $rolesBefore = app(TenantContext::class)->runFor($tenant->id, fn () => $ana->roles()->pluck('roles.id')->sort()->values()->all());

    $response = capacityAs($luis)->putJson(coreApiUrl($tenant->slug, "/users/{$ana->public_id}/roles"), [
        'role_ids' => [$adminRole, $restriccion],
    ])->assertStatus(409);

    expect($response->json('detail'))->toContain('mfa.eliminar')
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => $ana->roles()->pluck('roles.id')->sort()->values()->all()))->toBe($rolesBefore);

    // Luis desactiva a Ana: RN-CORE-07 lo permitiría (Luis es administrador_centro activo), RN-PERM-47 no.
    resetSessionState();

    $response = capacityAs($luis)->postJson(coreApiUrl($tenant->slug, "/users/{$ana->public_id}/status"), ['status' => 'inactivo'])
        ->assertStatus(409);

    expect($response->json('detail'))->toContain('mfa.eliminar');
    expect(capacityUser($tenant, 'admin@example.com')->status->value)->toBe('activo');

    // DELETE igual.
    resetSessionState();

    capacityAs($luis)->deleteJson(coreApiUrl($tenant->slug, "/users/{$ana->public_id}"))->assertStatus(409);

    expect(capacityUser($tenant, 'admin@example.com')->deleted_at)->toBeNull();

    // Reactivar a alguien nunca se rechaza por esta regla.
    resetSessionState();

    capacityAs($ana)->postJson(coreApiUrl($tenant->slug, "/users/{$luis->public_id}/status"), ['status' => 'inactivo'])->assertOk();

    resetSessionState();

    capacityAs($ana)->postJson(coreApiUrl($tenant->slug, "/users/{$luis->public_id}/status"), ['status' => 'activo'])->assertOk();
});

// CA-PERM-048
test('CA-PERM-048: un centro que aún no cumplía no ve rechazada ninguna escritura, y un permiso retirado o de módulo no utilizable queda fuera del conjunto', function (): void {
    [$tenant, $ana] = provisionCoreTenant('perm-048');

    $adminRole = capacityRolePublicId($tenant, 'administrador_centro');

    // El único administrador sigue `pendiente`: el centro no cumple todavía.
    $custom = capacityAs($ana)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_048', 'name' => 'Rol 048',
    ])->assertCreated()->json('public_id');

    resetSessionState();

    capacityAs($ana)->putJson(coreApiUrl($tenant->slug, "/roles/{$custom}/permissions"), [
        'permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertOk();

    // Ana pasa a `activo`: ahora el centro cumple y la regla empieza a proteger.
    capacityActivate($tenant, 'admin@example.com');

    // Permiso retirado del catálogo (`retired_at`): ya no forma parte del conjunto.
    try {
        DB::connection('pgsql_owner')->table('permissions')->where('code', 'usuario.importar')->update(['retired_at' => now()]);

        resetSessionState();

        capacityPutRole($tenant, $ana, $adminRole, without: ['usuario.importar'])
            ->assertOk();
    } finally {
        DB::connection('pgsql_owner')->table('permissions')->where('code', 'usuario.importar')->update(['retired_at' => null]);
    }

    // Permiso de un módulo no utilizable para el tenant: `auth` fuera, `mfa.eliminar` ya no cuenta.
    app()->instance(ModuleAvailability::class, new class implements ModuleAvailability
    {
        public function isEnabled(string $moduleCode): bool
        {
            return $moduleCode !== 'auth';
        }
    });
    app()->forgetInstance(AdministrationCapacityGuard::class);
    app()->forgetScopedInstances();

    resetSessionState();

    capacityPutRole($tenant, $ana, $adminRole, without: ['mfa.eliminar'])
        ->assertOk();

    expect(Permission::query()->where('code', 'mfa.eliminar')->exists())->toBeTrue();
});

// CA-PERM-049
test('CA-PERM-049: la comprobación y la escritura se serializan por tenant con un bloqueo de transacción, y la segunda escritura ve el estado de la primera', function (): void {
    [$tenant, $ana] = provisionCoreTenant('perm-049');
    capacityActivate($tenant, 'admin@example.com');

    $adminRole = capacityRolePublicId($tenant, 'administrador_centro');
    $luis = capacityCreateUser($tenant, $ana, 'luis@example.com', [$adminRole]);

    // Mecanismo: antes de `protect()` el bloqueo de ese tenant está libre;
    // mientras `protect()` ejecuta la escritura, otra conexión no puede
    // tomarlo, y el de otro tenant sigue libre. Es el criterio de
    // serialización de §20.17.1 cubierto a nivel de mecanismo: el entorno
    // de test no solapa transacciones de dos procesos de forma fiable.
    // Que el bloqueo se libera al cerrar la transacción es semántica de
    // `pg_advisory_xact_lock`; aquí no se puede observar porque cada test
    // corre dentro de una transacción externa (`DatabaseTransactions`).
    $ruleKey = (new ReflectionClassConstant(AdministrationCapacityGuard::class, 'LOCK_RULE_KEY'))->getValue();
    $tenantKey = $tenant->id % 2_147_483_647;
    $other = DB::connection('pgsql_owner');

    $tryLock = function (int $key) use ($other, $ruleKey): bool {
        $other->beginTransaction();

        try {
            return (bool) $other->selectOne('select pg_try_advisory_xact_lock(?, ?) as locked', [$ruleKey, $key])->locked;
        } finally {
            $other->rollBack();
        }
    };

    $observed = ['before' => $tryLock($tenantKey)];

    app(TenantContext::class)->runFor($tenant->id, function () use ($tryLock, $tenantKey, &$observed): void {
        app(AdministrationCapacityGuard::class)->protect(function () use ($tryLock, $tenantKey, &$observed): void {
            $observed['during'] = $tryLock($tenantKey);
            $observed['duringOtherTenant'] = $tryLock($tenantKey + 1);
        });
    });

    expect($observed['before'])->toBeTrue()
        ->and($observed['during'])->toBeFalse()
        ->and($observed['duringOtherTenant'])->toBeTrue();

    // Efecto observable, dos escrituras seguidas que por separado cumplen: la
    // primera pasa (Luis conserva todo), la segunda ve su estado y recibe 409.
    resetSessionState();

    $restriccionA = capacityAs($ana)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'veto_a', 'name' => 'Veto A',
        'permissions' => [['code' => 'usuario.crear', 'effect' => 'deny', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    resetSessionState();

    $restriccionB = capacityAs($ana)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'veto_b', 'name' => 'Veto B',
        'permissions' => [['code' => 'usuario.eliminar', 'effect' => 'deny', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    resetSessionState();

    // Luis recibe el veto A: Ana sigue completa, pasa.
    capacityAs($ana)->putJson(coreApiUrl($tenant->slug, "/users/{$luis->public_id}/roles"), [
        'role_ids' => [$adminRole, $restriccionA],
    ])->assertOk();

    // Ana recibe el veto B: Luis tiene el veto A, Ana tendría el B; nadie completo → 409.
    resetSessionState();

    capacityAs($luis)->putJson(coreApiUrl($tenant->slug, "/users/{$ana->public_id}/roles"), [
        'role_ids' => [$adminRole, $restriccionB],
    ])->assertStatus(409);
});

// CA-PERM-048
test('CA-PERM-048: con el único administrador pendiente, ni siquiera retirar rol.actualizar del rol de administración se rechaza', function (): void {
    [$tenant, $ana] = provisionCoreTenant('perm-048b');

    $adminRole = capacityRolePublicId($tenant, 'administrador_centro');

    // El centro no cumplía antes (administrador `pendiente`): la regla solo
    // rechaza pasar de cumplir a no cumplir.
    capacityPutRole($tenant, $ana, $adminRole, without: ['rol.actualizar'])->assertOk();

    expect(capacityGrantsOf($tenant, 'administrador_centro'))->not->toContain('rol.actualizar|allow|todos');
});
