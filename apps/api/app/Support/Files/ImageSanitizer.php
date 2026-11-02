<?php

namespace App\Support\Files;

/**
 * ADR-068 §5.3, `RNF-MANT-007`: reprocesa una imagen subida y devuelve otra **nueva**, limpia de metadatos. La
 * implementación (hoy `GdImageSanitizer`, sobre `gd` y `exif`) queda detrás de esta interfaz.
 *
 * Contrato:
 * - Tipo real por contenido (`finfo`), nunca por extensión ni por `Content-Type`.
 * - La extensión declarada, si es conocida, tiene que casar con el tipo real.
 * - Rechaza por tamaño y por píxeles de entrada **antes** de decodificar.
 * - Aplica la orientación EXIF y la descarta; reduce al lado máximo; recodifica en el mismo formato.
 * - Lo devuelto no lleva EXIF, XMP, IPTC ni perfiles, y su tipo real se vuelve a comprobar.
 */
interface ImageSanitizer
{
    /**
     * @param  string  $path  fichero temporal con la imagen recibida
     * @param  string|null  $declaredExtension  extensión del nombre que declaró el cliente (sin punto), si la hay
     *
     * @throws ImageRejectedException
     */
    public function sanitize(string $path, ?string $declaredExtension, ImageUploadPolicy $policy): SanitizedImage;
}
