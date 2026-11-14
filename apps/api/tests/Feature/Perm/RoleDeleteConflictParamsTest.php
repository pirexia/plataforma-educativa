<?php

use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/api.md §6 y §9.2.1 (`RN-PERM-17`): el `409` de un rol con
 * asignaciones vivas lleva el recuento en `errors.role[0].params`
 * (ADR-038 §6.3), nunca como `params` de primer nivel.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// CA-PERM-118
test('CA-PERM-118: DELETE /roles/{id} de un rol con dos titulares responde 409 con errors.role[0].params.users_count y sin params de primer nivel', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-118');

    $role = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'rol_118', 'name' => 'Rol 118',
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
        ])->assertCreated()->json('public_id');

    foreach (['uno', 'dos'] as $name) {
        resetSessionState();

        test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$name}-118@example.com",
            'person' => ['given_name' => ucfirst($name), 'family_name_1' => 'Titular'],
            'send_invitation' => false,
            'role_ids' => [$role],
        ])->assertCreated();
    }

    resetSessionState();

    $response = test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/roles/{$role}"))
        ->assertStatus(409);

    $entries = $response->json('errors.role');

    expect($response->json('type'))->toBe('urn:pge:error:conflict')
        ->and($response->json())->not->toHaveKey('params')
        ->and($response->json('detail'))->not->toBe('')
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['code'])->toBe('core.validation.role_has_assignments')
        ->and($entries[0]['message'])->not->toBe('')
        ->and($entries[0]['params'])->toBe(['users_count' => 2]);

    app(TenantContext::class)->runFor($tenant->id, function () use ($role): void {
        expect(Role::query()->where('public_id', $role)->exists())->toBeTrue();
    });
});
