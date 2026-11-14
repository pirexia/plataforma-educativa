<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Core\Domain\FeatureFlagAdministration;
use App\Support\Api\ApiException;
use App\Support\FeatureFlags\FeatureFlagExplainer;
use App\Support\FeatureFlags\FeatureFlagRuleSet;
use App\Support\FeatureFlags\FeatureFlagSubject;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * `REQ-BO-005` puntos 1-2, sub-paso `1.6e`, funcional.md §5.11,
 * §7.7. Orquesta capacidades, reautenticación, doble autorización (aquí:
 * ninguna, `api.md §2.12`) y `admin_action_logs` — todo lo que es de
 * `REQ-BO`; delega la lectura y escritura de `feature_flags`/
 * `feature_flag_rules` en `FeatureFlagAdministration` (`REQ-CORE`,
 * `INV-007`) y la evaluación «para otro centro» en `FeatureFlagExplainer`
 * — mismo reparto que `ModuleSubscriptionsService`/`ModuleContracting`.
 */
final class FeatureFlagsService
{
    /**
     * `RN-BO-108`: tercera regla de la familia `tenant_state_invalid`, con
     * su propio vocabulario de estados — `en_alta` **sí** admite
     * designación (a diferencia de la escritura de módulos, `RN-BO-71`).
     */
    private const EARLY_ADOPTER_WRITABLE_STATUSES = [
        TenantStatus::EnAlta,
        TenantStatus::Activo,
        TenantStatus::Suspendido,
        TenantStatus::EnBaja,
    ];

