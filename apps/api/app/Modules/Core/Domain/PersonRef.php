<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.4 y `OPEN-FAM-UNIT-03` (ampliación aprobada en `1.14-a`). Objeto de valor devuelto
 * por `PersonDirectory`: el id interno de una persona (para las FK de los módulos consumidores), su `public_id`
 * y su nombre visible, sin ningún otro dato personal (ni documento, ni contacto, ni fecha de nacimiento).
 *
 * `$removed`: la persona está borrada (`deleted_at`). Su `displayName` va entonces **siempre vacío** (#446, B-3,
 * `REQ-PRIV-006`, `ADR-004`): la referencia sobrevive a la baja, el nombre no.
 */
final class PersonRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $displayName,
        public readonly bool $removed = false,
    ) {}
}
