<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Cambio del correo de acceso. Consumidor previsto:
 * REQ-AUTH (1.2, revocar sesiones activas de ese usuario).
 */
final class UserEmailChanged
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
    ) {}
}
