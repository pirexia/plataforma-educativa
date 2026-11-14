<?php

namespace App\Modules\Auth\Domain\Events;

/**
 * funcional.md §C.9.3, §C.4.5 punto 3. Uso de un código de respaldo para
 * completar un login. Dispara el aviso encolado al titular — es la única
 * señal de que alguien entró sin el factor.
 */
final class RecoveryCodeUsed
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly ?string $ipAddress,
    ) {}
}
