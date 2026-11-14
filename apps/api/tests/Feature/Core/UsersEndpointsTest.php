<?php

use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function attachRole(Tenant $tenant, string $email, string $roleCode): User
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($email, $roleCode) {
        $person = Person::factory()->create(['contact_email' => $email]);
        $user = User::factory()->for($person)->create(['email' => $email]);
        $role = Role::where('code', $roleCode)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    });
}

// CA-CORE-010
test('CA-CORE-010: crear un usuario devuelve 201 con public_id ULID, Person+User pendiente', function (): void {
    [$tenant, $admin] = provisionCoreTenant('users-010');

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => 'nueva.docente@example.com',
            'person' => ['given_name' => 'Marta', 'family_name_1' => 'Ruiz'],
            'send_invitation' => false,
        ])
        ->assertCreated();

    expect($response->json('public_id'))->toMatch('/^[0-9A-Z]{26}$/')
        ->and($response->json('status'))->toBe('pendiente')
        ->and($response->json('person.given_name'))->toBe('Marta');
});

// CA-CORE-011
test('CA-CORE-011: correo ya usado por un usuario vivo del mismo tenant devuelve 422', function (): void {
    [$tenant, $admin] = provisionCoreTenant('users-011');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'repetido@example.com',
        'person' => ['given_name' => 'A', 'family_name_1' => 'B'],
        'send_invitation' => false,
    ])->assertCreated();

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'repetido@example.com',
        'person' => ['given_name' => 'C', 'family_name_1' => 'D'],
        'send_invitation' => false,
    ])->assertStatus(422);
});

// CA-CORE-012
test('CA-CORE-012: el mismo correo en dos tenants distintos tiene éxito en ambos y no se mezclan', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('users-012-a');
    [$tenantB, $adminB] = provisionCoreTenant('users-012-b');

    test()->actingAs($adminA)->postJson(coreApiUrl($tenantA->slug, '/users'), [
        'email' => 'compartido@example.com',
        'person' => ['given_name' => 'A', 'family_name_1' => 'Uno'],
        'send_invitation' => false,
    ])->assertCreated();

    test()->actingAs($adminB)->postJson(coreApiUrl($tenantB->slug, '/users'), [
        'email' => 'compartido@example.com',
        'person' => ['given_name' => 'B', 'family_name_1' => 'Dos'],
        'send_invitation' => false,
    ])->assertCreated();

    $listA = test()->actingAs($adminA)->getJson(coreApiUrl($tenantA->slug, '/users?per_page=100'))->json('data');
    expect(collect($listA)->pluck('person.given_name'))->not->toContain('B');
});

// CA-CORE-013
test('CA-CORE-013: crear un usuario nuevo con el correo de uno dado de baja lógica tiene éxito', function (): void {
    [$tenant, $admin] = provisionCoreTenant('users-013');

    $first = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'reciclado@example.com',
        'person' => ['given_name' => 'Viejo', 'family_name_1' => 'Usuario'],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    test()->actingAs($admin)->deleteJson(coreApiUrl($tenant->slug, "/users/{$first}"))->assertStatus(204);

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'reciclado@example.com',
        'person' => ['given_name' => 'Nuevo', 'family_name_1' => 'Usuario'],
        'send_invitation' => false,
    ])->assertCreated();

    app(TenantContext::class)->runFor($tenant->id, function () use ($first): void {
        $old = User::withTrashed()->where('public_id', $first)->firstOrFail();
        expect($old->deleted_at)->not->toBeNull();
    });
});

// CA-CORE-014
test('CA-CORE-014: eliminar un usuario lo deja con deleted_at, y 404 salvo include_deleted', function (): void {
    [$tenant, $admin] = provisionCoreTenant('users-014');

    $publicId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'baja@example.com',
        'person' => ['given_name' => 'Baja', 'family_name_1' => 'Logica'],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    test()->actingAs($admin)->deleteJson(coreApiUrl($tenant->slug, "/users/{$publicId}"))->assertStatus(204);

    test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, "/users/{$publicId}"))->assertStatus(404);

    test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/users?include_deleted=true&per_page=100'))
        ->assertOk()
        ->assertJsonFragment(['public_id' => $publicId]);
});

