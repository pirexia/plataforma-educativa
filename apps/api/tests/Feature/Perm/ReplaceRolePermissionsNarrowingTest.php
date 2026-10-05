<?php

use App\Models\AuditLog;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §20.2 (`RN-PERM-24`), api.md §5.4, issue #170:
 * `RPERM-013` en `PUT /roles/{id}/permissions` compara pares (código,
 * ámbito) con `todos` como única absorción. Estrechar no es una categoría:
 * cualquier `allow` nueva o cuyo ámbito cambia a uno no poseído da `403`;
 * las idénticas, las retiradas y todas las `deny` no se comprueban.
 * Ningún cambio de código de producción: este test fija el comportamiento.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function narrowingAs(User $actor): mixed
{
    resetSessionState();

    return test()->actingAs($actor);
}

/**
 * Crea un usuario cuyo único rol personalizado concede `$grants`.
 *
 * @param  list<array{code: string, effect: string, scope: string}>  $grants
 */
function narrowingUser(object $tenant, User $admin, string $slug, array $grants): User
{
    $role = narrowingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => "rol_{$slug}", 'name' => "Rol {$slug}", 'permissions' => $grants,
    ])->assertCreated()->json('public_id');

    narrowingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => "{$slug}@example.com",
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$role],
    ])->assertCreated();

    return app(TenantContext::class)->runFor($tenant->id, fn () => User::query()->where('email', "{$slug}@example.com")->firstOrFail());
}

/**
 * Siembra el rol `R` sin pasar por la API.
 */
function narrowingSeedRole(object $tenant, string $effect, string $scope): string
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($effect, $scope): string {
        $role = Role::query()->where('code', 'r_170')->first() ?? Role::create(['code' => 'r_170', 'name' => 'R 170']);

        $role->permissionGrants()->delete();
        PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'auditoria.leer', 'effect' => $effect, 'scope' => $scope]);

        return (string) $role->public_id;
    });
}

function narrowingGrantOf(object $tenant, string $code): ?string
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($code): ?string {
        $grant = PermissionRole::query()
            ->whereHas('role', fn ($q) => $q->where('code', 'r_170'))
            ->where('permission_code', $code)
            ->first();

        return $grant === null ? null : "{$grant->effect}|{$grant->scope}";
    });
}

// CA-PERM-045
test('CA-PERM-045: RPERM-013 estricto: estrechar sin poseer el ámbito nuevo da 403; idénticas, retiradas y deny no se comprueban', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-045');

    // Solicitante con rol.actualizar y SIN ninguna concesión de auditoria.leer.
    $requester = narrowingUser($tenant, $admin, 'sin_audit_045', [
        ['code' => 'rol.actualizar', 'effect' => 'allow', 'scope' => 'todos'],
    ]);

    $roleId = narrowingSeedRole($tenant, 'allow', 'todos');
    $auditBefore = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->count());

    // Estrechar a `propios` sin poseerlo → 403, fila intacta, sin auditoría.
    $response = narrowingAs($requester)->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
    ])->assertForbidden();

    expect($response->json('detail'))->toBe(__('core.authorization.cannot_grant_unheld_permission', ['code' => 'auditoria.leer', 'scope' => 'propios']))
        ->and(narrowingGrantOf($tenant, 'auditoria.leer'))->toBe('allow|todos')
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->count()))->toBe($auditBefore);

    // Idéntica + un `deny` nuevo → 200: la idéntica no se comprueba y el deny tampoco.
    narrowingAs($requester)->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
        'permissions' => [
            ['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos'],
            ['code' => 'usuario.eliminar', 'effect' => 'deny', 'scope' => 'todos'],
        ],
    ])->assertOk();

    expect(narrowingGrantOf($tenant, 'auditoria.leer'))->toBe('allow|todos')
        ->and(narrowingGrantOf($tenant, 'usuario.eliminar'))->toBe('deny|todos');

    // Conjunto sin auditoria.leer → 200 y queda retirada.
    narrowingAs($requester)->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
        'permissions' => [['code' => 'usuario.eliminar', 'effect' => 'deny', 'scope' => 'todos']],
    ])->assertOk();

    expect(narrowingGrantOf($tenant, 'auditoria.leer'))->toBeNull();

    // Solicitante con auditoria.leer `propios` (y sin `todos`) y rol.actualizar.
    $partial = narrowingUser($tenant, $admin, 'propios_045', [
        ['code' => 'rol.actualizar', 'effect' => 'allow', 'scope' => 'todos'],
        ['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios'],
    ]);

    narrowingSeedRole($tenant, 'allow', 'todos');

    // Estrechar de `todos` a `propios`, que sí posee → 200.
    narrowingAs($partial)->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
    ])->assertOk();

    expect(narrowingGrantOf($tenant, 'auditoria.leer'))->toBe('allow|propios');

    // Pasar una fila `deny` de auditoria.leer a `allow` `todos` → 403 (no posee `todos`).
    narrowingSeedRole($tenant, 'deny', 'todos');

    narrowingAs($partial)->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertForbidden();

    expect(narrowingGrantOf($tenant, 'auditoria.leer'))->toBe('deny|todos');
});
