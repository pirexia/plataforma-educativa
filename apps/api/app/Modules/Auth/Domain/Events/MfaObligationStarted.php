<?php

namespace App\Modules\Auth\Domain\Events;

use Illuminate\Support\Carbon;

/**
 * funcional.md §C.9.3, §C.4.8, RN-AUTH-65. Se publica cada vez que se
 * materializa una fila nueva de `user_mfa_obligations`.
 */
final class MfaObligationStarted
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly Carbon $graceDeadlineAt,
        public readonly string $trigger,
    ) {}
}
