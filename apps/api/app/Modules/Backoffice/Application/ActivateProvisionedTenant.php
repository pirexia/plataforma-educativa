<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\AdminActionLogActorType;
use App\Modules\Backoffice\Domain\Models\TenantLifecycleEvent;
use App\Modules\Backoffice\Domain\TenantLifecycleSystemReason;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantResolutionCache;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §5.3.3, §5.6.4, `RN-BO-52`. La transición `en_alta` →
 * `activo` que produce el aprovisionamiento, común al alta y a la
 * clonación (§5.6.4: "transita a `activo` al terminar el trabajo, con la
 * misma gestión de fallo de §5.3.5"). Un único sitio para que las dos
 * fases 2 (`ProvisionTenant`, `CloneTenant`) no diverjan.
 */
final class ActivateProvisionedTenant
{
    public function __construct(
        private readonly AdminActionLogRecorder $recorder,
    ) {}

    public function activate(Tenant $tenant): void
    {
        DB::connection('pgsql_platform')->transaction(function () use ($tenant): void {
            $tenant->forceFill(['status' => TenantStatus::Activo])->save();

            TenantLifecycleEvent::create([
                'affected_tenant_id' => $tenant->id,
                'from_status' => TenantStatus::EnAlta,
                'to_status' => TenantStatus::Activo,
                'reason' => TenantLifecycleSystemReason::Provisioned->value,
                'occurred_at' => now(),
                'performed_by' => null,
            ]);

            $this->recorder->record(
                action: AdminActionLogAction::TenantActualizado,
                subjectType: 'tenant',
                subjectId: $tenant->id,
                subjectPublicId: $tenant->public_id,
                affectedTenantId: $tenant->id,
                reason: TenantLifecycleSystemReason::Provisioned->value,
                actorType: AdminActionLogActorType::System,
            );

            DB::connection('pgsql_platform')->afterCommit(fn () => TenantResolutionCache::forget($tenant->slug));
        });
    }
}
