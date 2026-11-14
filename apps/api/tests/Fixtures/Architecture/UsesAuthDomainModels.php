<?php

namespace Tests\Fixtures\Architecture;

use App\Modules\Auth\Domain\Models\UserSession;

/**
 * Fixture de control negativo (ADR-056 AR-01/AR-02, #378 B1): viola la
 * frontera usando `Domain\Models` de otro módulo. NO es código de producción.
 */
final class UsesAuthDomainModels
{
    public function dependency(): string
    {
        return UserSession::class;
    }
}
