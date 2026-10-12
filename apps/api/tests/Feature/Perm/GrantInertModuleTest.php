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
