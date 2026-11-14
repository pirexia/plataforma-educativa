<?php

namespace App\Support\Files;

/**
 * ADR-068 §5.3: por qué `ImageSanitizer` rechaza una imagen. El llamador (un módulo) lo traduce a su error de API; el
 * núcleo no conoce los códigos de ningún módulo.
 */
enum ImageRejection: string
{
    /** Más bytes que `ImageUploadPolicy::$maxBytes` (⇒ `413`). */
    case TooLarge = 'too_large';

    /** El tipo real no está entre los admitidos: SVG, HEIC, PDF... (⇒ `415`). */
    case UnsupportedType = 'unsupported_type';

    /** La extensión declarada no casa con el tipo real (⇒ `422`). */
    case TypeMismatch = 'type_mismatch';

    /** Más píxeles de entrada que el máximo, o dimensiones ilegibles (⇒ `422`). */
    case Dimensions = 'dimensions';
}
