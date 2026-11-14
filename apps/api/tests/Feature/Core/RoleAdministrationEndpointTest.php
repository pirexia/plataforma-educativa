<?php

use App\Models\AuditLog;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/api.md §3-§6 (1.5): alta, clonación, edición completa, concesión
 * y revocación de permisos, y baja de rol. `provisionCoreTenant()` da un
 * `administrador_centro` con los cuatro permisos nuevos de `permisos.md §5`
 * ya sembrados (`rol.crear`, `rol.eliminar`, `rol_datos_especiales.actualizar`,
 * `permiso_efectivo.leer`).
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// CA-PERM-050
test('CA-PERM-050: crear un rol personalizado por API deja is_system=false, name literal y code único', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-050');

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'coordinacion_auditoria',
            'name' => 'Coordinación de auditoría',
            'permissions' => [
                ['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios'],
            ],
        ])
        ->assertCreated();

    expect($response->json('is_system'))->toBeFalse()
        ->and($response->json('name'))->toBe('Coordinación de auditoría')
        ->and($response->json('permissions.0.code'))->toBe('auditoria.leer')
        ->and($response->json('permissions.0.scope'))->toBe('propios');

    // Mismo code vivo ⇒ 422.
    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), ['code' => 'coordinacion_auditoria', 'name' => 'Otra'])
        ->assertStatus(422);
});

// CA-PERM-004
test('CA-PERM-004: conceder un ámbito no admitido por el permiso responde 422 y no guarda nada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-004');

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'rol_004',
            'name' => 'Rol 004',
            'permissions' => [
                ['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'propios'],
            ],
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(Role::where('code', 'rol_004')->exists())->toBeFalse();
    });
});

// CA-PERM-005: ámbito admitido por el permiso pero sin resolutor registrado.
test('CA-PERM-005: un ámbito sin resolutor registrado responde 422 con scope_resolver_missing', function (): void {
    // provisionCoreTenant() ejecuta `platform:sync-registry`, que marca
    // `retired_at` cualquier código no declarado por un ServiceProvider
    // real — así que el permiso de prueba se inserta DESPUÉS, o quedaría
    // retirado antes de que el test llegue a usarlo.
    [$tenant, $admin] = provisionCoreTenant('perm-005');

    DB::connection('pgsql_owner')->table('permissions')->updateOrInsert(
        ['code' => 'sondeo_test.leer'],
        ['resource' => 'sondeo_test', 'action' => 'leer', 'module_code' => 'test_scope_ausente',
            'is_special_category' => false, 'applicable_scopes' => json_encode(['todos', 'grupo']), 'retired_at' => null],
    );

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'rol_005',
            'name' => 'Rol 005',
            'permissions' => [
                ['code' => 'sondeo_test.leer', 'effect' => 'allow', 'scope' => 'grupo'],
            ],
        ])
        ->assertStatus(422);

    // ValidationErrorBag::add() usa el campo completo ("permissions.0.scope")
    // como clave literal del array de errores, no como ruta anidada.
    expect($response->json('errors')['permissions.0.scope'][0]['code'])->toBe('core.validation.scope_resolver_missing');

    // Sin cleanup del catálogo: la validación rechaza el alta antes de
    // crear ninguna fila de permission_role, así que no hay riesgo de
    // interbloqueo aquí — pero se deja la fila, igual que en
    // PermissionAlgorithmTest, por consistencia y para no depender del
    // orden de ejecución de los tests.
});

// CA-PERM-051
test('CA-PERM-051: clonar un rol copia sus concesiones y editar el origen después no afecta al clon', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-051');

    $origin = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'origen_051', 'name' => 'Origen',
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
        ])->assertCreated();

    resetSessionState();

    $clone = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'clone_from' => $origin->json('public_id'), 'code' => 'clon_051', 'name' => 'Clon',
        ])->assertCreated();

    expect($clone->json('permissions.0.code'))->toBe('auditoria.leer')
        ->and($clone->json('permissions.0.scope'))->toBe('propios');

    resetSessionState();

    // Editar el origen (revocar todo) no afecta al clon.
    test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/roles/{$origin->json('public_id')}/permissions"), ['permissions' => []])
        ->assertOk();

    resetSessionState();

    $cloneAfter = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/roles/{$clone->json('public_id')}"))
        ->assertOk();

    expect($cloneAfter->json('permissions'))->toHaveCount(1);
});

