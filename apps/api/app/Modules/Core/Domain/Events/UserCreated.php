<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Alta de usuario, individual o por importación.
 * Consumidores previstos: REQ-COM (1.19), REQ-RRHH.
 */
final class UserCreated
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
    ) {}
}
