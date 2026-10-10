<?php

namespace Tests\Fixtures\Architecture;

use App\Modules\Auth\Infrastructure\Google2FaTotpVerifier;

/**
 * Fixture de los controles negativos de las reglas de arquitectura
 * (ADR-056 AR-01/AR-02, #378 B1): viola a propósito la frontera de módulo
 * usando una clase de `Infrastructure` de otro módulo. NO es código de
 * producción; solo lo lee `expect(...)->not->toUse(...)` en los tests.
 */
final class UsesAuthInfrastructure
{
    public function dependency(): string
    {
        return Google2FaTotpVerifier::class;
    }
}
