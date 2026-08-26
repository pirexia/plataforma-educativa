<?php

namespace App\Modules\Auth\Domain\Events;

use App\Modules\Auth\Domain\SessionEndReason;

/**
 * funcional.md §B.8.2. Una sesión revocada por su propio titular
 * (`DELETE /auth/sessions/{public_id}` o `DELETE /auth/sessions`).
 * Consumidor previsto: REQ-COM (1.19) y REQ-BI. No se emite para los
 * demás cierres (inactividad, caducidad, cambio de credencial, baja de
 * usuario, tenant incoherente): esos no son una acción deliberada del
 * titular sobre su sesión.
 */
final class SessionRevoked
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly string $sessionPublicId,
        public readonly SessionEndReason $reason,
    ) {}
}
