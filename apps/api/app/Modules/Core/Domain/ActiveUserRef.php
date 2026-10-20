<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-ACAD-002 `RN-ACAD-92`. Objeto de valor devuelto por
 * `UserDirectory::findActiveByPublicId()`: el id interno (para FK) y el
 * nombre visible, sin correo ni otros datos.
 *
 * `$removed` (#446, B-3): el usuario está borrado (`deleted_at`). Su `displayName` va entonces **siempre vacío**:
 * la referencia sobrevive a la baja para no romper el histórico, pero el nombre no (`REQ-PRIV-006`, `ADR-004`).
 * El consumidor pone su propio marcador neutro traducido.
 */
final class ActiveUserRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $displayName,
        public readonly bool $removed = false,
    ) {}
}
