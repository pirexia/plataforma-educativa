<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\ModuleSubscription;
use App\Models\User;
use App\Modules\Core\Domain\FeatureFlagDecisionEngine;
use App\Modules\Core\Domain\Models\FeatureFlag;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Support\FeatureFlags\FeatureFlagDecision;
use App\Support\FeatureFlags\FeatureFlagEvaluator;
use App\Support\FeatureFlags\FeatureFlagExplainer;
use App\Support\FeatureFlags\FeatureFlagMatchedBy;
use App\Support\FeatureFlags\FeatureFlagRolloutUnit;
use App\Support\FeatureFlags\FeatureFlagRuleInput;
use App\Support\FeatureFlags\FeatureFlagRuleSet;
use App\Support\FeatureFlags\FeatureFlagScopeType;
use App\Support\FeatureFlags\FeatureFlagSubject;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * funcional.md §5.11.8. Precedente literal y verificado:
 * `EloquentModuleAvailability`. **Implementación única de las dos
 * interfaces** (`RN-BO-101`): `isEnabled()` (camino de petición, sujeto
 * ambiental, perezoso, caché compartida) y `explain()`/`explainAll()`/
 * `explainWith()` (backoffice, sujeto explícito, sin tenant activo,
 * siempre en fresco) delegan las dos en `FeatureFlagDecisionEngine`, la
 * misma función pura, para que el `matched_by` que un operador lee al
 * depurar sea siempre el motivo real (`CA-BO-170`).
 */
