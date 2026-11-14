<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Cambio del conjunto de roles de un usuario.
 * Consumidores previstos: REQ-AUTH (reevaluar MFA obligatorio, 1.3),
 * caché de permisos (1.5).
 */
final class UserRolesChanged
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
    ) {}
}
