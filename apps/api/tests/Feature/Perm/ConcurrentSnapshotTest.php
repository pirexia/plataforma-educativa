<?php

use App\Models\AuditLog;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §20.2.1, api.md §5.4/§14.5, issue #350 (H1/H2):
 * `RPERM-013` / `RN-PERM-24` se evalúan sobre las filas releídas con el
 * bloqueo de `protect()` tomado, no sobre una instantánea previa a esperar
 * el turno. La ventana se reproduce de forma determinista: un listener de
 * consultas ejecuta «el cambio del otro administrador» justo después de la
 * lectura previa (o, en H1, justo tras tomar el bloqueo) y una sola vez.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function snapAs(User $actor): mixed
{
    resetSessionState();

    return test()->actingAs($actor);
}

/**
 * Ejecuta `$change` una sola vez, tras la primera consulta que cumpla `$when`.
 *
 * @param  callable(string, array<int, mixed>): bool  $when
 */
function snapInjectOnce(callable $when, Closure $change): void
{
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, $when, $change): void {
        if ($fired || ! $when($query->sql, $query->bindings)) {
            return;
        }

        $fired = true;
        $change();
    });
}

// RPERM-013, RN-PERM-24
test('RPERM-013: H1 una entrada idéntica a lo leído antes del bloqueo se comprueba contra lo que hay con el bloqueo tomado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-350a');

    $roleId = snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_solicitante_350', 'name' => 'Solicitante 350',
        'permissions' => [['code' => 'rol.actualizar', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'solicitante-350@example.com',
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $tc = app(TenantContext::class);
    $requester = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'solicitante-350@example.com')->firstOrFail());

    // R concede auditoria.leer `todos`, que el solicitante NO posee.
    $targetId = $tc->runFor($tenant->id, function (): string {
        $role = Role::create(['code' => 'r_350', 'name' => 'R 350']);
        PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']);

        return (string) $role->public_id;
    });

    resetSessionState();

    // Otro administrador cambia R a `deny` justo entre la lectura y el bloqueo.
    snapInjectOnce(
        fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'),
        fn () => PermissionRole::query()
            ->where('permission_code', 'auditoria.leer')
            ->whereHas('role', fn ($q) => $q->where('code', 'r_350'))
            ->update(['effect' => 'deny']),
    );

    // El PUT envía `allow todos`: idéntico a lo que el solicitante leyó, pero
    // frente a lo guardado con el bloqueo es una concesión nueva sin poseerla.
    $response = test()->actingAs($requester)->putJson(coreApiUrl($tenant->slug, "/roles/{$targetId}/permissions"), [
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ]);

    $response->assertForbidden();

    expect($response->json('errors.grant.0.code'))->toBe('core.authorization.cannot_grant_unheld_permission')
        ->and($response->json('errors.grant.0.params'))->toBe(['code' => 'auditoria.leer', 'scope' => 'todos']);

});

