<?php

use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §20.2.1 (`RN-PERM-24`), api.md §8.4/§9.2.1, issue #356:
 * `POST /roles` con `clone_from` y un rol origen que concede algo que el
 * solicitante no posee responde 403 genérico, sin código ni ámbito (quien
 * tiene `rol.crear` puede no tener `rol.leer`). Las concesiones propias del
 * cuerpo siguen nombrando `(code, scope)`: las envió el propio solicitante.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// RPERM-013, RN-PERM-24
test('RPERM-013: #356 clonar un rol cuyas concesiones no se poseen da 403 genérico sin código ni ámbito', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-356');
    $tc = app(TenantContext::class);

    resetSessionState();
    $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'solo_crear_356', 'name' => 'Solo crear 356',
        'permissions' => [['code' => 'rol.crear', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'creador-356@example.com',
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $requester = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'creador-356@example.com')->firstOrFail());

    $originId = $tc->runFor($tenant->id, function (): string {
        $role = Role::create(['code' => 'origen_356', 'name' => 'Origen 356']);
        PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']);

        return (string) $role->public_id;
    });

    resetSessionState();
    $response = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $originId, 'code' => 'clon_356', 'name' => 'Clon 356',
    ]);

    $response->assertForbidden();

    expect($response->json('errors.grant.0.code'))->toBe('core.authorization.cannot_grant_unheld_role_permission')
        ->and($response->json('errors.grant.0.message'))->not->toBeEmpty()
        ->and($response->json('errors.grant.0'))->not->toHaveKey('params')
        ->and($response->json())->not->toHaveKey('params')
        ->and($response->json('detail'))->not->toContain('auditoria.leer')
        ->and($response->json('detail'))->not->toContain('todos')
        ->and($tc->runFor($tenant->id, fn () => Role::where('code', 'clon_356')->exists()))->toBeFalse();

    // Concesiones propias del cuerpo: sin cambio, siguen nombrando código y ámbito.
    resetSessionState();
    $own = test()->actingAs($requester)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'propio_356', 'name' => 'Propio 356',
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ]);

    $own->assertForbidden();

    expect($own->json('errors.grant.0.code'))->toBe('core.authorization.cannot_grant_unheld_permission')
        ->and($own->json('errors.grant.0.params'))->toBe(['code' => 'auditoria.leer', 'scope' => 'todos']);
});
