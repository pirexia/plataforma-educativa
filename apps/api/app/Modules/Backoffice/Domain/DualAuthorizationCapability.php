<?php

namespace App\Modules\Backoffice\Domain;

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
            // 1.6c, permisos.md §4.4 punto 3: aprobar una descontratación
            // masiva exige `modulo.contratar_masivo`, no una capacidad de
            // aprobación propia.
            DualAuthorizationAction::ModuloDescontratarMasivo => PlatformCapability::ModuloContratarMasivo,
        };
    }
}
