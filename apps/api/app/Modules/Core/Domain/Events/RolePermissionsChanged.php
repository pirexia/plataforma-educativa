<?php

namespace App\Modules\Core\Domain\Events;

/**
 * REQ-PERM/funcional.md §15.3, api.md §5.3, `OPEN-PERM-04` (abierta, no
 * bloqueante). Se emite en cada `PUT /roles/{id}/permissions` que cambia
 * de verdad el conjunto de concesiones de un rol. Sin consumidor en 1.5:
 * se emite porque el día que exista caché de permisos resueltos
 * (`ADR-044 §4.7`, descartada hoy) o un panel de plataforma, la señal debe
 * existir antes que su consumidor y no al revés.
 */
final class RolePermissionsChanged
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $rolePublicId,
    ) {}
}
