<?php

namespace App\Modules\Backoffice\Domain;

use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;

/**
 * `api.md §2.4.1`. `provisioning.state` es un enumerado de respuesta
 * derivado, nunca una columna (`ADR-034 OPEN-13`): con `status =
 * 'en_alta'` más la existencia o no de `tenant.aprovisionamiento_fallido`
 * está todo dicho. La consulta extra sólo se ejecuta para tenants en
 * `en_alta` — la inmensa mayoría no la paga.
 *
 * Extraído de `TenantResource` en `1.6d` (`api.md §2.10.1` punto 5): la
 * ficha de salud reutiliza este mismo cálculo (`RN-BO-22` aplicado por
 * analogía — una sola implementación también para leerlo desde un
 * segundo sitio).
 */
final class TenantProvisioningState
{
    public static function resolve(Tenant $tenant): string
    {
        if ($tenant->status !== TenantStatus::EnAlta) {
            return 'completado';
        }

        $failed = AdminActionLog::query()
            ->where('affected_tenant_id', $tenant->id)
            ->where('action', AdminActionLogAction::TenantAprovisionamientoFallido)
            ->exists();

        return $failed ? 'fallido' : 'en_curso';
    }
}
