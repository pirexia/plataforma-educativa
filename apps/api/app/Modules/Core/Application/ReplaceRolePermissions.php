<?php

namespace App\Modules\Core\Application;

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Events\RolePermissionsChanged;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\Scope;
use App\Support\Authorization\ScopeResolverRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/api.md §5, funcional.md §7.7 (`PUT /roles/{public_id}/permissions`).
 * Reemplazo completo del conjunto de concesiones de un rol
 * (`ADR-038 §9.1`). Orden de validación exacto de `api.md §5.1`: las
 * comprobaciones de forma (1-6) van antes que la de autorización (7), y la
 * existencia del rol (8) es la última — para que un cuerpo mal formado no
 * produzca un `403` que parezca un problema de permisos, ni un `404` que
 * oculte un `422` de verdad.
 */
final class ReplaceRolePermissions
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ScopeResolverRegistry $scopeResolvers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $input
     */
    public function execute(?Role $role, array $input, User $actor): Role
    {
        $errors = new ValidationErrorBag;
        $desired = $this->validateEntries($input, $errors);
        $errors->throwIfAny();

        /** @var Collection<string, PermissionRole> $existingByCode */
        $existingByCode = $role !== null
            ? $role->permissionGrants()->get()->keyBy('permission_code')
            : collect();

        $this->assertOwnsChanges($actor, $existingByCode, $desired);

        if ($role === null) {
            throw ApiException::notFound();
        }

        return DB::transaction(function () use ($role, $existingByCode, $desired): Role {
            $changed = $this->applyDiff($role, $existingByCode, $desired);

            if ($changed) {
                event(new RolePermissionsChanged($role->tenant_id, $role->public_id));
            }

            return $role->fresh(['permissionGrants.permission']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @return list<array{code: string, effect: string, scope: string}>
     */
    private function validateEntries(array $input, ValidationErrorBag $errors): array
    {
        $validated = [];
        $seen = [];

        foreach ($input as $i => $entry) {
            $code = (string) ($entry['code'] ?? '');
            $effect = (string) ($entry['effect'] ?? '');
            $scopeValue = (string) ($entry['scope'] ?? '');

            if (isset($seen[$code])) {
                $errors->add("permissions.{$i}.code", 'core.validation.permission_duplicated', 'core.validation.permission_duplicated', ['code' => $code]);

                continue;
            }
            $seen[$code] = true;

            $permission = Permission::query()->find($code);

            if ($permission === null) {
                $errors->add("permissions.{$i}.code", 'core.validation.permission_not_found', 'core.validation.permission_not_found', ['code' => $code]);

                continue;
            }

            if ($permission->retired_at !== null) {
                $errors->add("permissions.{$i}.code", 'core.validation.permission_retired', 'core.validation.permission_retired', ['code' => $code]);

                continue;
            }

            // effect/scope: forma validada por ReplaceRolePermissionsRequest
            // (in:allow,deny / vocabulario de Scope) antes de llegar aquí.
            $scope = Scope::from($scopeValue);

            if (! in_array($scope, $permission->applicableScopes(), true)) {
                $errors->add("permissions.{$i}.scope", 'core.validation.scope_not_applicable', 'core.validation.scope_not_applicable', ['scope' => $scopeValue, 'resource' => $permission->resource]);

                continue;
            }

            if (! $this->scopeResolvers->has($permission->resource, $scope)) {
                $errors->add("permissions.{$i}.scope", 'core.validation.scope_resolver_missing', 'core.validation.scope_resolver_missing', ['scope' => $scopeValue, 'resource' => $permission->resource]);

                continue;
            }

            $validated[] = ['code' => $code, 'effect' => $effect, 'scope' => $scopeValue];
        }

        return $validated;
    }

    /**
     * §5.4: solo lo que se añade o amplía. Una entrada `allow` idéntica a
     * la que ya existía para ese código no se vuelve a comprobar; una
     * nueva, o una cuyo efecto/ámbito cambia, sí — y `ownsScope()` pasa en
     * silencio si el solicitante ya posee el ámbito nuevo (estrechar a algo
     * que sí posee no rechaza nada). Las filas `deny` nunca se comprueban.
     *
     * @param  Collection<string, PermissionRole>  $existingByCode
     * @param  list<array{code: string, effect: string, scope: string}>  $desired
     */
    private function assertOwnsChanges(User $actor, Collection $existingByCode, array $desired): void
    {
        foreach ($desired as $entry) {
            if ($entry['effect'] === 'deny') {
                continue;
            }

            $existing = $existingByCode->get($entry['code']);
            $unchanged = $existing !== null && $existing->effect === 'allow' && $existing->scope === $entry['scope'];

            if ($unchanged) {
                continue;
            }

            $scope = Scope::from($entry['scope']);

            if (! $this->permissions->ownsScope($actor, $entry['code'], $scope)) {
                throw ApiException::forbidden('core.authorization.cannot_grant_unheld_permission', [
                    'code' => $entry['code'],
                    'scope' => $scope->value,
                ]);
            }
        }
    }

    /**
     * @param  Collection<string, PermissionRole>  $existingByCode
     * @param  list<array{code: string, effect: string, scope: string}>  $desired
     */
    private function applyDiff(Role $role, Collection $existingByCode, array $desired): bool
    {
        $changed = false;
        $desiredCodes = [];

        foreach ($desired as $entry) {
            $desiredCodes[$entry['code']] = true;
            $existing = $existingByCode->get($entry['code']);

            if ($existing === null) {
                PermissionRole::create([
                    'role_id' => $role->id,
                    'permission_code' => $entry['code'],
                    'effect' => $entry['effect'],
                    'scope' => $entry['scope'],
                ]);
                $changed = true;

                continue;
            }

            if ($existing->effect !== $entry['effect'] || $existing->scope !== $entry['scope']) {
                $existing->effect = $entry['effect'];
                $existing->scope = $entry['scope'];
                $existing->save();
                $changed = true;
            }
        }

        foreach ($existingByCode as $code => $existing) {
            if (! isset($desiredCodes[$code])) {
                $existing->delete();
                $changed = true;
            }
        }

        return $changed;
    }
}
