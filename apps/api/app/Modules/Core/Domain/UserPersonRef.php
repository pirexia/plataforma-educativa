<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §4.2 y `OPEN-FAM-UNIT-03` (ampliación aprobada en `1.14-a`). Un usuario del centro visto
 * desde su persona: ids interno y público del usuario y de la persona, estado de la cuenta (`pendiente`, `activo`,
 * `inactivo`) y nombre visible. Sin correo ni otros datos.
 *
 * `$removed`: el usuario está borrado (`deleted_at`); su `displayName` va entonces vacío (#446, B-3).
 */
final class UserPersonRef
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userPublicId,
        public readonly int $personId,
        public readonly string $personPublicId,
        public readonly string $status,
        public readonly string $displayName,
        public readonly bool $removed = false,
    ) {}
}
