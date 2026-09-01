<?php

namespace App\Modules\Auth\Domain;

use App\Modules\Auth\Domain\Models\UserIdentity;
use Illuminate\Support\Collection;

/**
 * funcional.md §E.7.2. Consultar los vínculos vivos de un usuario, sin
 * que otro módulo importe `UserIdentity` directamente (`INV-007`). La
 * consumirán `1.4b` y `1.6` — sin consumidor propio en 1.4.
 */
interface LinkedIdentityDirectory
{
    /**
     * @return Collection<int, UserIdentity>
     */
    public function liveForUser(int $userId): Collection;
}
