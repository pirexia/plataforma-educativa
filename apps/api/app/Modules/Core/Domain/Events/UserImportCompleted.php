<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Fin de una importación (completada o fallida).
 * Consumidor previsto: REQ-ONB (1.24).
 */
final class UserImportCompleted
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userImportPublicId,
        public readonly int $createdCount,
    ) {}
}
