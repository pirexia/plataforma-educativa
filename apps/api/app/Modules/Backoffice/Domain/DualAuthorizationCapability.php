<?php

namespace App\Modules\Backoffice\Domain;

use LogicException;

/**
 * permisos.md §5.2. No existe `autorizacion.aprobar`: quien aprueba una
 * `dual_authorization` necesita la capacidad de la **acción autorizada**
 * — aprobar `tenant.eliminar` exige `tenant.eliminar`, no una capacidad
 * genérica que abriría cualquier operación destructiva sin poseer
 * ninguna de ellas (aprobar es tan potente como ejecutar, porque al
 * aprobar se ejecuta).
 */
final class DualAuthorizationCapability
{
    public static function requiredFor(DualAuthorizationAction $action): PlatformCapability
    {
        return match ($action) {
            DualAuthorizationAction::TenantEliminar => PlatformCapability::TenantEliminar,
            DualAuthorizationAction::TenantBaja => PlatformCapability::TenantBaja,
            // 1.6c: sin capacidad todavía en el enum de 1.6b. Se añade
            // cuando ese sub-paso construya `modulo.contratar_masivo`.
            DualAuthorizationAction::ModuloDescontratarMasivo => throw new LogicException(
                'modulo.descontratar_masivo no está implementado todavía (1.6c).'
            ),
        };
    }
}
