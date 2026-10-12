<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\Scope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

// RPERM-013 (1.11-b, REQ-ACAD-001, permisos.md §5 de REQ-ACAD): «nadie concede
// lo que no tiene» ignora los permisos de módulos NO contratados (inertes
// también para el destinatario); los de un módulo contratado se siguen exigiendo.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('RPERM-013 REQ-ACAD-001: quien no posee un permiso de un módulo no contratado puede concederlo; contratado, se exige', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-013-inert');

    $actor = app(TenantContext::class)->runFor($tenant->id, function (): User {
        $user = User::factory()->for(Person::factory()->create(['contact_email' => 'a13@example.com']))->create(['email' => 'a13@example.com']);
        $role = Role::query()->where('code', 'direccion')->firstOrFail();
        // `direccion` no tiene estructura_academica.crear.
        $user->roles()->attach($role->id);

        return $user;
    });

    $resolver = app(PermissionResolver::class);

    app(TenantContext::class)->runFor($tenant->id, function () use ($resolver, $actor): void {
        // No contratado: se ignora (también el que sí tiene sembrado, leer).
        expect($resolver->canGrant($actor, 'estructura_academica.crear', Scope::Todos))->toBeTrue()
            ->and($resolver->canGrant($actor, 'estructura_academica.leer', Scope::Todos))->toBeTrue()
            // Un permiso de un módulo esencial se sigue exigiendo.
            ->and($resolver->canGrant($actor, 'usuario.crear', Scope::Todos))->toBeFalse()
            // Código desconocido: falla en cerrado.
            ->and($resolver->canGrant($actor, 'inexistente.leer', Scope::Todos))->toBeFalse();
    });

    contractModule($tenant, 'acad');

    $fresh = app()->make(PermissionResolver::class);

    app(TenantContext::class)->runFor($tenant->id, function () use ($fresh, $actor): void {
        expect($fresh->canGrant($actor, 'estructura_academica.crear', Scope::Todos))->toBeFalse()
            ->and($fresh->canGrant($actor, 'estructura_academica.leer', Scope::Todos))->toBeTrue();
    });
});

test('RPERM-013 REQ-ACAD-001: el administrador de un centro sin acad contratado puede asignar y clonar roles que llevan permisos de acad', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-013-http');

    $docente = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'docente')->firstOrFail());

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'doc13@example.com',
        'person' => ['given_name' => 'Doc', 'family_name_1' => 'Ente'],
        'role_ids' => [$docente->public_id],
        'send_invitation' => false,
    ])->assertCreated();

    resetSessionState();

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $docente->public_id, 'code' => 'clon_doc_13', 'name' => 'Clon docente',
    ])->assertCreated();
});

test('RPERM-013 REQ-ACAD-001 S-1: introducir un permiso inerte NUEVO en un rol (lista explícita o PUT) exige poseerlo; mantener el que el rol ya tenía y clonar, no', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-013-new');

    $docente = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'docente')->firstOrFail());

    // Lista explícita con un permiso inerte que el solicitante no posee (acad sin contratar): 403.
    $explicit = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_nuevo_013', 'name' => 'Nuevo',
        'permissions' => [['code' => 'estructura_academica.eliminar', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertForbidden();
    expect($explicit->json('errors.grant.0.params.code'))->toBe('estructura_academica.eliminar');

    // Clonar sí (copia lo que el rol origen ya contiene).
    resetSessionState();
    $clone = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $docente->public_id, 'code' => 'clon_013', 'name' => 'Clon',
    ])->assertCreated()->json('public_id');

    // PUT: mantener lo que ya tenía (idéntico) se tolera...
    resetSessionState();
    test()->actingAs($admin)->putJson(coreApiUrl($tenant->slug, "/roles/{$clone}/permissions"), [
        'permissions' => [['code' => 'estructura_academica.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertOk();

    // ...añadir uno inerte nuevo, no.
    resetSessionState();
    test()->actingAs($admin)->putJson(coreApiUrl($tenant->slug, "/roles/{$clone}/permissions"), [
        'permissions' => [
            ['code' => 'estructura_academica.leer', 'effect' => 'allow', 'scope' => 'todos'],
            ['code' => 'estructura_academica.eliminar', 'effect' => 'allow', 'scope' => 'todos'],
        ],
    ])->assertForbidden();

    // Tras contratar el módulo (sin sesión: la suscripción no lleva autoría), el administrador lo posee y puede añadirlo.
    resetSessionState();
    contractModule($tenant, 'acad');
    test()->actingAs($admin)->putJson(coreApiUrl($tenant->slug, "/roles/{$clone}/permissions"), [
        'permissions' => [
            ['code' => 'estructura_academica.leer', 'effect' => 'allow', 'scope' => 'todos'],
            ['code' => 'estructura_academica.eliminar', 'effect' => 'allow', 'scope' => 'todos'],
        ],
    ])->assertOk();
});

test('RPERM-013 RPERM-007 REQ-ACAD-001 S-1: un deny explícito del asignador sobre un permiso inerte impide ignorarlo', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-013-deny');

    $denyRole = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_deny_013', 'name' => 'Deny',
        'permissions' => [['code' => 'estructura_academica.leer', 'effect' => 'deny', 'scope' => 'todos']],
    ])->assertCreated();
    $role = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'rol_deny_013')->firstOrFail());

    [$actor, $other] = app(TenantContext::class)->runFor($tenant->id, function () use ($role): array {
        $make = function (string $email, ?Role $with): User {
            $user = User::factory()->for(Person::factory()->create(['contact_email' => $email]))->create(['email' => $email]);
            $with === null || $user->roles()->attach($with->id);

            return $user;
        };

        return [$make('deny13@example.com', $role), $make('free13@example.com', null)];
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($actor, $other): void {
        $resolver = app(PermissionResolver::class);

        expect($resolver->canGrant($other, 'estructura_academica.leer', Scope::Todos))->toBeTrue()
            ->and($resolver->canGrant($actor, 'estructura_academica.leer', Scope::Todos))->toBeFalse();
    });

    expect($denyRole->json('public_id'))->not->toBeEmpty();
});
