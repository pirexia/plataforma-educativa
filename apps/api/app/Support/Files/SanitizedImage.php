<?php

namespace App\Support\Files;

/**
 * ADR-068 §5.3: el resultado de `ImageSanitizer`: la imagen recodificada (sin EXIF, XMP, IPTC ni perfiles), con su tipo
 * real **tras** el reprocesado. El original no sobrevive en ningún sitio.
 */
final class SanitizedImage
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $byteSize,
    ) {}
}
