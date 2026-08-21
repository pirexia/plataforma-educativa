<?php

namespace App\Modules\Core\Domain\Events;

/**
 * funcional.md §7. Emisión o reenvío. Consumidor previsto: REQ-COM (1.19),
 * que sustituirá el envío directo de 1.1.
 */
final class InvitationIssued
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $invitationPublicId,
        public readonly string $userPublicId,
    ) {}
}
