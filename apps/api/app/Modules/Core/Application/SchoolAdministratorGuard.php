<?php

namespace App\Modules\Core\Application;

use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Support\Api\ApiException;
use Illuminate\Database\Eloquent\Builder;

/**
 * RN-CORE-07: siempre al menos un `administrador_centro` vivo; al menos
 * uno activo si la operación es un cambio de estado (api.md §3,
 * `POST /users/{id}/status`, matiz distinto de la baja lógica — "vivo"
 * no es lo mismo que "activo": un administrador `pendiente` cuenta como
 * vivo pero no como activo).
 *
 * **Concurrencia (issue #349)**: las dos comprobaciones de arriba son
 * lecturas sin bloqueo, útiles como rechazo temprano que conserva el orden
 * de errores. No bastan por sí solas: dos bajas simultáneas de los dos
 * últimos administradores pasan ambas. Las `assert*` de abajo se llaman
 * **dentro de `AdministrationCapacityGuard::protect()`**, con el bloqueo por
 * tenant tomado, y releen cada una el estado (READ COMMITTED: una
 * instantánea por sentencia), de modo que la segunda ve lo que la primera
 * ya confirmó.
 */
final class SchoolAdministratorGuard
{
    /**
     * @param  User  $excluding  el usuario que se está dando de baja/cuyo rol se retira
     */
    public function wouldLeaveNoLivingAdministrator(User $excluding): bool
    {
        return $this->livingAdministratorsExcluding($excluding)->doesntExist();
    }

    /**
     * @param  User  $excluding  el usuario cuyo estado está cambiando a inactivo
     */
    public function wouldLeaveNoActiveAdministrator(User $excluding): bool
    {
        return $this->livingAdministratorsExcluding($excluding)
            ->where('status', UserStatus::Activo)
            ->doesntExist();
    }

    /**
     * RN-CORE-07 releída con el bloqueo tomado (baja lógica): rechaza si
     * `$user` es administrador **ahora** y su salida no deja otro vivo.
     * Solo se llama dentro de `AdministrationCapacityGuard::protect()`.
     */
    public function assertNotLastLivingAdministrator(User $user): void
    {
        if ($this->isAdministrator($user) && $this->wouldLeaveNoLivingAdministrator($user)) {
            throw ApiException::conflict('core.validation.last_school_administrator');
        }
    }

    /**
     * RN-CORE-07 releída con el bloqueo tomado (paso a `inactivo`).
     */
    public function assertNotLastActiveAdministrator(User $user): void
    {
        if ($this->isAdministrator($user) && $this->wouldLeaveNoActiveAdministrator($user)) {
            throw ApiException::conflict('core.validation.last_school_administrator');
        }
    }

    /**
     * RN-CORE-07 releída con el bloqueo tomado cuando la salida del rol
     * se decide sobre los roles releídos (`PUT /users/{id}/roles`).
     */
    public function assertRoleRemovalKeepsAdministrator(User $user, bool $losesAdminCentro): void
    {
        if ($losesAdminCentro && $this->wouldLeaveNoLivingAdministrator($user)) {
            throw ApiException::conflict('core.validation.last_school_administrator');
        }
    }

    /**
     * Consulta fresca (no la relación `roles` ya cargada).
     */
    private function isAdministrator(User $user): bool
    {
        return $user->roles()->where('code', 'administrador_centro')->exists();
    }

    /**
     * @return Builder<User>
     */
    private function livingAdministratorsExcluding(User $excluding): Builder
    {
        $role = Role::query()->where('code', 'administrador_centro')->first();

        if ($role === null) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()
            ->whereKeyNot($excluding->getKey())
            ->whereHas('roles', fn ($q) => $q->whereKey($role->id));
    }
}
