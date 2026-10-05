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
        private readonly AdministrationCapacityGuard $capacityGuard,
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

        $write = function () use ($target, $roles, $actor): void {
            // Issue #350 (H2): el estado se relee con el bloqueo ya tomado.
            // Lo que se compara, se audita y se comprueba para RPERM-013 es
            // lo que hay AHORA, no lo leído antes de esperar el turno.
            $target->unsetRelation('roles');
            $lockedRoles = $target->roles()->get();

            $fromCodes = $lockedRoles->pluck('code')->sort()->values()->all();
            $toCodes = $roles->pluck('code')->sort()->values()->all();

            $lockedAdded = $roles->whereNotIn('id', $lockedRoles->pluck('id')->all());

            if ($lockedAdded->isNotEmpty()) {
                $this->assertActorCanGrant($actor, $lockedAdded);
            }

            // Issue #350 (hueco residual), RPERM-013/RN-PERM-20: retirar un
            // rol exige asignacion_rol.eliminar sobre los roles releídos con
            // el bloqueo. La comprobación previa (arriba) se conserva para no
            // alterar el orden de errores; esta cubre el rol que un cambio
            // concurrente añadió y el PUT va a retirar.
            $lockedRemoved = $lockedRoles->whereNotIn('id', $roles->pluck('id')->all());

            if ($lockedRemoved->isNotEmpty() && ! $this->permissions->can($actor, 'asignacion_rol.eliminar')) {
                throw ApiException::forbidden();
            }

            // Sin cambio efectivo del conjunto de roles: ni escritura ni
            // auditoría (ADR-038 §9.3).
            if ($fromCodes === $toCodes) {
                return;
            }

            // datos.md §5.3: estado anterior leído ANTES del sync() (RN-PERM-20).
            $target->roles()->sync($roles->pluck('id'));

            $this->auditRecorder->record($target, 'updated', [
                'roles' => [$fromCodes, $toCodes],
            ]);
        };

        // RN-PERM-47 (1.5b, §20.2.1): después de RN-CORE-06/-07 y de
        // RPERM-013, antes de guardar. Asignar un rol con `deny` también
        // puede reducir la posesión efectiva, no solo retirar uno. Siempre
        // se pasa por el guard (issue #350): si el cambio efectivo solo se
        // conoce con el bloqueo tomado, no se puede decidir antes si hace
        // falta protegerlo; sin cambio efectivo el guard no tiene nada que
        // rechazar.
        $this->capacityGuard->protect($write);

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
                throw ApiException::cannotGrantUnheldRolePermission();
            }
        }
    }
}
