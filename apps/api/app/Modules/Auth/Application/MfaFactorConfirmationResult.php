<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Models\MfaFactor;

/**
 * funcional.md §C.4.1 punto 9: los códigos de respaldo en claro viajan
 * **solo** cuando se han generado — el primer factor confirmado del
 * usuario, y ninguna otra vez (`RN-AUTH-56`).
 */
final class MfaFactorConfirmationResult
{
    /**
     * @param  list<string>|null  $recoveryCodes
     */
    public function __construct(
        public readonly MfaFactor $factor,
        public readonly ?array $recoveryCodes,
    ) {}
}
