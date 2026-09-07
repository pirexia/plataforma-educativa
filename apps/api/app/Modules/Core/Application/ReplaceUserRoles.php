<?php

namespace App\Modules\Core\Application;

use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Events\UserRolesChanged;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/api.md §8 (`PUT /users/{public_id}/roles`). Ruta, verbo y
 * cuerpo sin cambios desde 1.1; 1.5 cambia dos cosas dentro (funcional.md
 * §7.8):
 *
 * 1. `RPERM-013` pasa a comparar pares (código, ámbito), con el motor
 *    nuevo y sus inercias (`PermissionResolver::ownsScope()`).
 * 2. El cambio deja registro de auditoría explícito con estado anterior y
 *    posterior (issue #165, `datos.md §5.3`) — `sync()` no dispara eventos
 *    de modelo.
 *
 * **Corrección de un hallazgo confirmado** (`REQ-PERM/api.md §8.2`):
 * `REQ-CORE/api.md §5` documenta desde 1.1 que retirar un rol exige
 * también `asignacion_rol.eliminar`, pero la ruta solo declaraba
 * `asignacion_rol.crear` y la comprobación no estaba implementada. Se
 * corrige aquí: retirar al menos un rol sin ese permiso responde `403`.
 */
final class ReplaceUserRoles
{
    public function __construct(
        private readonly SchoolAdministratorGuard $adminGuard,
        private readonly PermissionResolver $permissions,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  list<string>  $requestedRolePublicIds
     */
    public function execute(User $target, array $requestedRolePublicIds, User $actor): User
    {
        if ($actor->is($target)) {
            throw ApiException::conflict('core.validation.cannot_modify_self');
        }

        $roles = Role::query()->whereIn('public_id', $requestedRolePublicIds)->get();

        if ($roles->count() !== count(array_unique($requestedRolePublicIds))) {
            (new ValidationErrorBag)
                ->add('role_ids', 'core.validation.role_not_found', 'core.validation.role_not_found')
                ->throwIfAny();
        }

        $currentRoles = $target->roles;
        $currentRoleIds = $currentRoles->pluck('id');
        $newRoleIds = $roles->pluck('id');

        $addedRoles = $roles->whereNotIn('id', $currentRoleIds->all());
        $removedRoles = $currentRoles->whereNotIn('id', $newRoleIds->all());

        if ($addedRoles->isNotEmpty()) {
            $this->assertActorCanGrant($actor, $addedRoles);
        }

        // Hallazgo confirmado (api.md §8.2): retirar un rol exige
        // asignacion_rol.eliminar, comprobado aquí porque la ruta solo
        // declara asignacion_rol.crear (RequirePermission solo evalúa la
        // puerta de un único código por ruta).
        if ($removedRoles->isNotEmpty() && ! $this->permissions->can($actor, 'asignacion_rol.eliminar')) {
            throw ApiException::forbidden();
        }

        $losesAdminCentro = $currentRoles->contains('code', 'administrador_centro')
            && ! $roles->contains('code', 'administrador_centro');

        if ($losesAdminCentro && $this->adminGuard->wouldLeaveNoLivingAdministrator($target)) {
            throw ApiException::conflict('core.validation.last_school_administrator');
        }

        $fromCodes = $currentRoles->pluck('code')->sort()->values()->all();
        $toCodes = $roles->pluck('code')->sort()->values()->all();

        DB::transaction(function () use ($target, $newRoleIds, $fromCodes, $toCodes): void {
            $target->roles()->sync($newRoleIds);

            // datos.md §5.3: solo si hay cambio efectivo (ADR-038 §9.3), con
            // el estado anterior leído ANTES del sync() (RN-PERM-20) — ya lo
            // está, en $fromCodes, calculado antes de esta transacción.
            if ($fromCodes !== $toCodes) {
                $this->auditRecorder->record($target, 'updated', [
                    'roles' => [$fromCodes, $toCodes],
                ]);
            }
        });

        event(new UserRolesChanged($target->tenant_id, $target->public_id));

        return $target->fresh(['roles']);
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    private function assertActorCanGrant(User $actor, Collection $roles): void
    {
        $grants = PermissionRole::query()
            ->whereIn('role_id', $roles->pluck('id'))
            ->where('effect', 'allow')
            ->get(['permission_code', 'scope']);

        foreach ($grants as $grant) {
            $scope = Scope::tryFrom($grant->scope);

            if ($scope === null) {
                continue;
            }

            if (! $this->permissions->ownsScope($actor, $grant->permission_code, $scope)) {
                throw ApiException::forbidden('core.authorization.cannot_grant_unheld_permission', [
                    'code' => $grant->permission_code,
                    'scope' => $scope->value,
                ]);
            }
        }
    }
}