// RPERM-013, RN-PERM-24
test('RPERM-013: H2 las concesiones de un rol añadido se comprueban con el bloqueo tomado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-350b');
    $tc = app(TenantContext::class);

    // Solicitante con solo asignacion_rol.crear: no posee auditoria.leer.
    $roleId = snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_asigna_350', 'name' => 'Asigna 350',
        'permissions' => [['code' => 'asignacion_rol.crear', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    foreach (['asignador-350', 'objetivo-350'] as $slug) {
        snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$slug}@example.com",
            'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
            'role_ids' => [$roleId],
        ])->assertCreated();
    }

    $requester = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'asignador-350@example.com')->firstOrFail());
    $target = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'objetivo-350@example.com')->firstOrFail());

    // Rol R2 vacío en el momento de la comprobación previa.
    $r2 = $tc->runFor($tenant->id, fn () => Role::create(['code' => 'r2_350', 'name' => 'R2 350']));

    resetSessionState();

    // Otro administrador le concede auditoria.leer `todos` justo tras tomar el bloqueo.
    snapInjectOnce(
        fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'),
        fn () => PermissionRole::create(['role_id' => $r2->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']),
    );

    $response = test()->actingAs($requester)->putJson(coreApiUrl($tenant->slug, "/users/{$target->public_id}/roles"), [
        'role_ids' => [$roleId, $r2->public_id],
    ]);

    $response->assertForbidden();

    $codes = $tc->runFor($tenant->id, fn () => $target->fresh()->roles()->pluck('code')->all());

    expect($codes)->not->toContain('r2_350');

    // Issue #352: ni errors.grant[0].params ni el detail nombran el código
    // ni el ámbito de las concesiones del rol asignado.
    expect($response->json('errors.grant.0.code'))->toBe('core.authorization.cannot_grant_unheld_role_permission')
        ->and($response->json('errors.grant.0'))->not->toHaveKey('params')
        ->and($response->json('detail'))->not->toContain('auditoria.leer')
        ->and($response->json('detail'))->not->toContain('todos');
});

// RPERM-013, RN-PERM-24, issue #352
test('RPERM-013: #352 las dos rutas de asignación de rol no revelan código ni ámbito de las concesiones del rol', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-352');
    $tc = app(TenantContext::class);

    $roleId = snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_asigna_352', 'name' => 'Asigna 352',
        'permissions' => [
            ['code' => 'asignacion_rol.crear', 'effect' => 'allow', 'scope' => 'todos'],
            ['code' => 'usuario.crear', 'effect' => 'allow', 'scope' => 'todos'],
        ],
    ])->assertCreated()->json('public_id');

    snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'asignador-352@example.com',
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'objetivo-352@example.com',
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
    ])->assertCreated();

    $requester = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'asignador-352@example.com')->firstOrFail());
    $target = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'objetivo-352@example.com')->firstOrFail());

    $heavy = $tc->runFor($tenant->id, function (): string {
        $role = Role::create(['code' => 'pesado_352', 'name' => 'Pesado 352']);
        PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']);

        return (string) $role->public_id;
    });

    $assertGeneric = function ($response): void {
        $response->assertForbidden();

        expect($response->json('errors.grant.0.code'))->toBe('core.authorization.cannot_grant_unheld_role_permission')
            ->and($response->json('errors.grant.0.message'))->not->toBeEmpty()
            ->and($response->json('errors.grant.0'))->not->toHaveKey('params')
            ->and($response->json())->not->toHaveKey('params')
            ->and($response->json('detail'))->not->toContain('auditoria.leer')
            ->and($response->json('detail'))->not->toContain('todos');
    };

    $assertGeneric(snapAs($requester)->putJson(coreApiUrl($tenant->slug, "/users/{$target->public_id}/roles"), [
        'role_ids' => [$heavy],
    ]));

    $assertGeneric(snapAs($requester)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'nuevo-352@example.com',
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$heavy],
    ]));
});

// RPERM-013, RN-PERM-20, issue #350 (hueco residual)
test('RPERM-013: #350 retirar un rol que un cambio concurrente añadió exige asignacion_rol.eliminar con el bloqueo tomado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-350e');
    $tc = app(TenantContext::class);

    // Solicitante con asignacion_rol.crear pero SIN asignacion_rol.eliminar.
    $roleId = snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_asigna_350e', 'name' => 'Asigna 350e',
        'permissions' => [['code' => 'asignacion_rol.crear', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    foreach (['asignador-350e', 'objetivo-350e'] as $slug) {
        snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$slug}@example.com",
            'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
            'role_ids' => [$roleId],
        ])->assertCreated();
    }

    $requester = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'asignador-350e@example.com')->firstOrFail());
    $target = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'objetivo-350e@example.com')->firstOrFail());
    $extra = $tc->runFor($tenant->id, fn () => Role::create(['code' => 'extra_350e', 'name' => 'Extra 350e']));

    resetSessionState();

    // Tras la lectura previa de los roles del objetivo, otro administrador le
    // añade `extra`, que el PUT (solo [rol_asigna]) va a retirar.
    snapInjectOnce(
        fn (string $sql): bool => str_starts_with($sql, 'select')
            && str_contains($sql, 'pivot_user_id')
            && DB::transactionLevel() <= 1,
        fn () => $target->roles()->attach($extra->id),
    );

    test()->actingAs($requester)->putJson(coreApiUrl($tenant->slug, "/users/{$target->public_id}/roles"), [
        'role_ids' => [$roleId],
    ])->assertForbidden();

    $codes = $tc->runFor($tenant->id, fn () => $target->fresh()->roles()->pluck('code')->sort()->values()->all());

    expect($codes)->toBe(['extra_350e', 'rol_asigna_350e']);
});

