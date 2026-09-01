<?php

namespace App\Modules\Auth\Domain;

use RuntimeException;
use Throwable;

/**
 * `funcional.md §F.4.2`. Cualquiera de las cinco guardas incumplida.
 * `RN-AUTH-113`: nunca lleva el destino ni el error de red en el mensaje
 * visible — solo el `DiscoveryFailureCode`, que es lo único que sale por
 * la API (`api.md §F.3`).
 */
final class DiscoveryValidationException extends RuntimeException
{
    public readonly DiscoveryFailureCode $failureCode;

    public function __construct(DiscoveryFailureCode $failureCode, ?Throwable $previous = null)
    {
        $this->failureCode = $failureCode;

        // RuntimeException::$code (heredado) no es de solo lectura: no
        // se puede redeclarar como readonly con el mismo nombre. El
        // motivo por el que existe esta clase entera vive en
        // $failureCode, no en $code.
        parent::__construct($failureCode->value, 0, $previous);
    }
}
