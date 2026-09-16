<?php

namespace App\Modules\Backoffice\Application;

use App\Models\Module;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\DualAuthorizationAction;
use App\Modules\Backoffice\Domain\DualAuthorizationStatus;
use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Infrastructure\Jobs\RunModuleRollout;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Modules\Core\Domain\ModuleChange;
use App\Modules\Core\Domain\ModuleContracting;
use App\Modules\Core\Domain\ModuleContractingOutcome;
use App\Modules\Core\Domain\ModuleContractingPreview;
use App\Support\Api\ApiException;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * `REQ-BO-002`, `ADR-045`, funcional.md §5.8. Orquesta la contratación y
 * descontratación de módulos: capacidades, reautenticación, doble
 * autorización, `Idempotency-Key` y `admin_action_logs` son suyos
 * (`REQ-BO`); resolver dependencias, bloquear y escribir
 * `module_subscriptions` es de `REQ-CORE`, a través de `ModuleContracting`
 * (`INV-007`, funcional.md §5.8.2).
 *
 * `RN-BO-71`: los cinco estados de tenant frente a la escritura de
 * módulos. `activo`, `suspendido` y `en_baja` admiten escritura;
 * `en_alta` y `eliminado` no.
 */
final class ModuleSubscriptionsService
{
    private const WRITABLE_STATUSES = [TenantStatus::Activo, TenantStatus::Suspendido, TenantStatus::EnBaja];

    public function __construct(
        private readonly ModuleContracting $contracting,
        private readonly ModuleCatalog $catalog,
        private readonly AdminActionLogRecorder $recorder,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * `POST /tenants/{public_id}/modules/preview`, api.md §2.7. Hace del
     * 2 al 6 de `funcional.md §5.8.4` y devuelve el cierre sin escribir
     * nada y sin bloquear nada (`RN-BO-68`).
     */
    public function preview(Tenant $tenant, ModuleChange $change): ModuleContractingPreview
    {
        $this->guardModuleExists($change->moduleCode);
        $this->guardTenantWritable($tenant);

        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeLectura,
            fn (): ModuleContractingPreview => $this->contracting->preview($tenant->id, $change),
        );
    }

    /**
     * `POST /module-rollouts/preview`. Agrega la vista previa de cada
     * centro de la lista — `ModuleContracting::preview()` es de un solo
     * tenant (funcional.md §5.8.2) — sin escribir ni bloquear nada
     * (`RN-BO-68`). Un centro cuyo estado no admite escritura se excluye
     * del recuento, igual que lo excluiría la ejecución (`RN-BO-71`).
     *
     * @param  list<Tenant>  $tenants
     * @return array{affected_tenants: int, modules_to_contract: list<string>, modules_to_decontract: list<string>, cascaded_dependencies: list<string>, blocked: list<array{module_code: string, reason_code: string}>, impact: array{users_affected: int, screens_removed: list<string>, integrations_disabled: list<string>}}
     */
    public function previewBulk(array $tenants, ModuleChange $change): array
    {
        $this->guardModuleExists($change->moduleCode);

        $affectedTenants = 0;
        $modulesToContract = [];
        $modulesToDecontract = [];
        $cascaded = [];
        $blocked = [];
        $usersAffected = 0;

        foreach ($tenants as $tenant) {
            if (! in_array($tenant->status, self::WRITABLE_STATUSES, true)) {
                continue;
            }

            $preview = $this->tenantContext->runAsPlatform(
                PlatformAccessPurpose::BackofficeLectura,
                fn (): ModuleContractingPreview => $this->contracting->preview($tenant->id, $change),
            );

            if ($preview->modulesToContract !== [] || $preview->modulesToDecontract !== [] || $preview->blocked !== []) {
                $affectedTenants++;
            }

            $modulesToContract = [...$modulesToContract, ...$preview->modulesToContract];
            $modulesToDecontract = [...$modulesToDecontract, ...$preview->modulesToDecontract];
            $cascaded = [...$cascaded, ...$preview->cascadedDependencies];
            $blocked = [...$blocked, ...$preview->blocked];
            $usersAffected += $preview->impact['users_affected'];
        }

        return [
            'affected_tenants' => $affectedTenants,
            'modules_to_contract' => array_values(array_unique($modulesToContract)),
            'modules_to_decontract' => array_values(array_unique($modulesToDecontract)),
            'cascaded_dependencies' => array_values(array_unique($cascaded)),
            'blocked' => array_values(array_unique($blocked, SORT_REGULAR)),
            'impact' => [
                'users_affected' => $usersAffected,
                'screens_removed' => [],
                'integrations_disabled' => [],
            ],
        ];
    }

