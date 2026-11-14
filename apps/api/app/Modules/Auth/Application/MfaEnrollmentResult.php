<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Models\MfaFactor;

/**
 * funcional.md §C.4.1 punto 4. El secreto y la URI `otpauth://` solo
 * viajan en el resultado del alta — nunca se vuelven a leer del servidor
 * (`RN-AUTH-55`).
 *
 * `destinationMasked` (1.3b, funcional.md §D.4.1 punto 4): la respuesta de
 * un alta de método de entrega no devuelve nada verificable — ni secreto
 * ni código —, solo el destino enmascarado (`RN-AUTH-75`).
 */
final class MfaEnrollmentResult
{
    public function __construct(
        public readonly MfaFactor $factor,
        public readonly ?string $secretBase32,
        public readonly ?string $otpAuthUri,
        public readonly ?string $destinationMasked = null,
    ) {}
}
