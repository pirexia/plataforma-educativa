<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Revocación manual, reemisión o caducidad.
 */
final class InvitationRevoked
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $invitationPublicId,
        public readonly string $userPublicId,
    ) {}
}