// RN-PERM-20, ADR-038 §9.3
test('RN-PERM-20: H2 el estado anterior y posterior que se audita es el releído con el bloqueo tomado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-350c');
    $tc = app(TenantContext::class);

    $ids = $tc->runFor($tenant->id, fn () => [
        Role::create(['code' => 'a_350', 'name' => 'A 350']),
        Role::create(['code' => 'b_350', 'name' => 'B 350']),
    ]);

    snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'objetivo-350c@example.com',
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$ids[0]->public_id],
    ])->assertCreated();

    $target = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'objetivo-350c@example.com')->firstOrFail());

    resetSessionState();

    // Tras la lectura previa de los roles del objetivo (la carga con tabla
    // pivote, fuera de la transacción del guard), otro administrador le añade B.
    snapInjectOnce(
        fn (string $sql): bool => str_starts_with($sql, 'select')
            && str_contains($sql, 'pivot_user_id')
            && DB::transactionLevel() <= 1,
        fn () => $target->roles()->attach($ids[1]->id),
    );

    // El PUT pide solo [A]: frente a la lectura previa es «sin cambios»;
    // frente a lo guardado, retira B y debe quedar escrito y auditado.
    test()->actingAs($admin)->putJson(coreApiUrl($tenant->slug, "/users/{$target->public_id}/roles"), [
        'role_ids' => [$ids[0]->public_id],
    ])->assertOk();

    $after = $tc->runFor($tenant->id, fn () => $target->fresh()->roles()->pluck('code')->all());
    $audit = $tc->runFor($tenant->id, fn () => AuditLog::query()
        ->where('auditable_id', $target->id)
        ->where('auditable_type', $target->getMorphClass())
        ->where('event', 'updated')
        ->latest('id')
        ->first());

    expect($after)->toBe(['a_350'])
        ->and($audit)->not->toBeNull()
        ->and($audit->changes['roles'] ?? null)->toBe(['to' => ['a_350'], 'from' => ['a_350', 'b_350']]);
});

// RN-PERM-20, ADR-038 §9.3
test('RN-PERM-20: H2 sin cambio efectivo no hay escritura ni auditoría y responde 200 como antes', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-350d');
    $tc = app(TenantContext::class);

    $role = $tc->runFor($tenant->id, fn () => Role::create(['code' => 'a_350d', 'name' => 'A 350d']));

    snapAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'objetivo-350d@example.com',
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$role->public_id],
    ])->assertCreated();

    $target = $tc->runFor($tenant->id, fn () => User::query()->where('email', 'objetivo-350d@example.com')->firstOrFail());
    $before = $tc->runFor($tenant->id, fn () => AuditLog::query()->count());

    snapAs($admin)->putJson(coreApiUrl($tenant->slug, "/users/{$target->public_id}/roles"), [
        'role_ids' => [$role->public_id],
    ])->assertOk();

    expect($tc->runFor($tenant->id, fn () => AuditLog::query()->count()))->toBe($before);
});