// CA-PERM-052
test('CA-PERM-052: clonar un rol con special_data_access sin poder activarlo responde 422 y no crea nada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-052');

    $orientador = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'orientador')->firstOrFail());

    // administrador_centro tiene rol_datos_especiales.actualizar pero NO
    // special_data_access (permisos.md §5.5/§7.4) ⇒ no puede activarlo.
    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'clone_from' => $orientador->public_id, 'code' => 'clon_orientador_052', 'name' => 'Clon de orientador',
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(Role::where('code', 'clon_orientador_052')->exists())->toBeFalse();
    });
});

// CA-PERM-054, CA-PERM-055
test('CA-PERM-054/055: un rol con asignaciones vivas no se elimina; sin asignaciones sí, con sus concesiones', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-054');

    $role = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'rol_054', 'name' => 'Rol 054',
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
        ])->assertCreated()->json('public_id');

    resetSessionState();

    $target = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'objetivo-054@example.com',
        'person' => ['given_name' => 'Obj', 'family_name_1' => 'Etivo'],
        'send_invitation' => false,
        'role_ids' => [$role],
    ])->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/roles/{$role}"))
        ->assertStatus(409);

    resetSessionState();

    // Retirar la asignación y entonces sí se puede eliminar.
    test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/users/{$target}/roles"), ['role_ids' => []])
        ->assertOk();

    resetSessionState();

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/roles/{$role}"))
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($role): void {
        $roleModel = Role::withTrashed()->where('public_id', $role)->firstOrFail();
        expect($roleModel->trashed())->toBeTrue();
        expect(PermissionRole::where('role_id', $roleModel->id)->count())->toBe(0);
        expect(PermissionRole::withTrashed()->where('role_id', $roleModel->id)->count())->toBe(1);
    });
});

// CA-PERM-040, CA-PERM-041 (RPERM-013 con ámbitos)
test('CA-PERM-040/041: no se puede conceder un ámbito más amplio del que se posee, pero sí uno más estrecho', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-040');

    // Un rol restringido con auditoria.leer ámbito propios, y un usuario
    // que solo tiene ESE rol (no administrador_centro).
    $restrictedRole = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'restringido_040', 'name' => 'Restringido',
            // rol.crear con todos: necesita crear roles pero no auditoria total.
            'permissions' => [
                ['code' => 'rol.crear', 'effect' => 'allow', 'scope' => 'todos'],
                ['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios'],
            ],
        ])->assertCreated()->json('public_id');

    resetSessionState();

    $restrictedUser = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'restringido-040@example.com',
        'person' => ['given_name' => 'Res', 'family_name_1' => 'Tringido'],
        'send_invitation' => false,
        'role_ids' => [$restrictedRole],
    ])->assertCreated();

    $restrictedUserModel = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => User::where('email', 'restringido-040@example.com')->firstOrFail(),
    );

    resetSessionState();

    // CA-PERM-040: intenta conceder auditoria.leer con ámbito TODOS ⇒ 403.
    test()->actingAs($restrictedUserModel)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'excede_040', 'name' => 'Excede',
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']],
        ])
        ->assertStatus(403);

    resetSessionState();

    // CA-PERM-041: concede auditoria.leer con ámbito PROPIOS (absorbido por
    // lo que él mismo tiene) ⇒ se guarda.
    test()->actingAs($restrictedUserModel)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'estrecha_041', 'name' => 'Estrecha',
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
        ])
        ->assertCreated();
});

// CA-PERM-043
test('CA-PERM-043: un deny se acepta aunque el solicitante no tenga el código', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-043');

    $restrictedRole = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'restringido_043', 'name' => 'Restringido',
            'permissions' => [['code' => 'rol.crear', 'effect' => 'allow', 'scope' => 'todos']],
        ])->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'restringido-043@example.com',
        'person' => ['given_name' => 'Res', 'family_name_1' => 'Tringido'],
        'send_invitation' => false,
        'role_ids' => [$restrictedRole],
    ])->assertCreated();

    $restrictedUserModel = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => User::where('email', 'restringido-043@example.com')->firstOrFail(),
    );

    resetSessionState();

    // No tiene usuario.eliminar, pero puede DENEGARLO en un rol nuevo.
    test()->actingAs($restrictedUserModel)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'deniega_043', 'name' => 'Deniega',
            'permissions' => [['code' => 'usuario.eliminar', 'effect' => 'deny', 'scope' => 'todos']],
        ])
        ->assertCreated();
});

