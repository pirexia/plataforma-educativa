<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Restauración de un usuario dado de baja lógica.
 */
final class UserRestored
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
    ) {}
}
