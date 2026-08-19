<?php

namespace App\Modules\Core\Application;

use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * RN-CORE-07: siempre al menos un `administrador_centro` vivo; al menos
 * uno activo si la operación es un cambio de estado (api.md §3,
 * `POST /users/{id}/status`, matiz distinto de la baja lógica — "vivo"
 * no es lo mismo que "activo": un administrador `pendiente` cuenta como
 * vivo pero no como activo).
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
