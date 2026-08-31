<?php

namespace App\Modules\Auth\Domain;

use RuntimeException;
use Throwable;

/**
 * `ADR-042 §4.4`. Ninguna excepción propia de Socialite
 * (`Laravel\Socialite\Two\InvalidStateException` incluida) sale del
 * adaptador: se traduce siempre a esta, con uno de los tres motivos de
 * `ExternalIdentityFailure`. Sin esto, el controlador acabaría
 * capturando `\Exception` y decidiendo por el texto del mensaje, que es
 * exactamente lo que no queremos que dependa de una biblioteca externa.
 */
final class ExternalIdentityException extends RuntimeException
{
    public function __construct(
        public readonly ExternalIdentityFailure $failure,
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $failure->name, 0, $previous);
    }
}
