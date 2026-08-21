<?php

use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\PermissionResolver;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * ADR-034 §2: el resolutor provisional lee `effect` e ignora `scope`
 * (permisos.md §5) — no hace falta sembrar un tenant completo, basta un
 * usuario, un rol y una concesión.
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

    expect((new PermissionResolver)->can($user, 'usuario.leer'))->toBeFalse();
});

test('un usuario con un rol que concede el permiso lo tiene, y solo ese', function (): void {
    $user = User::factory()->for(Person::factory())->create();
    $role = Role::create(['code' => 'test_role', 'name' => 'Rol de prueba', 'is_system' => false]);

    PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']);
    $user->roles()->attach($role->id);

    $resolver = new PermissionResolver;

    expect($resolver->can($user, 'usuario.leer'))->toBeTrue()
        ->and($resolver->can($user, 'usuario.crear'))->toBeFalse();
});

// RPERM-007: deny gana a allow sobre el mismo código, aunque 1.1 nunca
// siembre deny (RN-CORE-22) — el resolutor lo respeta desde ya.
test('deny sobre el mismo código de permiso gana a allow, incluso de roles distintos', function (): void {
    $user = User::factory()->for(Person::factory())->create();
    $allowRole = Role::create(['code' => 'allow_role', 'name' => 'Concede', 'is_system' => false]);
    $denyRole = Role::create(['code' => 'deny_role', 'name' => 'Deniega', 'is_system' => false]);

    PermissionRole::create(['role_id' => $allowRole->id, 'permission_code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']);
    PermissionRole::create(['role_id' => $denyRole->id, 'permission_code' => 'usuario.leer', 'effect' => 'deny', 'scope' => 'todos']);
    $user->roles()->attach([$allowRole->id, $denyRole->id]);

    expect((new PermissionResolver)->can($user, 'usuario.leer'))->toBeFalse();
});
