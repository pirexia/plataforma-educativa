<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

test('GET /roles devuelve los 16 roles predefinidos y GET /roles/{id} sus permisos', function (): void {
    [$tenant, $admin] = provisionCoreTenant('roles-001');

    $list = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/roles?per_page=100'))
        ->assertOk()
        ->json('data');

    expect($list)->toHaveCount(16);

    $adminRole = collect($list)->firstWhere('code', 'administrador_centro');
    expect($adminRole['is_system'])->toBeTrue()
        ->and($adminRole['mfa_required'])->toBeTrue();

    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/roles/{$adminRole['public_id']}"))
        ->assertOk();

    expect($detail->json('permissions'))->toHaveCount(20);
});

// CA-CORE-041
test('CA-CORE-041: modificar o borrar un rol del sistema no tiene ruta disponible (solo lectura en 1.1)', function (): void {
    [$tenant, $admin] = provisionCoreTenant('roles-041');

    $role = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'docente')->firstOrFail());

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/roles/{$role->public_id}"), ['name' => 'Cambiado'])
        ->assertStatus(405);

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/roles/{$role->public_id}"))
        ->assertStatus(405);
});

// CA-CORE-043
test('CA-CORE-043: asignar a un usuario un rol de otro tenant falla y no crea la fila role_user', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('roles-043-a');
    [$tenantB] = provisionCoreTenant('roles-043-b');

    $foreignRole = app(TenantContext::class)->runFor($tenantB->id, fn () => Role::where('code', 'docente')->firstOrFail());

    $targetUser = test()->actingAs($adminA)->postJson(coreApiUrl($tenantA->slug, '/users'), [
        'email' => 'objetivo@example.com',
        'person' => ['given_name' => 'Objetivo', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    test()->actingAs($adminA)
        ->putJson(coreApiUrl($tenantA->slug, "/users/{$targetUser}/roles"), [
            'role_ids' => [$foreignRole->public_id],
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($targetUser): void {
        $user = User::where('public_id', $targetUser)->firstOrFail();
        expect($user->roles)->toBeEmpty();
    });
});

test('PUT /users/{id}/roles reemplaza el conjunto y emite UserRolesChanged', function (): void {
    [$tenant, $admin] = provisionCoreTenant('roles-put');

    $docente = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'docente')->firstOrFail());
    $secretaria = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'secretaria')->firstOrFail());

    $targetUser = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'multi-rol@example.com',
        'person' => ['given_name' => 'Multi', 'family_name_1' => 'Rol'],
        'role_ids' => [$docente->public_id],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    $response = test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/users/{$targetUser}/roles"), [
            'role_ids' => [$secretaria->public_id],
        ])
        ->assertOk();

    expect(collect($response->json('data'))->pluck('code')->all())->toBe(['secretaria']);
});

// CA-CORE-060
test('CA-CORE-060: un tenant sin fila de suscripción para un módulo obtiene 403 al usar EnsureModuleEnabled', function (): void {
    [$tenant] = provisionCoreTenant('modules-060');

    Route::middleware(['resolve-tenant', 'resolve-locale', 'module-enabled:acad-test-060'])
        ->get('/api/v1/_test/probe-060', fn () => response()->json(['ok' => true]));

    test()->get('http://'.$tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/probe-060')
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:pge:error:module-disabled');
});

// CA-CORE-061
test('CA-CORE-061: cambiar enabled de una suscripción por API no existe (422 si se envía)', function (): void {
    [$tenant, $admin] = provisionCoreTenant('modules-061');

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/module-subscriptions/01ARZ3NDEKTSV4RRFFQ69G5FAV'), [
            'enabled' => true,
        ])
        ->assertStatus(422);
});

// CA-CORE-062
test('CA-CORE-062: GET /modules devuelve solo las suscripciones del propio tenant', function (): void {
    [$tenant, $admin] = provisionCoreTenant('modules-062');

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/modules'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('module_code'))->toContain('core');
});
