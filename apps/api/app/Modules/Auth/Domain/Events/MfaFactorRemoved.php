<?php

namespace App\Modules\Auth\Domain\Events;

/**
 * funcional.md §C.9.3. Desactivación por el propio usuario o por
 * restablecimiento del administrador (`removedByAdmin` distingue los dos).
 */
final class MfaFactorRemoved
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly string $method,
        public readonly bool $removedByAdmin,
    ) {}
}
