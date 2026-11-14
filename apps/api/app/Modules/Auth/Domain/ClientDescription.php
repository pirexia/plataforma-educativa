<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §B.6.4. Resultado de `ClientDescriber`: solo texto para
 * mostrar en el panel. Nunca participa en la decisión de `RN-AUTH-46`.
 */
final class ClientDescription
{
    public function __construct(
        public readonly string $browser,
        public readonly string $platform,
        public readonly ClientDeviceType $deviceType,
    ) {}
}
