<?php

namespace App\Modules\Core\Application;

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\Scope;
use App\Support\Authorization\ScopeResolverRegistry;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §7.4-§7.5, api.md §3 (`POST /roles`, `RPERM-005`,
 * `RPERM-006`). Alta y clonación comparten una única ruta y un único
 * permiso (`rol.crear`); `clone_from` decide cuál de las dos ocurre.
 * `is_system` es siempre `false` — no es un campo del cuerpo.
 */
final class CreateRole
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ScopeResolverRegistry $scopeResolvers,
        private readonly SpecialDataAccessGuard $specialDataAccessGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Role
    {
        $errors = new ValidationErrorBag;

        // api.md §3.2: mutuamente excluyentes — mezclarlos haría ambiguo
        // si la lista sustituye o amplía lo clonado.
        if (isset($data['clone_from']) && ! empty($data['permissions'])) {
            $errors->add('permissions', 'core.validation.clone_and_permissions_exclusive', 'core.validation.clone_and_permissions_exclusive')
                ->throwIfAny();
        }

        if (Role::query()->where('code', $data['code'])->exists()) {
            $errors->add('code', 'core.validation.role_code_taken', 'core.validation.role_code_taken');
        }

        $wantsSpecialDataAccess = (bool) ($data['special_data_access'] ?? false);

        if ($wantsSpecialDataAccess) {
            $this->assertCanActivateSpecialDataAccess($actor);
        }

        $cloneSource = null;

        if (isset($data['clone_from'])) {
            $cloneSource = Role::query()->where('public_id', $data['clone_from'])->first();

            if ($cloneSource === null) {
                $errors->add('clone_from', 'core.validation.clone_source_not_found', 'core.validation.clone_source_not_found');
            }
        }

        $grantInputs = $cloneSource !== null
            ? $cloneSource->permissionGrants->map(fn (PermissionRole $grant) => [
                'code' => $grant->permission_code,
                'effect' => $grant->effect,
                'scope' => $grant->scope,
            ])->all()
            : ($data['permissions'] ?? []);

        $validatedGrants = $this->validateGrants($grantInputs, $errors);

        $errors->throwIfAny();

        // funcional.md §7.5 punto 4: si el clon lleva special_data_access y
        // el solicitante no puede activarlo, 422 y no se crea nada — no se
        // degrada en silencio (CLAUDE.md §5).
        if ($cloneSource !== null && (bool) $cloneSource->special_data_access && ! $this->canActivateSpecialDataAccess($actor)) {
            (new ValidationErrorBag)
                ->add('special_data_access', 'core.validation.clone_requires_special_data_access', 'core.validation.clone_requires_special_data_access')
                ->throwIfAny();
        }

        $this->assertOwnsGrants($actor, $validatedGrants);

        $mfaRequired = match (true) {
            array_key_exists('mfa_required', $data) => (bool) $data['mfa_required'],
            $cloneSource !== null => (bool) $cloneSource->mfa_required,
            default => false,
        };

        return DB::transaction(function () use ($data, $cloneSource, $validatedGrants, $wantsSpecialDataAccess, $mfaRequired): Role {
            $role = Role::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'name_key' => null,
                'is_system' => false,
                'mfa_required' => $mfaRequired,
                'special_data_access' => $cloneSource !== null ? (bool) $cloneSource->special_data_access : $wantsSpecialDataAccess,
            ]);

            foreach ($validatedGrants as $grant) {
                PermissionRole::create([
                    'role_id' => $role->id,
                    'permission_code' => $grant['code'],
                    'effect' => $grant['effect'],
                    'scope' => $grant['scope'],
                ]);
            }

            // api.md §11: un rol recién creado nunca tiene titulares —
            // RoleMfaRequirementChanged no se emite en el alta, a
            // propósito, aunque mfa_required venga a true. No hay nada
            // que materializar todavía.
            return $role->refresh();
        });
    }

    private function assertCanActivateSpecialDataAccess(User $actor): void
    {
        if (! $this->canActivateSpecialDataAccess($actor)) {
            throw ApiException::forbidden('core.authorization.special_data_access_not_held');
        }
    }

    private function canActivateSpecialDataAccess(User $actor): bool
    {
        return $this->permissions->can($actor, 'rol_datos_especiales.actualizar')
            && $this->specialDataAccessGuard->holds($actor);
    }

    /**
     * @param  list<array<string, mixed>>  $grants
     * @return list<array{code: string, effect: string, scope: string}>
     */
    private function validateGrants(array $grants, ValidationErrorBag $errors): array
    {
        $validated = [];
        $seen = [];

        foreach ($grants as $i => $grant) {
            $code = (string) ($grant['code'] ?? '');
            $effect = (string) ($grant['effect'] ?? '');
            $scopeValue = (string) ($grant['scope'] ?? '');

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

            $scope = Scope::tryFrom($scopeValue);

            if ($scope === null) {
                $errors->add("permissions.{$i}.scope", 'core.validation.scope_not_applicable', 'core.validation.scope_not_applicable', ['scope' => $scopeValue]);

                continue;
            }

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
     * `RPERM-013`/`ADR-044 §4.8`: cada `(code, scope)` del alta contra lo
     * efectivo del solicitante. Las filas `deny` no se comprueban
     * (`funcional.md §7.7`): nadie necesita poseer un permiso para
     * prohibírselo a otro.
     *
     * @param  list<array{code: string, effect: string, scope: string}>  $grants
     */
    private function assertOwnsGrants(User $actor, array $grants): void
    {
        foreach ($grants as $grant) {
            if ($grant['effect'] === 'deny') {
                continue;
            }

            $scope = Scope::from($grant['scope']);

            if (! $this->permissions->ownsScope($actor, $grant['code'], $scope)) {
                throw ApiException::forbidden('core.authorization.cannot_grant_unheld_permission', [
                    'code' => $grant['code'],
                    'scope' => $scope->value,
                ]);
            }
        }
    }
}