    public function __construct(
        private readonly FeatureFlagAdministration $administration,
        private readonly FeatureFlagExplainer $explainer,
        private readonly AdminActionLogRecorder $recorder,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * `GET /feature-flags`, api.md §2.11.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $moduleCode, ?string $status, ?bool $retired, ?string $q, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeLectura,
            fn (): LengthAwarePaginator => $this->administration->paginate($moduleCode, $status, $retired, $q, $perPage, $page),
        );
    }

    /**
     * `GET /feature-flags/{key}`.
     *
     * @return array<string, mixed>
     */
    public function find(string $key): array
    {
        $flag = $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeLectura,
            fn (): ?array => $this->administration->find($key),
        );

        if ($flag === null) {
            throw ApiException::notFound();
        }

        return $flag;
    }

    /**
     * `POST /feature-flags/{key}/rules/preview`, `RN-BO-68`.
     *
     * @return array<string, mixed>
     */
    public function previewRules(string $key, FeatureFlagRuleSet $proposed): array
    {
        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeLectura,
            fn (): array => $this->administration->previewRules($key, $proposed),
        );
    }

    /**
     * `PUT /feature-flags/{key}/state`. La reautenticación (sólo cuando
     * el destino es `activo`) ya se comprobó antes de llegar aquí
     * (`api.md §2.12`).
     *
     * @return array<string, mixed>
     */
    public function setState(string $key, string $status, string $reason, PlatformAdmin $actor): array
    {
        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeEscritura,
            fn (): array => DB::connection('pgsql_platform')->transaction(function () use ($key, $status, $reason, $actor): array {
                $result = $this->administration->setState($key, $status, $reason, $actor->id);

                // RN-BO-43: alcance global — nunca afecta a un centro en
                // particular, `affected_tenant_id` queda nulo.
                $this->recorder->record(
                    action: AdminActionLogAction::FlagEstadoCambiado,
                    subjectType: 'feature_flag',
                    subjectPublicId: $result['public_id'],
                    reason: $reason,
                    changes: ['status' => [null, $status]],
                );

                return $result;
            }),
        );
    }

    /**
     * `PUT /feature-flags/{key}/rules`, `RN-BO-104`. Ya reautenticado
     * siempre (`api.md §2.12`).
     *
     * @return array<string, mixed>
     */
    public function replaceRules(string $key, FeatureFlagRuleSet $proposed, string $reason, PlatformAdmin $actor): array
    {
        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeEscritura,
            fn (): array => DB::connection('pgsql_platform')->transaction(function () use ($key, $proposed, $reason, $actor): array {
                $result = $this->administration->replaceRules($key, $proposed, $reason, $actor->id);

                $this->recorder->record(
                    action: AdminActionLogAction::FlagReglasCambiadas,
                    subjectType: 'feature_flag',
                    subjectPublicId: $result['public_id'],
                    affectedTenantId: $result['affected_tenant_id'],
                    reason: $reason,
                    context: ['exposed_tenants' => $result['impact']['exposed_tenants'] ?? null],
                );

                // Hallazgo Media de la revisión independiente (`doc-reviewer`):
                // `affected_tenant_id` es el bigint interno de `tenants.id`
                // (ADR-047 §4.2), necesario para admin_action_logs pero
                // prohibido en una respuesta HTTP (ADR-029) — no documentado
                // en api.md/OpenAPI porque nunca debió salir del servicio.
                unset($result['affected_tenant_id']);

                return $result;
            }),
        );
    }

    /**
     * `GET /tenants/{public_id}/feature-flags`, api.md §2.13. Sin sujeto
     * usuario: la pregunta es «qué ve este centro», no «qué ve esta
     * persona» (`RN-BO-41` — sin usuario, las reglas de rol no exponen a
     * nadie, coherente con no tener ninguno aquí).
     *
     * @return list<array<string, mixed>>
     */
    public function tenantFlags(Tenant $tenant): array
    {
        $subject = new FeatureFlagSubject(
            tenantId: $tenant->id,
            tenantPublicId: $tenant->public_id,
            isEarlyAdopter: $tenant->early_adopter_since !== null,
        );

        // Sólo la lectura de feature_flags/feature_flag_rules entra en el
        // bloque: `EloquentFeatureFlagEvaluator::decideForFlag()` resuelve
        // la disponibilidad de módulo con una consulta directa a
        // `module_subscriptions`, no con `ModuleAvailability`/`runFor()`
        // (que entraría en conflicto con `runAsPlatform()` activo —
        // docblock de `TenantContext::runAsPlatform()`).
        $decisions = $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeLectura,
            fn (): array => $this->explainer->explainAll($subject),
        );

        $result = [];

        foreach ($decisions as $key => $decision) {
            $result[] = [
                'key' => $key,
                'enabled' => $decision->enabled,
                'matched_by' => $decision->matchedBy->value,
            ];
        }

        return $result;
    }

    /**
     * `PUT /tenants/{public_id}/early-adopter`, `RN-BO-46`, `RN-BO-108`,
     * `RN-BO-109`. No toca ninguna tabla de *flags*: escribe
     * `tenants.early_adopter_since` directamente — `Tenant` es modelo
     * compartido (`App\Support\Tenancy`), no interno de ningún módulo.
     */
    public function setEarlyAdopter(Tenant $tenant, bool $designate, string $reason, PlatformAdmin $actor): Tenant
    {
        $this->guardTenantAdmitsEarlyAdopterDesignation($tenant);

        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeEscritura,
            fn (): Tenant => DB::connection('pgsql_platform')->transaction(function () use ($tenant, $designate, $reason): Tenant {
                $previous = $tenant->early_adopter_since;
                $tenant->early_adopter_since = $designate ? now() : null;
                $tenant->save();

                $this->recorder->record(
                    action: $designate ? AdminActionLogAction::TenantEarlyAdopterDesignado : AdminActionLogAction::TenantEarlyAdopterRetirado,
                    subjectType: 'tenant',
                    subjectId: $tenant->id,
                    subjectPublicId: $tenant->public_id,
                    affectedTenantId: $tenant->id,
                    reason: $reason,
                    changes: ['early_adopter_since' => [$previous?->toJSON(), $tenant->early_adopter_since?->toJSON()]],
                );

                return $tenant;
            }),
        );
    }

    private function guardTenantAdmitsEarlyAdopterDesignation(Tenant $tenant): void
    {
        if (! in_array($tenant->status, self::EARLY_ADOPTER_WRITABLE_STATUSES, true)) {
            throw ApiException::conflict('bo.flag.tenant_state_invalid', ['tenant_status' => $tenant->status->value]);
        }
    }
}