// CA-CORE-015. Con la matriz de permisos.md §4.1, solo administrador_centro
// tiene usuario.eliminar en 1.1 — para ejercitar RN-CORE-07 con un actor
// que no sea el propio objetivo (y no tropezar antes con RN-CORE-06), se
// concede el permiso a docente para este test, igual que CA-CORE-017
// aísla RPERM-013 concediendo permisos ad hoc a secretaria.
test('CA-CORE-015: dar de baja al único Administrador de Centro vivo devuelve 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant('users-015');
    $other = attachRole($tenant, 'otro-admin@example.com', 'docente');

    app(TenantContext::class)->runFor($tenant->id, function () use ($other): void {
        PermissionRole::create([
            'role_id' => $other->roles()->first()->id,
            'permission_code' => 'usuario.eliminar',
            'effect' => 'allow',
            'scope' => 'todos',
        ]);
    });

    test()->actingAs($other)
        ->deleteJson(coreApiUrl($tenant->slug, "/users/{$admin->public_id}"))
        ->assertStatus(409);
});

// CA-CORE-016
test('CA-CORE-016: un usuario no puede darse de baja a sí mismo', function (): void {
    [$tenant, $admin] = provisionCoreTenant('users-016');
    $secondAdmin = attachRole($tenant, 'segundo-admin@example.com', 'administrador_centro');

    test()->actingAs($secondAdmin)
        ->deleteJson(coreApiUrl($tenant->slug, "/users/{$secondAdmin->public_id}"))
        ->assertStatus(409);
});

// CA-CORE-017 (RPERM-013)
test('CA-CORE-017: no se puede asignar al crear un rol con permisos que el solicitante no posee', function (): void {
    [$tenant, $admin] = provisionCoreTenant('users-017');
    // secretaria: usuario.leer, invitacion.leer — no auditoria.leer.
    $secretaria = attachRole($tenant, 'secretaria@example.com', 'secretaria');

    // secretaria no tiene usuario.crear, así que la ruta ya la bloquearía en 403
    // por el propio middleware — para aislar RPERM-013 se le concede
    // usuario.crear y asignacion_rol.crear directamente y se comprueba que
    // aun así no puede conceder administrador_centro (que sí tiene
    // auditoria.leer, permiso que secretaria no posee).
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $role = Role::where('code', 'secretaria')->firstOrFail();
        PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'usuario.crear', 'effect' => 'allow', 'scope' => 'todos']);
        PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'asignacion_rol.crear', 'effect' => 'allow', 'scope' => 'todos']);
    });

    $adminCentroRole = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'administrador_centro')->firstOrFail());

    test()->actingAs($secretaria)
        ->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => 'intento@example.com',
            'person' => ['given_name' => 'Intento', 'family_name_1' => 'Fallido'],
            'role_ids' => [$adminCentroRole->public_id],
            'send_invitation' => false,
        ])
        ->assertStatus(403);
});

// CA-CORE-018
test('CA-CORE-018: PATCH /me cambia el idioma preferido; email/estado/roles se ignoran', function (): void {
    [$tenant, $admin] = provisionCoreTenant('users-018');

    // RN-CORE-13: el idioma solo puede fijarse entre los activos del
    // centro — por defecto solo es_ES (funcional.md §4.7), así que se
    // activa "en" antes de poder elegirlo.
    test()->actingAs($admin)->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
        'regional' => ['active_locales' => ['es-ES', 'en']],
    ])->assertOk();

    $response = test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/me'), [
            'person' => ['locale' => 'en'],
            'email' => 'otro@example.com',
            'status' => 'inactivo',
        ])
        ->assertOk();

    expect($response->json('person.locale'))->toBe('en')
        ->and($response->json('email'))->toBe('admin@example.com')
        ->and($response->json('status'))->toBe('pendiente');
});

// CA-CORE-019
test('CA-CORE-019: un usuario sin permisos de REQ-CORE recibe 403 en GET /users', function (): void {
    [$tenant] = provisionCoreTenant('users-019');
    $docente = attachRole($tenant, 'sin-permiso@example.com', 'docente');

    test()->actingAs($docente)
        ->getJson(coreApiUrl($tenant->slug, '/users'))
        ->assertStatus(403);
});
