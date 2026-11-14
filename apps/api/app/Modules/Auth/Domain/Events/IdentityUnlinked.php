<?php

namespace App\Modules\Auth\Domain\Events;

/**
 * funcional.md §E.7.3. Desvinculación desde el perfil. Consumidor
 * previsto: `REQ-COM` (1.19).
 */
final class IdentityUnlinked
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly string $provider,
    ) {}
}
