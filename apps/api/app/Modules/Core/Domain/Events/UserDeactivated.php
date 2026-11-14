<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Baja lógica de usuario. Consumidores previstos:
 * REQ-AUTH (revocar sesiones, 1.2), REQ-COM.
 */
final class UserDeactivated
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
    ) {}
}