// CA-PERM-060, CA-PERM-061 (RPERM-014, RN-AUTH-003)
test('CA-PERM-060: un rol personalizado creado con mfa_required=true obliga a MFA exactamente igual que uno predefinido', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-060');

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'mfa_obligatorio_060', 'name' => 'MFA obligatorio', 'mfa_required' => true,
        ])->assertCreated()->json('public_id');

    resetSessionState();

    $newUserPublicId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'mfa-060@example.com',
        'person' => ['given_name' => 'Con', 'family_name_1' => 'Mfa'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated()->json('public_id');

    $isObligated = app(TenantContext::class)->runFor($tenant->id, function () {
        $user = User::where('email', 'mfa-060@example.com')->firstOrFail();

        return app(MfaPolicy::class)->resolve($user)->isObligated();
    });

    expect($isObligated)->toBeTrue();
});

// CA-PERM-080, CA-PERM-081, CA-PERM-082 (auditoría de concesiones)
test('CA-PERM-080/081/082: conceder, cambiar y revocar un permiso quedan en audit_logs', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-080');

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), ['code' => 'rol_080', 'name' => 'Rol 080'])
        ->assertCreated()->json('public_id');

    resetSessionState();

    // Concede (created).
    test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
        ])->assertOk();

    resetSessionState();

    // Cambia el ámbito (updated).
    test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']],
        ])->assertOk();

    resetSessionState();

    // Revoca (deleted).
    test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), ['permissions' => []])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(AuditLog::where('auditable_type', 'permission_role')->where('event', 'created')->exists())->toBeTrue();
        expect(AuditLog::where('auditable_type', 'permission_role')->where('event', 'updated')->exists())->toBeTrue();
        expect(AuditLog::where('auditable_type', 'permission_role')->where('event', 'deleted')->exists())->toBeTrue();
    });
});

// CA-PERM-084
test('CA-PERM-084: enviar el mismo conjunto de concesiones no genera fila de auditoría nueva', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-084');

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'rol_084', 'name' => 'Rol 084',
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
        ])->assertCreated()->json('public_id');

    $before = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::where('auditable_type', 'permission_role')->count());

    resetSessionState();

    test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
        ])->assertOk();

    $after = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::where('auditable_type', 'permission_role')->count());

    expect($after)->toBe($before);
});

// CA-PERM-083
test('CA-PERM-083: cambiar los roles de un usuario audita user.updated con changes.roles.from/to', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-083');

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), ['code' => 'rol_083', 'name' => 'Rol 083'])
        ->assertCreated()->json('public_id');

    resetSessionState();

    $targetPublicId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'objetivo-083@example.com',
        'person' => ['given_name' => 'Obj', 'family_name_1' => 'Etivo'],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/users/{$targetPublicId}/roles"), ['role_ids' => [$roleId]])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($targetPublicId): void {
        $target = User::where('public_id', $targetPublicId)->firstOrFail();
        $log = AuditLog::where('auditable_type', 'user')
            ->where('auditable_id', $target->id)
            ->where('event', 'updated')
            ->latest('id')->first();

        expect($log)->not->toBeNull();
        expect($log->changes)->toHaveKey('roles');
        expect($log->changes['roles']['to'] ?? $log->changes['roles'])->not->toBeNull();
    });
});

// CA-PERM-090
test('CA-PERM-090: un rol de otro tenant referenciado por public_id responde 404, nunca 403', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('perm-090-a');
    [$tenantB, $adminB] = provisionCoreTenant('perm-090-b');

    $roleInB = test()->actingAs($adminB)
        ->postJson(coreApiUrl($tenantB->slug, '/roles'), ['code' => 'rol_090', 'name' => 'Rol de B'])
        ->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/roles/{$roleInB}"))
        ->assertStatus(404);

    resetSessionState();

    test()->actingAs($adminA)
        ->deleteJson(coreApiUrl($tenantA->slug, "/roles/{$roleInB}"))
        ->assertStatus(404);

    resetSessionState();

    test()->actingAs($adminA)
        ->putJson(coreApiUrl($tenantA->slug, "/roles/{$roleInB}/permissions"), ['permissions' => []])
        ->assertStatus(404);
});
