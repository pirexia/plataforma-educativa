<?php

use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Application\AdministrationCapacityGuard;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopeResolverRegistry;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §20.2.1 (`RN-PERM-47`), issue #353: el guard usa un
 * único `PermissionResolver` por fase de evaluación (antes / después de la
 * escritura) en vez de uno por usuario. Este test fija que (a) el resultado,
 * incluido `errors.administration_capacity[0].params.codes`, es idéntico al
 * de evaluar cada usuario con un resolutor propio y (b) el catálogo
 * (`Permission::all()`) se carga una vez por fase, no una por titular.
 *
 * Medición que motivó el cambio: ver `AdministrationCapacityGuard::newResolver()`.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * @return array{0: object, 1: User, 2: User, 3: Role, 4: Role} tenant, X, Y, rol de X, rol de Y
 */
function acr353Setup(string $slug): array
{
    [$tenant, $owner] = provisionCoreTenant($slug);
    $tc = app(TenantContext::class);

    $adminRole = $tc->runFor($tenant->id, fn () => Role::query()->where('code', 'administrador_centro')->firstOrFail());

    $cloneIds = [];

    foreach (['a', 'b'] as $suffix) {
        resetSessionState();
        $cloneIds[$suffix] = test()->actingAs($owner)->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'clone_from' => $adminRole->public_id, 'code' => "clon_{$suffix}_{$slug}", 'name' => "Clon {$suffix}",
        ])->assertCreated()->json('public_id');
    }

    $users = [];

    foreach (['a' => 'x', 'b' => 'y'] as $suffix => $local) {
        resetSessionState();
        test()->actingAs($owner)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$local}-{$slug}@example.com",
            'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
            'role_ids' => [$cloneIds[$suffix]],
        ])->assertCreated();

        $users[$suffix] = $tc->runFor($tenant->id, function () use ($local, $slug): User {
            $user = User::query()->where('email', "{$local}-{$slug}@example.com")->firstOrFail();
            $user->status = UserStatus::Activo;
            $user->save();

            return $user;
        });
    }

    $roles = $tc->runFor($tenant->id, fn () => [
        Role::query()->where('code', "clon_a_{$slug}")->firstOrFail(),
        Role::query()->where('code', "clon_b_{$slug}")->firstOrFail(),
    ]);

    return [$tenant, $users['a'], $users['b'], $roles[0], $roles[1]];
}

// RN-PERM-47
test('RN-PERM-47: #353 el 409 lleva los mismos params.codes que evaluar a cada titular con un resolutor propio', function (): void {
    [$tenant, $x, $y, $roleX, $roleY] = acr353Setup('acr353a');
    $tc = app(TenantContext::class);

    $reference = [];

    // La escritura quita a X `rol.eliminar` y a Y `permiso.leer`: ambos cumplían
    // antes, ninguno cumple después, y a cada uno le falta un código distinto.
    $write = function () use ($roleX, $roleY, $x, $y, &$reference): void {
        PermissionRole::query()->where('role_id', $roleX->id)->where('permission_code', 'rol.eliminar')->delete();
        PermissionRole::query()->where('role_id', $roleY->id)->where('permission_code', 'permiso.leer')->delete();

        // Referencia independiente: un resolutor NUEVO por usuario y por código
        // (el comportamiento anterior a #353) sobre el estado posterior a la escritura.
        $missing = [];

        foreach ([$x, $y] as $user) {
            $resolver = new PermissionResolver(app(ScopeResolverRegistry::class), app(ModuleAvailability::class));

            foreach (ProvisionTenantDefaults::ADMIN_CENTRO_PERMISSIONS as $code) {
                $decision = $resolver->decide($user, $code);

                if (! ($decision->permitted && $decision->isUnrestricted())) {
                    $missing[] = $code;
                }
            }
        }

        $reference = array_values(array_unique($missing));
        sort($reference);
    };

    $thrown = null;

    try {
        $tc->runFor($tenant->id, fn () => app(AdministrationCapacityGuard::class)->protect($write));
    } catch (ApiException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ApiException::class)
        ->and($thrown->status)->toBe(409)
        ->and($reference)->toBe(['permiso.leer', 'rol.eliminar'])
        ->and($thrown->errors['administration_capacity'][0]['params']['codes'])->toBe($reference)
        ->and($thrown->detailParams['codes'])->toBe(implode(', ', $reference));
});

// RN-PERM-47
test('RN-PERM-47: #353 si un titular conserva todo, la escritura se acepta; el catálogo se carga una vez por fase', function (): void {
    [$tenant, $x, $y, $roleX] = acr353Setup('acr353b');
    $tc = app(TenantContext::class);

    $catalogLoads = 0;
    DB::listen(function (QueryExecuted $query) use (&$catalogLoads): void {
        if ($query->sql === 'select * from "permissions"') {
            $catalogLoads++;
        }
    });

    // Solo se recorta a X; Y sigue cumpliendo.
    $tc->runFor($tenant->id, fn () => app(AdministrationCapacityGuard::class)->protect(
        fn () => PermissionRole::query()->where('role_id', $roleX->id)->where('permission_code', 'rol.eliminar')->delete(),
    ));

    // Dos titulares evaluados antes y hasta el primero que cumple después:
    // una carga del catálogo por fase (antes, después), no una por usuario.
    expect($catalogLoads)->toBeLessThanOrEqual(2);
});
