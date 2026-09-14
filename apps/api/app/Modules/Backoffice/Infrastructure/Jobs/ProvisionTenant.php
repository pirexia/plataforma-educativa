<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Modules\Backoffice\Application\ActivateProvisionedTenant;
use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\AdminActionLogActorType;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * REQ-BO-001, funcional.md §5.3.3, §5.3.5, `RN-BO-52`, `RN-BO-53`. Fase 2
 * del alta: entra en el contexto del tenant con `runFor()` (dentro de
 * `TenantProvisioner::provision()`), llama al aprovisionamiento público de
 * `REQ-CORE` (`ADR-048`) y, al terminar sin error, transita
 * `en_alta` → `activo` y vuelve a invalidar la caché de resolución.
 *
 * No implementa el aprovisionamiento (`INV-007`): solo orquesta la
 * llamada al contrato público y la transición de estado, que es dato de
 * `REQ-BO`.
 *
 * `bo:retry-provisioning` reencola esta misma clase con el mismo
 * `payload` original (`operacion.md §5.1`): sus propiedades son públicas
 * a propósito, para que ese comando pueda localizar en `failed_jobs` el
 * trabajo que corresponde a un tenant sin tener que adivinar la forma
 * serializada de una propiedad privada.
 */
class ProvisionTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantPublicId,
        public readonly TenantInitialSettings $settings,
        public readonly TenantAdministrator $administrator,
    ) {
        $this->onQueue('backoffice-maintenance');
    }

    public function handle(TenantProvisioner $provisioner, ActivateProvisionedTenant $activator): void
    {
        $tenant = Tenant::query()->where('public_id', $this->tenantPublicId)->first();

        if ($tenant === null) {
            // Defensivo: no debería ocurrir (la fase 1 ya creó la fila
            // antes de encolar este trabajo), pero un tenant que ya no
            // existe no es un fallo que reintentar.
            return;
        }

        if ($tenant->status !== TenantStatus::EnAlta) {
            // Ya se completó (posible reintento manual duplicado, o
            // `bo:retry-provisioning` sobre un trabajo que en realidad ya
            // había terminado): nada que hacer, y no es un fallo.
            return;
        }

        // ADR-048 §4.3: se activa igual tanto si `provision()` ha escrito
        // de verdad como si ya estaba aprovisionado (`AlreadyProvisioned`)
        // — un tenant ya aprovisionado que sigue en `en_alta` es
        // exactamente el caso que repara `bo:retry-provisioning`.
        $provisioner->provision($tenant, $this->settings, $this->administrator);

        $activator->activate($tenant);
    }

    /**
     * funcional.md §5.3.5: agotados los reintentos, el tenant se queda en
     * `en_alta` (RN-BO-52 — no hay transición a la que ir, `409` si se
     * intenta una por API) y se escribe **una** entrada
     * `tenant.aprovisionamiento_fallido` con el error en `context`.
     * **No** se escribe fila en `tenant_lifecycle_events`: no ha habido
     * transición.
     */
    public function failed(?Throwable $exception): void
    {
        $tenant = Tenant::query()->where('public_id', $this->tenantPublicId)->first();

        if ($tenant === null) {
            return;
        }

        app(AdminActionLogRecorder::class)->record(
            action: AdminActionLogAction::TenantAprovisionamientoFallido,
            subjectType: 'tenant',
            subjectId: $tenant->id,
            subjectPublicId: $tenant->public_id,
            affectedTenantId: $tenant->id,
            context: ['error' => $exception?->getMessage()],
            actorType: AdminActionLogActorType::System,
        );
    }
}
