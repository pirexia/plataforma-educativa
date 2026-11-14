<?php

namespace Tests\Fixtures\Architecture;

use App\Modules\Auth\Domain\MfaVerifier;

/**
 * Fixture de control positivo (ADR-056 AR-01/AR-02, #378 B1): usa solo el
 * `Domain` permitido de otro módulo, así que la regla NO debe morder.
 * Demuestra que los controles negativos no pasan por una regla que siempre
 * lanza. NO es código de producción.
 */
final class UsesAuthDomainContract
{
    public function dependency(): string
    {
        return MfaVerifier::class;
    }
}
