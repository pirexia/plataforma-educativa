<?php

use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CORE `RN-CORE-07`, issue #349: la comprobación «queda otro
 * administrador» se repite con el bloqueo por tenant de
 * `AdministrationCapacityGuard::protect()` tomado y sobre lecturas frescas.
 * La ventana se reproduce de forma determinista (estilo de
 * `ConcurrentSnapshotTest`): un listener de consultas ejecuta «la baja del
 * otro administrador» justo después de tomar el bloqueo, es decir, entre
 * la comprobación previa (que aún veía a los dos) y la escritura. Sin la
 * relectura bajo el bloqueo, la escritura se hace y el centro se queda sin
 * administrador.
 *
 * El actor tiene un rol clonado de `administrador_centro` (misma
 * capacidad, pero no es `administrador_centro`): así `RN-PERM-47` queda
 * siempre satisfecha y lo único que puede rechazar es `RN-CORE-07`.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * @return array{0: object, 1: User, 2: User, 3: User} tenant, actor, adminB, adminC
 */
function sa349Setup(string $slug): array
{
    [$tenant, $owner] = provisionCoreTenant($slug);
    $tc = app(TenantContext::class);

    $adminRole = $tc->runFor($tenant->id, fn () => Role::query()->where('code', 'administrador_centro')->firstOrFail());

    resetSessionState();
    $cloneId = test()->actingAs($owner)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $adminRole->public_id, 'code' => "clon_admin_{$slug}", 'name' => 'Clon admin',
    ])->assertCreated()->json('public_id');

    $make = function (string $local, string $roleId) use ($tenant, $owner): User {
        resetSessionState();
        test()->actingAs($owner)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$local}@example.com",
            'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
            'role_ids' => [$roleId],
        ])->assertCreated();

        $user = app(TenantContext::class)->runFor($tenant->id, fn () => User::query()->where('email', "{$local}@example.com")->firstOrFail());
        app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
            $user->status = UserStatus::Activo;
            $user->save();
        });

        return $user;
    };

    $actor = $make("actor-{$slug}", $cloneId);
    $adminB = $make("b-{$slug}", $adminRole->public_id);
    $adminC = $make("c-{$slug}", $adminRole->public_id);

    // El administrador aprovisionado deja de contar: quedan exactamente B y C.
    app(TenantContext::class)->runFor($tenant->id, function () use ($owner): void {
        $owner->roles()->detach();
    });

    return [$tenant, $actor, $adminB, $adminC];
}

/** Ejecuta `$change` una sola vez, justo después de tomar el bloqueo por tenant. */
function sa349InjectAfterLock(Closure $change): void
{
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, $change): void {
        if ($fired || ! str_contains($query->sql, 'pg_advisory_xact_lock')) {
            return;
        }

        $fired = true;
        $change();
    });
}

// RN-CORE-07
test('RN-CORE-07: #349 DELETE /users/{id} se rechaza si, con el bloqueo tomado, el otro administrador ya no está vivo', function (): void {
    [$tenant, $actor, $adminB, $adminC] = sa349Setup('cs349a');
    $tc = app(TenantContext::class);

    resetSessionState();
    sa349InjectAfterLock(fn () => $adminC->delete());

    $response = test()->actingAs($actor)->deleteJson(coreApiUrl($tenant->slug, "/users/{$adminB->public_id}"));

    $response->assertStatus(409);

    expect($response->json('detail'))->toBe(__('core.validation.last_school_administrator'))
        ->and($tc->runFor($tenant->id, fn () => User::query()->where('email', $adminB->email)->exists()))->toBeTrue();
});

// RN-CORE-07
test('RN-CORE-07: #349 POST /users/{id}/status a inactivo se rechaza si, con el bloqueo tomado, el otro administrador ya no está activo', function (): void {
    [$tenant, $actor, $adminB, $adminC] = sa349Setup('cs349b');
    $tc = app(TenantContext::class);

    resetSessionState();
    sa349InjectAfterLock(function () use ($adminC): void {
        $adminC->status = UserStatus::Inactivo;
        $adminC->save();
    });

    $response = test()->actingAs($actor)->postJson(coreApiUrl($tenant->slug, "/users/{$adminB->public_id}/status"), ['status' => 'inactivo']);

    $response->assertStatus(409);

    expect($response->json('detail'))->toBe(__('core.validation.last_school_administrator'))
        ->and($tc->runFor($tenant->id, fn () => $adminB->fresh()->status))->toBe(UserStatus::Activo);
});

// RN-CORE-07
test('RN-CORE-07: #349 PUT /users/{id}/roles que retira administrador_centro se rechaza si, con el bloqueo tomado, el otro administrador ya no está vivo', function (): void {
    [$tenant, $actor, $adminB, $adminC] = sa349Setup('cs349c');
    $tc = app(TenantContext::class);

    resetSessionState();
    sa349InjectAfterLock(fn () => $adminC->delete());

    $response = test()->actingAs($actor)->putJson(coreApiUrl($tenant->slug, "/users/{$adminB->public_id}/roles"), ['role_ids' => []]);

    $response->assertStatus(409);

    expect($response->json('detail'))->toBe(__('core.validation.last_school_administrator'))
        ->and($tc->runFor($tenant->id, fn () => $adminB->fresh()->roles()->pluck('code')->all()))->toContain('administrador_centro');
});

// RN-CORE-07
test('RN-CORE-07: #349 con otro administrador vivo y activo bajo el bloqueo las tres operaciones siguen funcionando', function (): void {
    [$tenant, $actor, $adminB, $adminC] = sa349Setup('cs349d');

    resetSessionState();
    test()->actingAs($actor)->postJson(coreApiUrl($tenant->slug, "/users/{$adminB->public_id}/status"), ['status' => 'inactivo'])->assertOk();

    resetSessionState();
    test()->actingAs($actor)->postJson(coreApiUrl($tenant->slug, "/users/{$adminB->public_id}/status"), ['status' => 'activo'])->assertOk();

    resetSessionState();
    test()->actingAs($actor)->putJson(coreApiUrl($tenant->slug, "/users/{$adminB->public_id}/roles"), ['role_ids' => []])->assertOk();

    resetSessionState();
    test()->actingAs($actor)->deleteJson(coreApiUrl($tenant->slug, "/users/{$adminC->public_id}"))->assertStatus(409);
});
