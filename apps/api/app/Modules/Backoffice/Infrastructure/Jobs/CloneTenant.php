<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Models\ModuleSubscription;
use App\Modules\Backoffice\Application\ActivateProvisionedTenant;
use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\AdminActionLogActorType;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * RMT-007, funcional.md §5.6, `RN-BO-21`, `RN-BO-59`, `ADR-048 §4.5`.
 * Fase 2 de la clonación: copia configuración operativa, roles y
 * concesiones del origen por `TenantProvisioner::provisionFromTemplate()`
 * (`REQ-CORE`, `INV-007`) — **nunca** personas, usuarios, invitaciones ni
 * auditoría. `module_subscriptions` es la excepción y la copia este
 * trabajo, del que el backoffice es único escritor (`ADR-045 §4.1`).
 *
 * Transita a `activo` al terminar, con la misma gestión de fallo que el
 * alta (`ActivateProvisionedTenant`, `ProvisionTenant::failed()`).
 */
class CloneTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $sourceTenantPublicId,
        public readonly string $targetTenantPublicId,
        public readonly TenantAdministrator $administrator,
    ) {
        $this->onQueue('backoffice-maintenance');
    }

    public function handle(TenantProvisioner $provisioner, ActivateProvisionedTenant $activator, TenantContext $tenantContext): void
    {
        $source = Tenant::withTrashed()->where('public_id', $this->sourceTenantPublicId)->first();
        $target = Tenant::query()->where('public_id', $this->targetTenantPublicId)->first();

        if ($source === null || $target === null) {
            return;
        }

        if ($target->status !== TenantStatus::EnAlta) {
            return;
        }

        $provisioner->provisionFromTemplate($source, $target, $this->administrator);

        $this->cloneModuleSubscriptions($tenantContext, $source->id, $target->id);

        $activator->activate($target);
    }

    /**
     * funcional.md §5.6.2: se copian **con `enabled_at = now()`** y un
     * `reason` propio que dice que vienen de un clon — nunca
     * `enabled_at`/`disabled_at` del origen. `ADR-045 §4.1`: el
     * backoffice es el único escritor de esta tabla.
     *
     * **`1.6c`, hallazgo de severidad Alta corregido en este mismo
     * cambio** (issue de esta migración de privilegios, ver commit):
     * antes de `1.6c`, este método leía y escribía `module_subscriptions`
     * por `runFor()` normal (conexión `pgsql`, rol `plataforma_app`), y
     * era correcto porque ese rol todavía tenía `INSERT`/`UPDATE`/`SELECT`
     * completos sobre la tabla. La migración de `datos.md §7`/`§7.7` se
     * los revoca, así que la escritura de esta clonación —que sigue sin
     * pasar por `ModuleContracting` (funcional.md §5.8.2: el sembrado
     * inicial de un clon no es "contratar/descontratar")— rompería en
     * seco: ni podría leer con `SELECT *` ni podría escribir. La
     * corrección es forzar la conexión a `pgsql_platform`
     * (`ModuleSubscription::on(...)`, sin necesidad y sin poder entrar
     * en `runAsPlatform()`: es un trabajo en cola sin sesión de
     * administrador, y `BackofficeEscritura`/`BackofficeLectura` la
     * exigen) permaneciendo en modo de tenant normal vía `runFor()`, para
     * que `TenantScope` siga filtrando por el tenant correcto y
     * `AuditRecorder` siga auditando esta escritura con normalidad —
     * exactamente el comportamiento de antes de `1.6c`, ahora sobre la
     * conexión que conserva privilegio completo.
     */
    private function cloneModuleSubscriptions(TenantContext $tenantContext, int $sourceTenantId, int $targetTenantId): void
    {
        $subscriptions = $tenantContext->runFor(
            $sourceTenantId,
            fn () => ModuleSubscription::on('pgsql_platform')->where('enabled', true)->get(),
        );

        if ($subscriptions->isEmpty()) {
            return;
        }

        $tenantContext->runFor($targetTenantId, function () use ($subscriptions): void {
            // AuditActor::actingAs('console', ...) (issue #196, mismo
            // patrón que ProvisionTenantDefaults y RevokeTenantSessions,
            // que este método se quedó sin recibir): quien dispara este
            // job es un administrador de plataforma, nunca un usuario del
            // tenant destino — sin esto, created_by/updated_by intentan
            // grabar su id y violan module_subscriptions_tenant_id_
            // created_by_foreign (compuesta contra users del tenant).
            AuditActor::actingAs('console', function () use ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    // updateOrCreate(), no create() (issue #206): sin esto,
                    // un fallo a mitad del bucle (p. ej. la segunda de tres
                    // suscripciones) deja ya comprometidas las filas
                    // anteriores; un reintento de bo:retry-provisioning
                    // vuelve a recorrer el bucle entero desde el principio
                    // y el INSERT de la primera suscripción viola
                    // module_subscriptions_tenant_module_unique, dejando
                    // el clon sin recuperación posible por ese camino.
                    // Idempotente: repetir esta llamada con los mismos
                    // datos no duplica ni cambia nada.
                    ModuleSubscription::on('pgsql_platform')->updateOrCreate(
                        ['module_code' => $subscription->module_code],
                        [
                            'enabled' => true,
                            'enabled_at' => now(),
                            'reason' => 'bo.module_subscription.reason.cloned',
                            'settings' => $subscription->settings,
                        ],
                    );
                }
            });
        });
    }

    /**
     * Misma gestión de fallo que `ProvisionTenant::failed()`
     * (funcional.md §5.6.4: "con la misma gestión de fallo de §5.3.5"):
     * el destino se queda en `en_alta`, y se registra
     * `tenant.aprovisionamiento_fallido`. `bo:retry-provisioning` repara
     * también este caso, reencolando el mismo trabajo.
     */
    public function failed(?Throwable $exception): void
    {
        $target = Tenant::query()->where('public_id', $this->targetTenantPublicId)->first();

        if ($target === null) {
            return;
        }

        app(AdminActionLogRecorder::class)->record(
            action: AdminActionLogAction::TenantAprovisionamientoFallido,
            subjectType: 'tenant',
            subjectId: $target->id,
            subjectPublicId: $target->public_id,
            affectedTenantId: $target->id,
            context: ['error' => $exception?->getMessage()],
            actorType: AdminActionLogActorType::System,
        );
    }
}
