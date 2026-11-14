<?php

namespace App\Modules\Auth\Domain;

use RuntimeException;
use Throwable;

/**
 * `funcional.md §G.4.2` (REQ-AUTH-004, 1.4c). Cualquiera de las guardas
 * incumplida. `RN-AUTH-113` ampliada a este canal: nunca lleva el destino
 * ni el error de red en el mensaje visible — solo el
 * `SamlMetadataFailureCode`, que es lo único que sale por la API.
 */
final class SamlMetadataValidationException extends RuntimeException
{
    public readonly SamlMetadataFailureCode $failureCode;

    public function __construct(SamlMetadataFailureCode $failureCode, ?Throwable $previous = null)
    {
        $this->failureCode = $failureCode;

        parent::__construct($failureCode->value, 0, $previous);
    }
}
