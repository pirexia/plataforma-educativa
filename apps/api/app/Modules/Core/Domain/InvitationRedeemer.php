<?php

namespace App\Modules\Core\Domain;

use App\Models\User;
use App\Support\Api\ApiException;

/**
 * REQ-AUTH/funcional.md §8.1, §4.1. Interfaz pública para que REQ-AUTH
 * canjee una invitación sin tocar `UserInvitation` ni conocer el formato
 * de su hash (INV-007). El tenant sale del contexto activo, nunca de un
 * parámetro — misma regla que el resto del módulo.
 */
interface InvitationRedeemer
{
    /**
     * Busca la invitación vigente por `(tenant_id, sha256($token))` y,
     * si es válida (no caducada, no revocada, no aceptada, usuario
     * `pendiente`), marca `accepted_at = now()` y devuelve el `User`
     * asociado. **No toca `users`**: password, status y
     * email_verified_at son responsabilidad del llamador (RN-AUTH-20).
     *
     * @throws ApiException `gone()` si el token no es
     *                      válido en el tenant activo.
     */
    public function redeem(string $token): User;
}
