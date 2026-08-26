<?php

namespace App\Modules\Auth\Domain\Events;

/**
 * funcional.md §C.9.3. No se expone por API. Lo consume el propio módulo
 * para encolar el aviso al titular (`§C.4.13`); `REQ-COM` (1.19) lo
 * sustituirá por su canal.
 */
final class MfaFactorConfirmed
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly string $method,
    ) {}
}
