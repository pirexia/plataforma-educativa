<?php

namespace App\Modules\Auth\Domain\Events;

/**
 * funcional.md §C.9.3, §C.4.10. Restablecimiento de MFA por el
 * administrador. Distinto del modelo `App\Modules\Auth\Domain\Models\MfaReset`
 * (la traza persistida): este es el evento que dispara el aviso encolado
 * al titular.
 */
final class MfaReset
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly string $performedByPublicId,
    ) {}
}
