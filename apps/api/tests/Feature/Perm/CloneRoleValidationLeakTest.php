<?php

use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * REQ-PERM/funcional.md §20.2.1 (`RN-PERM-24`), api.md §8.4, issue #359:
 * los 422 de validación de concesiones en `POST /roles` con `clone_from`
 * (`permission_retired`, `permission_not_found`, `scope_not_applicable`,
 * `scope_resolver_missing`) y `clone_requires_special_data_access` no
 * nombran el código ni el ámbito del rol origen — quien tiene `rol.crear`
 * puede no tener `rol.leer`. Con concesiones propias del cuerpo, nada cambia.
 *
 * `permission_not_found` no es alcanzable con un origen real:
 * `permission_role.permission_code` tiene clave foránea a `permissions`.
 */
afterEach(function (): void {
    DB::connection('pgsql_owner')->table('permissions')->where('code', 'auditoria.leer')->update(['retired_at' => null, 'applicable_scopes' => json_encode(['todos'])]);
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * @return array{0: object, 1: User, 2: callable(array<string, mixed>): string}
 */
function cloneLeakFixture(string $slug): array
{
    [$tenant, $admin] = provisionCoreTenant($slug);
    $tc = app(TenantContext::class);

    DB::connection('pgsql_owner')->table('permissions')->where('code', 'auditoria.leer')->update(['applicable_scopes' => json_encode(['todos'])]);

    resetSessionState();
    $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'solo_crear_359', 'name' => 'Solo crear 359',
        'permissions' => [['code' => 'rol.crear', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'creador-359@example.com',
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $requester = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'creador-359@example.com')->firstOrFail());

    $makeOrigin = fn (array $grant, bool $special = false): string => $tc->runFor($tenant->id, function () use ($grant, $special): string {
        $role = Role::create(['code' => 'origen_359_'.uniqid(), 'name' => 'Origen 359', 'special_data_access' => $special]);

        if ($grant !== []) {
            PermissionRole::create(['role_id' => $role->id, 'permission_code' => $grant[0], 'effect' => 'allow', 'scope' => $grant[1]]);
        }

        return (string) $role->public_id;
    });

    return [$tenant, $requester, $makeOrigin];
}

function assertNoOriginLeak(TestResponse $response, string $codeKey, string $field, string $scope = 'propios'): void
{
    $response->assertStatus(422);
    $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

    expect($response->json("errors.{$field}.0.code"))->toBe($codeKey)
        ->and($response->json("errors.{$field}.0.message"))->not->toBeEmpty()
        ->and($response->json("errors.{$field}.0.params"))->toBeEmpty()
        ->and($response->json())->not->toHaveKey('params')
        ->and($body)->not->toContain('auditoria')
        ->and($body)->not->toContain($scope);
}

// RPERM-013, RN-PERM-24
test('RPERM-013: #359 clonar un rol con un permiso retirado da 422 sin código ni ámbito', function (): void {
    [$tenant, $requester, $makeOrigin] = cloneLeakFixture('perm-359-ret');
    $originId = $makeOrigin(['auditoria.leer', 'todos']);
    DB::connection('pgsql_owner')->table('permissions')->where('code', 'auditoria.leer')->update(['retired_at' => now()]);

    resetSessionState();
    $response = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $originId, 'code' => 'clon_359_ret', 'name' => 'Clon',
    ]);

    assertNoOriginLeak($response, 'core.validation.permission_retired', 'clone_from');
    expect(app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'clon_359_ret')->exists()))->toBeFalse();

    // Concesiones propias del cuerpo: sin cambio, nombran el código.
    resetSessionState();
    $own = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'propio_359_ret', 'name' => 'Propio',
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ]);

    $own->assertStatus(422);
    expect($own->json('errors')['permissions.0.code'][0]['code'])->toBe('core.validation.permission_retired')
        ->and($own->json('errors')['permissions.0.code'][0]['params'])->toBe(['code' => 'auditoria.leer']);
});

// RPERM-013, RN-PERM-24
test('RPERM-013: #359 clonar un rol con un ámbito no aplicable da 422 sin código ni ámbito', function (): void {
    [$tenant, $requester, $makeOrigin] = cloneLeakFixture('perm-359-na');
    $originId = $makeOrigin(['auditoria.leer', 'propios']);

    resetSessionState();
    $response = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $originId, 'code' => 'clon_359_na', 'name' => 'Clon',
    ]);

    assertNoOriginLeak($response, 'core.validation.scope_not_applicable', 'clone_from');

    resetSessionState();
    $own = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'propio_359_na', 'name' => 'Propio',
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
    ]);

    $own->assertStatus(422);
    expect($own->json('errors')['permissions.0.scope'][0]['params']['scope'])->toBe('propios');
});

// RPERM-013, RN-PERM-24
test('RPERM-013: #359 clonar un rol con un ámbito sin resolutor da 422 sin código ni ámbito', function (): void {
    [$tenant, $requester, $makeOrigin] = cloneLeakFixture('perm-359-res');
    DB::connection('pgsql_owner')->table('permissions')->where('code', 'auditoria.leer')->update(['applicable_scopes' => json_encode(['todos', 'grupo'])]);
    $originId = $makeOrigin(['auditoria.leer', 'grupo']);

    resetSessionState();
    $response = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $originId, 'code' => 'clon_359_res', 'name' => 'Clon',
    ]);

    assertNoOriginLeak($response, 'core.validation.scope_resolver_missing', 'clone_from', 'grupo');

    resetSessionState();
    $own = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'propio_359_res', 'name' => 'Propio',
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'grupo']],
    ]);

    $own->assertStatus(422);
    expect($own->json('errors')['permissions.0.scope'][0]['code'])->toBe('core.validation.scope_resolver_missing')
        ->and($own->json('errors')['permissions.0.scope'][0]['params']['scope'])->toBe('grupo');
});

// RPERM-013, RN-PERM-24
test('RPERM-013: #359 clonar un rol con special_data_access sin poder activarlo da 422 genérico', function (): void {
    [$tenant, $requester, $makeOrigin] = cloneLeakFixture('perm-359-sda');
    $originId = $makeOrigin([], true);

    resetSessionState();
    $response = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $originId, 'code' => 'clon_359_sda', 'name' => 'Clon',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.special_data_access.0.code'))->toBe('core.validation.clone_requires_special_data_access')
        ->and($response->json('errors.special_data_access.0.params'))->toBeEmpty()
        ->and(json_encode($response->json(), JSON_UNESCAPED_UNICODE))->not->toContain('categoría especial')
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'clon_359_sda')->exists()))->toBeFalse();
});
