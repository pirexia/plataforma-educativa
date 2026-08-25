<?php

namespace App\Modules\Auth\Domain;

use App\Models\User;

/**
 * funcional.md §8.4. Revoca sesiones activas de un usuario. La consume
 * `REQ-CORE` a través del evento `UserDeactivated` (§8.2) y la consumirá
 * 1.2b para el cierre remoto por el propio usuario.
 */
interface SessionRevoker
{
    /**
     * Revoca todas las sesiones activas del usuario, salvo
     * `$exceptSessionId` si se indica (RN-AUTH-36: el cambio de contraseña
     * auto-servicio conserva la sesión actual; el restablecimiento y la
     * baja de usuario las revocan todas, RN-AUTH-22).
     */
    public function revokeAllForUser(User $user, ?string $exceptSessionId = null): void;
}
