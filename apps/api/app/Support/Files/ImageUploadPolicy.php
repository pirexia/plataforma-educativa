<?php

namespace App\Support\Files;

use InvalidArgumentException;

/**
 * ADR-068 §5.3, REQ-FAM-UNIT-005 (`RN-FAM-UNIT-83`): lo que una subida de imagen privada admite. Objeto de valor sin
 * lógica: tipos reales admitidos, bytes máximos del fichero, píxeles máximos de **entrada** (se comprueban antes de
 * decodificar, contra las bombas de descompresión) y lado máximo de **salida** (la imagen se reduce a él).
 *
 * Solo `image/jpeg`, `image/png` e `image/webp` son admisibles: nunca SVG (puede llevar *scripts*) ni HEIC (sin
 * decodificador fiable en `gd`).
 */
final class ImageUploadPolicy
{
    /** Tipos que `GdImageSanitizer` sabe decodificar y recodificar. */
    public const SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param  list<string>  $allowedMimeTypes  subconjunto de `SUPPORTED_TYPES`
     */
    public function __construct(
        public readonly array $allowedMimeTypes,
        public readonly int $maxBytes,
        public readonly int $maxInputPixels,
        public readonly int $maxSide,
    ) {
        if ($allowedMimeTypes === [] || array_diff($allowedMimeTypes, self::SUPPORTED_TYPES) !== []) {
            throw new InvalidArgumentException('ImageUploadPolicy solo admite image/jpeg, image/png e image/webp.');
        }

        if ($maxBytes < 1 || $maxInputPixels < 1 || $maxSide < 1) {
            throw new InvalidArgumentException('ImageUploadPolicy exige límites positivos.');
        }
    }
}
