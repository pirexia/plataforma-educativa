<?php

namespace App\Modules\Auth\Domain;

use App\Models\User;
use App\Modules\Auth\Domain\Models\UserSession;
use Illuminate\Database\Eloquent\Collection;

/**
 * funcional.md §B.8.3. Consultar las sesiones vivas de un usuario. La
 * consumirá 1.6 (soporte de plataforma) y, si algún día se aprueba, la
 * vista de administración que `permisos.md §B.2` deja fuera de 1.2b. Se
 * expone desde ya para que ese paso no tenga que abrir el modelo del
 * módulo (`INV-007`).
 */
interface UserSessionDirectory
{
    /**
     * @return Collection<int, UserSession>
     */
    public function liveSessionsForUser(User $user): Collection;
}
