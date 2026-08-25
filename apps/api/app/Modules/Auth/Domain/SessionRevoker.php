<?php

namespace App\Modules\Auth\Domain;

use App\Models\User;
use App\Modules\Auth\Domain\Models\UserSession;

/**
 * funcional.md §8.4, ampliada en §B.2.1 punto 1 y §B.8.3 (1.2b). Revoca
 * sesiones activas de un usuario. La razón del cierre es obligatoria y la
 * decide quien llama, no el revocador (`RN-AUTH-44`: toda fila cerrada
 * lleva una de las siete razones de `funcional.md §B.4.6`).
 *
 * Consumidores: el *listener* de `UserDeactivated` (`baja_usuario`),
 * `PasswordResetService` y `PasswordChangeService` (`cambio_credencial`),
 * y desde 1.2b el propio usuario a través de
 * `UserSessionsController` (`revocada_usuario`).
 */
interface SessionRevoker
{
    /**
     * Revoca todas las sesiones activas del usuario, salvo
     * `$exceptSessionId` si se indica (RN-AUTH-36: el cambio de contraseña
     * auto-servicio conserva la sesión actual; el restablecimiento y la
     * baja de usuario las revocan todas, RN-AUTH-22).
     */
    public function revokeAllForUser(User $user, SessionEndReason $reason, ?string $exceptSessionId = null): void;

    /**
     * Revoca una sesión concreta (`DELETE /auth/sessions/{public_id}`,
     * `RN-AUTH-42`). `$revokedBy` es el propio titular en el autoservicio;
     * `null` en cualquier cierre automático.
     */
    public function revokeSession(UserSession $session, SessionEndReason $reason, ?User $revokedBy = null): void;
}
