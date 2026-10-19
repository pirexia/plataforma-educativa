<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-ACAD-002 `RN-ACAD-92`. Objeto de valor devuelto por
 * `UserDirectory::findActiveByPublicId()`: el id interno (para FK) y el
 * nombre visible, sin correo ni otros datos.
 */
final class ActiveUserRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $displayName,
    ) {}
}