final class EloquentFeatureFlagEvaluator implements FeatureFlagEvaluator, FeatureFlagExplainer
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleAvailability $moduleAvailability,
        private readonly ModuleCatalog $moduleCatalog,
        private readonly FeatureFlagCatalogCache $catalogCache,
    ) {}

    public function isEnabled(string $flagKey): bool
    {
        if (! $this->tenantContext->hasTenant()) {
            return false;
        }

        $snapshot = $this->loadCachedCatalog()[$flagKey] ?? null;

        if ($snapshot === null) {
            return false;
        }

        $moduleAvailable = $snapshot['module_code'] !== null
            ? $this->moduleAvailability->isEnabled($snapshot['module_code'])
            : null;

        return FeatureFlagDecisionEngine::decide(
            $this->ruleSetFromSnapshot($flagKey, $snapshot),
            $this->currentSubject(),
            retired: $snapshot['retired'],
            forcedOff: $snapshot['status'] === 'forced_off',
            moduleAvailable: $moduleAvailable,
        )->enabled;
    }

    public function explain(FeatureFlagSubject $subject, string $flagKey): FeatureFlagDecision
    {
        $flag = FeatureFlag::query()->where('key', $flagKey)->with('rules')->first();

        if ($flag === null) {
            return FeatureFlagDecision::of(false, FeatureFlagMatchedBy::None);
        }

        return $this->decideForFlag($flag, $subject);
    }

    public function explainAll(FeatureFlagSubject $subject): array
    {
        $flags = FeatureFlag::query()->with('rules')->orderBy('key')->get();
        $decisions = [];

        foreach ($flags as $flag) {
            $decisions[$flag->key] = $this->decideForFlag($flag, $subject);
        }

        return $decisions;
    }

    public function explainWith(FeatureFlagRuleSet $proposed, FeatureFlagSubject $subject): FeatureFlagDecision
    {
        // La vista previa evalúa el conjunto PROPUESTO, no el escrito
        // (RN-BO-68): no hay `retired_at` ni `forced_off` que consultar
        // porque, por construcción, no hay fila que leer para eso más
        // allá de la que ya resolvió quien construyó `$proposed`.
        $moduleAvailable = $proposed->moduleCode !== null
            ? $this->moduleAvailableFor($subject->tenantId, $proposed->moduleCode)
            : null;

        return FeatureFlagDecisionEngine::decide($proposed, $subject, retired: false, forcedOff: false, moduleAvailable: $moduleAvailable);
    }

    private function decideForFlag(FeatureFlag $flag, FeatureFlagSubject $subject): FeatureFlagDecision
    {
        $moduleAvailable = $flag->module_code !== null
            ? $this->moduleAvailableFor($subject->tenantId, $flag->module_code)
            : null;

        return FeatureFlagDecisionEngine::decide(
            $this->ruleSetFromModel($flag),
            $subject,
            retired: $flag->retired_at !== null,
            forcedOff: $flag->status === 'forced_off',
            moduleAvailable: $moduleAvailable,
        );
    }

    /**
     * `RN-BO-102`, comprobado **para el tenant del sujeto explícito**, no
     * del contexto ambiental — `explain()`/`explainAll()`/`explainWith()`
     * se llaman desde el backoffice, sin tenant activo (`ADR-046 §6.4`),
     * así que `ModuleAvailability::isEnabled()` (que lee `TenantContext`)
     * no sirve aquí por la misma razón por la que `FeatureFlagEvaluator`
     * no sirve para evaluar otro centro (§5.11.8.2). Réplica deliberada y
     * mínima de la regla de `EloquentModuleAvailability::isEnabled()`
     * —esencial siempre disponible, si no hay suscripción activa es
     * `false` — `ModuleSubscription` es `TenantModel` (`TenantScope`
     * exige tenant activo, lance `TenantContextMissing` si no lo hay), así
     * que la lectura entra en el contexto del tenant del sujeto para esa
     * única consulta (`TenantContext::runFor()`) y lo restaura al salir.
     * Es sólo lectura, nunca escritura — la advertencia de `runAsPlatform()`
     * sobre "modo plataforma y tenant activo a la vez" es sobre escribir
     * con el `tenant_id` equivocado, que no aplica aquí.
     */
    private function moduleAvailableFor(int $tenantId, string $moduleCode): bool
    {
        if ($this->moduleCatalog->find($moduleCode)?->essential === true) {
            return true;
        }

        return $this->tenantContext->runFor(
            $tenantId,
            static fn (): bool => ModuleSubscription::query()
                ->select('id')
                ->where('module_code', $moduleCode)
                ->where('enabled', true)
                ->exists(),
        );
    }

    /**
     * `OPEN-BO-24`, decisión (b): una sola lectura de caché por petición
     * en vez de una por *flag* consultado (`funcional.md §5.11.9.2`).
     *
     * @return array<string, array{module_code: ?string, rollout_unit: string, status: string, retired: bool, rules: list<array<string, mixed>>}>
     */
    private function loadCachedCatalog(): array
    {
        $cached = $this->catalogCache->get();

        if ($cached !== null) {
            return $cached;
        }

        $snapshot = $this->buildCatalogSnapshot();
        $this->catalogCache->put($snapshot, (int) config('core.feature_flags.cache_ttl_seconds', 300));

        return $snapshot;
    }

    /**
     * @return array<string, array{module_code: ?string, rollout_unit: string, status: string, retired: bool, rules: list<array<string, mixed>>}>
     */
    private function buildCatalogSnapshot(): array
    {
        $snapshot = [];

        foreach (FeatureFlag::query()->with('rules')->get() as $flag) {
            $snapshot[$flag->key] = [
                'module_code' => $flag->module_code,
                'rollout_unit' => $flag->rollout_unit,
                'status' => $flag->status,
                'retired' => $flag->retired_at !== null,
                'rules' => $flag->rules->map(static fn ($rule): array => [
                    'scope_type' => $rule->scope_type->value,
                    'affected_tenant_id' => $rule->affected_tenant_id,
                    'role_code' => $rule->role_code,
                    'percentage' => $rule->percentage,
                    'enabled' => $rule->enabled,
                ])->all(),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array{module_code: ?string, rollout_unit: string, status: string, retired: bool, rules: list<array<string, mixed>>}  $snapshot
     */
    private function ruleSetFromSnapshot(string $flagKey, array $snapshot): FeatureFlagRuleSet
    {
        return new FeatureFlagRuleSet(
            flagKey: $flagKey,
            rolloutUnit: FeatureFlagRolloutUnit::from($snapshot['rollout_unit']),
            rules: array_map(
                static fn (array $rule): FeatureFlagRuleInput => new FeatureFlagRuleInput(
                    scopeType: FeatureFlagScopeType::from($rule['scope_type']),
                    enabled: $rule['enabled'],
                    tenantId: $rule['affected_tenant_id'],
                    roleCode: $rule['role_code'],
                    percentage: $rule['percentage'],
                ),
                $snapshot['rules'],
            ),
            moduleCode: $snapshot['module_code'],
        );
    }

    private function ruleSetFromModel(FeatureFlag $flag): FeatureFlagRuleSet
    {
        return new FeatureFlagRuleSet(
            flagKey: $flag->key,
            rolloutUnit: FeatureFlagRolloutUnit::from($flag->rollout_unit),
            rules: $flag->rules->map(static fn ($rule): FeatureFlagRuleInput => new FeatureFlagRuleInput(
                scopeType: $rule->scope_type,
                enabled: $rule->enabled,
                tenantId: $rule->affected_tenant_id,
                roleCode: $rule->role_code,
                percentage: $rule->percentage,
            ))->all(),
            moduleCode: $flag->module_code,
        );
    }

    private function currentSubject(): FeatureFlagSubject
    {
        $tenantId = $this->tenantContext->tenantId();
        $tenant = Tenant::query()->select(['id', 'public_id', 'early_adopter_since'])->find($tenantId);

        $user = Auth::guard('web')->user();

        return new FeatureFlagSubject(
            tenantId: $tenantId,
            tenantPublicId: $tenant === null ? '' : $tenant->public_id,
            userPublicId: $user instanceof User ? $user->public_id : null,
            roleCodes: $user instanceof User ? $this->roleCodesOf($user) : [],
            isEarlyAdopter: $tenant?->early_adopter_since !== null,
        );
    }

    /**
     * `RN-BO-107`: método con nombre, no un `pluck('code')` en línea —
     * mismo dato que `App\Support\Api\UserProfilePresenter` ya mapea para
     * `GET /me`. `roles`/`role_user` son de `REQ-CORE` (`REQ-CORE-004`),
     * no de `REQ-PERM` (§5.11.8.3): leerlos aquí no cruza ninguna
     * frontera de módulo.
     *
     * @return list<string>
     */
    private function roleCodesOf(User $user): array
    {
        return $user->roles->pluck('code')->all();
    }
}
