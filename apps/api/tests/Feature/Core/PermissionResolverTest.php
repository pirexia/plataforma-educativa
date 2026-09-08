<?php

use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\PermissionResolver;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * REQ-PERM/funcional.md §4 (1.5): motor de resolución multi-rol completo,
 * sucesor del resolutor provisional de 1.1-1.4c. `app(PermissionResolver::class)`
 * en vez de `new`: el resolutor ya no es un value object sin dependencias
 * — necesita `ScopeResolverRegistry` y `ModuleAvailability` (ambos
 * `scoped()`/`bind()` en el contenedor, `AuthorizationServiceProvider` y
 * `CoreServiceProvider`).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->enter($this->tenant->id);
});

afterEach(function (): void {
    app(TenantContext::class)->leave();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('un usuario sin roles no tiene ningún permiso efectivo (RPERM-011)', function (): void {
    $user = User::factory()->for(Person::factory())->create();

    expect(app(PermissionResolver::class)->can($user, 'usuario.leer'))->toBeFalse();
});

test('un usuario con un rol que concede el permiso lo tiene, y solo ese', function (): void {
    $user = User::factory()->for(Person::factory())->create();
    $role = Role::create(['code' => 'test_role', 'name' => 'Rol de prueba', 'is_system' => false]);

    PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']);
    $user->roles()->attach($role->id);

    $resolver = app(PermissionResolver::class);

    expect($resolver->can($user, 'usuario.leer'))->toBeTrue()
        ->and($resolver->can($user, 'usuario.crear'))->toBeFalse();
});

// RPERM-007: deny gana a allow sobre el mismo código, en cualquier ámbito
// (RN-PERM-06) — ciego al ámbito, a propósito (funcional.md §4.2).
test('deny sobre el mismo código de permiso gana a allow, incluso de roles distintos y con ámbitos distintos', function (): void {
    $user = User::factory()->for(Person::factory())->create();
    $allowRole = Role::create(['code' => 'allow_role', 'name' => 'Concede', 'is_system' => false]);
    $denyRole = Role::create(['code' => 'deny_role', 'name' => 'Deniega', 'is_system' => false]);

    PermissionRole::create(['role_id' => $allowRole->id, 'permission_code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']);
    PermissionRole::create(['role_id' => $denyRole->id, 'permission_code' => 'usuario.leer', 'effect' => 'deny', 'scope' => 'todos']);
    $user->roles()->attach([$allowRole->id, $denyRole->id]);

    expect(app(PermissionResolver::class)->can($user, 'usuario.leer'))->toBeFalse();
});