    /**
     * `PUT /tenants/{public_id}/modules/{module_code}`, api.md §2.6.3.
     * La capacidad y, con `enabled: false`, la reautenticación ya se
     * comprobaron antes de llegar aquí (`OPEN-BO-17`).
     *
     * @return array{outcome: ModuleContractingOutcome, cache_invalidated: bool}
     */
    public function putModule(Tenant $tenant, ModuleChange $change, PlatformAdmin $actor): array
    {
        $this->guardReasonPresent($change->reason);
        $this->guardModuleExists($change->moduleCode);
        $this->guardTenantWritable($tenant);

        return $this->applyChange($tenant, $change);
    }

    /**
     * `RN-BO-75`: fase 1 (bloquea, valida, escribe `module_subscriptions`
     * y `admin_action_logs`, todo en la misma transacción) dentro de
     * `runAsPlatform(BackofficeEscritura, …)`; fase 2 (invalidación de
     * caché y eventos) fuera del bloque y después del `COMMIT`.
     *
     * @return array{outcome: ModuleContractingOutcome, cache_invalidated: bool}
     */
    public function applyChange(Tenant $tenant, ModuleChange $change): array
    {
        // `RN-BO-69`: contratar lo ya contratado (o descontratar lo ya
        // descontratado) es no-operación completa y **no audita** — pero
        // sólo se sabe bajo el bloqueo de `apply()`, ya dentro del
        // bloque. `ModuleChangeWasNoOp` es la señal de control con la
        // que se sale de él sin dejar fila en `admin_action_logs`, sin
        // que la comprobación de cierre de `BackofficeEscritura`
        // (`ADR-046 §6.5`) la confunda con una escritura de plataforma
        // olvidada: `TenantContext::runAsPlatform()` sólo exige rastro
        // en el camino de éxito, nunca cuando el bloque lanza.
        try {
            $outcome = $this->tenantContext->runAsPlatform(
                PlatformAccessPurpose::BackofficeEscritura,
                fn (): ModuleContractingOutcome => DB::connection('pgsql_platform')->transaction(
                    function () use ($tenant, $change): ModuleContractingOutcome {
                        $outcome = $this->contracting->apply($tenant->id, $change);

                        if ($outcome->isEmpty()) {
                            throw new ModuleChangeWasNoOp($outcome);
                        }

                        $this->recordAdminActionLogs($tenant, $change, $outcome);

                        return $outcome;
                    }
                ),
            );
        } catch (ModuleChangeWasNoOp $noOp) {
            return ['outcome' => $noOp->outcome, 'cache_invalidated' => true];
        }

        $cacheInvalidated = true;

        if (! $outcome->isEmpty()) {
            try {
                $this->contracting->publish($outcome);
            } catch (Throwable $e) {
                report($e);
                $cacheInvalidated = false;
            }
        }

        return ['outcome' => $outcome, 'cache_invalidated' => $cacheInvalidated];
    }

    /**
     * `POST /module-rollouts`, api.md §2.6.4. `enabled: true` encola de
     * inmediato (`RN-BO-27`, `RN-BO-78`); `enabled: false` crea la
     * `dual_authorization` y no encola ni escribe nada todavía
     * (`RN-BO-79`, `RN-BO-80`).
     *
     * @param  list<string>  $tenantPublicIds
     * @return array{type: 'module_rollout', tenants: int}|array{type: 'dual_authorization', authorization: DualAuthorization}
     */
    public function requestBulkRollout(
        string $moduleCode,
        bool $enabled,
        string $reason,
        bool $cascade,
        array $tenantPublicIds,
        PlatformAdmin $actor,
    ): array {
        $this->guardReasonPresent($reason);
        $this->guardModuleExists($moduleCode);

        if ($enabled) {
            RunModuleRollout::dispatch($moduleCode, true, $reason, $cascade, $tenantPublicIds, $actor->public_id);

            return ['type' => 'module_rollout', 'tenants' => count($tenantPublicIds)];
        }

        $payload = [
            'module_code' => $moduleCode,
            'enabled' => false,
            'reason' => $reason,
            'cascade' => $cascade,
            'tenant_public_ids' => $tenantPublicIds,
        ];
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        try {
            $authorization = DualAuthorization::create([
                'action' => DualAuthorizationAction::ModuloDescontratarMasivo,
                'payload' => $payload,
                'payload_fingerprint' => $fingerprint,
                'reason' => $reason,
                'requested_by' => $actor->id,
                'requested_at' => now(),
                'expires_at' => now()->addMinutes((int) config('backoffice.dual_authorization_ttl_minutes')),
                'status' => DualAuthorizationStatus::Pendiente,
            ]);
        } catch (QueryException) {
            throw ApiException::conflict('bo.dual_auth.already_resolved');
        }

        $this->recorder->record(
            action: AdminActionLogAction::AutorizacionSolicitada,
            subjectType: 'dual_authorization',
            subjectId: $authorization->id,
            subjectPublicId: $authorization->public_id,
            reason: $reason,
        );

        return ['type' => 'dual_authorization', 'authorization' => $authorization];
    }

