<?php

namespace App\Modules\Backoffice\Domain;

use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantStatus;

/**
 * `RN-BO-12`, permisos.md §4.3. Las cinco transiciones permitidas —y
 * ninguna más— junto con la capacidad exacta que exige cada una. Un
 * único sitio con la máquina de estados completa: `POST
 * /tenants/{id}/transitions` es la única puerta (api.md §2.4), y esta
 * clase es la que decide si la arista existe y qué hace falta para
 * cruzarla.
 *
 * `en_alta` no aparece como origen de ninguna arista a propósito: de ahí
 * no se sale por API (`RN-BO-52`), y cualquier intento cae en el
 * `default` de abajo, `409`.
 */
final class TenantTransitionCapability
{
    public static function requiredFor(TenantStatus $from, TenantStatus $to): PlatformCapability
    {
        return match (true) {
            $from === TenantStatus::Activo && $to === TenantStatus::Suspendido => PlatformCapability::TenantSuspender,
            $from === TenantStatus::Suspendido && $to === TenantStatus::Activo => PlatformCapability::TenantSuspender,
            $from === TenantStatus::Activo && $to === TenantStatus::EnBaja => PlatformCapability::TenantBaja,
            // El rescate se autoriza con tenant.baja, no con
            // tenant.suspender: quien no puede dar de baja un centro
            // tampoco debe poder deshacer la baja que decidió otro
            // (permisos.md §4.3).
            $from === TenantStatus::EnBaja && $to === TenantStatus::Activo => PlatformCapability::TenantBaja,
            $from === TenantStatus::EnBaja && $to === TenantStatus::Eliminado => PlatformCapability::TenantEliminar,
            default => throw ApiException::conflict('bo.tenant.invalid_transition'),
        };
    }
}
