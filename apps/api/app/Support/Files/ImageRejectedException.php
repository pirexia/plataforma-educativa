<?php

namespace App\Support\Files;

use RuntimeException;

/**
 * ADR-068 §5.3: `ImageSanitizer` rechazó la imagen. Nunca se ha escrito nada en el almacenamiento.
 */
final class ImageRejectedException extends RuntimeException
{
    public function __construct(public readonly ImageRejection $reason)
    {
        parent::__construct("Imagen rechazada: {$reason->value}.");
    }
}
