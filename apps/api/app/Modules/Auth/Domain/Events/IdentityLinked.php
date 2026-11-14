<?php

namespace App\Modules\Auth\Domain\Events;

/**
 * funcional.md §E.7.3. Fusión automática o vinculación desde el perfil —
 * `linkMethod` distingue las dos. Consumidor previsto: `REQ-COM` (1.19),
 * `REQ-BI`.
 */
final class IdentityLinked
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly string $provider,
        public readonly string $linkMethod,
    ) {}
}