    /**
     * `RN-BO-80`. Invocado desde `DualAuthorizationService::execute()`
     * dentro de la transacción que esa clase ya abre: al aprobar, se
     * valida el *payload* congelado (los centros existen, el módulo
     * existe, no es esencial) y se **encola** el lote — `executed_at`
     * marca este instante, no el final de las N operaciones.
     */
    public function executeApprovedBulkDecontract(DualAuthorization $authorization): void
    {
        $payload = $authorization->payload;
        $moduleCode = $payload['module_code'] ?? null;

        if (! is_string($moduleCode)) {
            throw new RuntimeException(
                'El módulo del lote congelado ya no existe en el catálogo declarado (RN-BO-20).'
            );
        }

        $descriptor = $this->catalog->find($moduleCode);

        if ($descriptor === null) {
            throw new RuntimeException(
                'El módulo del lote congelado ya no existe en el catálogo declarado (RN-BO-20).'
            );
        }

        if ($descriptor->essential) {
            throw new RuntimeException(
                'El módulo del lote congelado es esencial y no se puede descontratar (RN-BO-20, RN-BO-65).'
            );
        }

        $tenantPublicIds = $payload['tenant_public_ids'] ?? [];
        $existing = Tenant::withTrashed()->whereIn('public_id', $tenantPublicIds)->count();

        if ($existing === 0) {
            throw new RuntimeException(
                'Ninguno de los centros del lote congelado existe ya (RN-BO-20).'
            );
        }

        // issue #224 (Alta, `/codex:review`): este método corre dentro de
        // la transacción de `DualAuthorizationService::execute()`, y las
        // tres conexiones de cola tienen `after_commit => false`
        // (`config/queue.php`) — un `dispatch()` directo aquí encola de
        // inmediato, antes del `COMMIT`. Si el `forceFill(...)->save()` o
        // el `record()` que `execute()` ejecuta a continuación (todavía
        // dentro de la misma transacción) lanzan, la transacción entera
        // se revierte y la autorización queda `Fallida` — pero el lote ya
        // encolado se ejecutaría igual. Mismo patrón que ya usa
        // `TenantLifecycleService::executeApprovedDeletion()` para
        // `RevokeTenantSessions`: encolar solo tras el `COMMIT` real.
        DB::connection('pgsql_platform')->afterCommit(function () use ($moduleCode, $payload, $tenantPublicIds, $authorization): void {
            RunModuleRollout::dispatch(
                $moduleCode,
                false,
                (string) $payload['reason'],
                (bool) $payload['cascade'],
                $tenantPublicIds,
                // `approver` está garantizado no nulo aquí: este método
                // sólo se invoca desde `DualAuthorizationService::execute()`,
                // llamado después de que `approve()` fije `approved_by`
                // (RN-BO-80).
                $authorization->approver->public_id,
                $authorization->public_id,
            );
        });
    }

    private function guardModuleExists(string $moduleCode): void
    {
        if ($this->catalog->find($moduleCode) === null) {
            throw ApiException::notFound();
        }
    }

    /**
     * `RN-BO-24`: obligatorio y no vacío. Código propio
     * `bo.module.reason_required` (api.md §5) en vez del genérico que
     * produciría una regla `required` de `FormRequest`.
     */
    private function guardReasonPresent(?string $reason): void
    {
        if ($reason === null || trim($reason) === '') {
            throw BoValidationError::forField('reason', 'bo.module.reason_required');
        }
    }

    private function guardTenantWritable(Tenant $tenant): void
    {
        if (! in_array($tenant->status, self::WRITABLE_STATUSES, true)) {
            throw ApiException::conflict('bo.module.tenant_state_invalid', ['tenant_status' => $tenant->status->value]);
        }
    }

    private function recordAdminActionLogs(Tenant $tenant, ModuleChange $change, ModuleContractingOutcome $outcome): void
    {
        foreach ($outcome->applied as $item) {
            $this->recorder->record(
                action: $item['enabled'] ? AdminActionLogAction::ModuloContratado : AdminActionLogAction::ModuloDescontratado,
                subjectType: 'module_subscription',
                subjectPublicId: $item['module_code'],
                affectedTenantId: $tenant->id,
                reason: $change->reason,
                context: $item['cascaded'] ? ['cascaded_from' => $change->moduleCode] : null,
            );
        }
    }
}
