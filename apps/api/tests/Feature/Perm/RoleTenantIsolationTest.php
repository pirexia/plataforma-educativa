<?php

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §20.14 (`CA-PERM-135`), issue #350 (H6), `INV-001`:
 * dos tenants con datos equivalentes. Autenticado en el primero, ni la
 * ficha de rol, ni sus escrituras, ni los permisos efectivos devuelven,
 * modifican o cuentan nada del segundo, y un `public_id` ajeno da 404.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Crea el rol `rol_h6` con `$members` usuarios en el tenant.
 *
 * @return array{0: string, 1: list<string>} public_id del rol y de sus usuarios
 */
function isolationSeed(object $tenant, User $admin, int $members): array
{
    resetSessionState();
    $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_h6', 'name' => 'Rol H6',
        'permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    $users = [];

    for ($i = 1; $i <= $members; $i++) {
        resetSessionState();
        test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "miembro{$i}-h6@example.com",
            'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
            'role_ids' => [$roleId],
        ])->assertCreated();

        $users[] = (string) app(TenantContext::class)->runFor(
            $tenant->id,
            fn () => User::query()->where('email', "miembro{$i}-h6@example.com")->firstOrFail()->public_id,
        );
    }

    resetSessionState();

    return [$roleId, $users];
}

// CA-PERM-135, INV-001
test('CA-PERM-135: users_count de la ficha y de las escrituras del rol no cuenta usuarios de otro tenant y un public_id ajeno da 404', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('perm-h6a');
    [$tenantB, $adminB] = provisionCoreTenant('perm-h6b');

    [$roleA] = isolationSeed($tenantA, $adminA, 1);
    [$roleB] = isolationSeed($tenantB, $adminB, 3);

    $detail = test()->actingAs($adminA)->getJson(coreApiUrl($tenantA->slug, "/roles/{$roleA}"))->assertOk();
    expect($detail->json('users_count'))->toBe(1);

    resetSessionState();
    $patched = test()->actingAs($adminA)
        ->patchJson(coreApiUrl($tenantA->slug, "/roles/{$roleA}"), ['name' => 'Rol H6 bis'])
        ->assertOk();
    expect($patched->json('users_count'))->toBe(1);

    resetSessionState();
    $replaced = test()->actingAs($adminA)
        ->putJson(coreApiUrl($tenantA->slug, "/roles/{$roleA}/permissions"), [
            'permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']],
        ])->assertOk();
    expect($replaced->json('users_count'))->toBe(1);

    resetSessionState();
    $list = test()->actingAs($adminA)->getJson(coreApiUrl($tenantA->slug, '/roles?per_page=100'))->assertOk();
    expect(collect($list->json('data'))->firstWhere('public_id', $roleA)['users_count'])->toBe(1)
        ->and(collect($list->json('data'))->pluck('public_id')->all())->not->toContain($roleB);

    // El rol de B visto desde A: 404 en lectura y en toda escritura, sin tocarlo.
    foreach ([
        ['getJson', "/roles/{$roleB}", []],
        ['patchJson', "/roles/{$roleB}", ['name' => 'Intruso']],
        ['putJson', "/roles/{$roleB}/permissions", ['permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']]]],
        ['deleteJson', "/roles/{$roleB}", []],
    ] as [$method, $path, $body]) {
        resetSessionState();
        test()->actingAs($adminA)->{$method}(coreApiUrl($tenantA->slug, $path), $body)->assertNotFound();
    }

    // B sigue intacto: su ficha, desde B, cuenta sus 3 usuarios y conserva el nombre.
    resetSessionState();
    $own = test()->actingAs($adminB)->getJson(coreApiUrl($tenantB->slug, "/roles/{$roleB}"))->assertOk();

    expect($own->json('users_count'))->toBe(3)
        ->and($own->json('name'))->toBe('Rol H6');
});

// CA-PERM-135, INV-001
test('CA-PERM-135: los permisos efectivos de un usuario de otro tenant dan 404 y los propios no mezclan tenants', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('perm-h6c');
    [$tenantB, $adminB] = provisionCoreTenant('perm-h6d');

    [, $usersA] = isolationSeed($tenantA, $adminA, 1);
    [, $usersB] = isolationSeed($tenantB, $adminB, 1);

    $own = test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/users/{$usersA[0]}/effective-permissions"))
        ->assertOk();

    expect(collect($own->json('data'))->where('decision', 'permitido')->pluck('code')->all())->toBe(['usuario.leer']);

    resetSessionState();
    test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/users/{$usersB[0]}/effective-permissions"))
        ->assertNotFound();

    resetSessionState();
    test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/users/{$adminB->public_id}/effective-permissions"))
        ->assertNotFound();
});
